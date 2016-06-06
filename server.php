<?php
	// Cloud Storage Server.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/css_functions.php";

	$config = CSS_LoadConfig();

	if (!isset($config["quota"]))  CSS_DisplayError("Configuration is incomplete or missing.  Run 'install.php' first.");
	$config["quota"] = CSS_ConvertUserStrToBytes($config["quota"]);

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	$db = new CSDB_sqlite();

	try
	{
		$db->Connect("sqlite:" . $rootpath . "/main.db");
	}
	catch (Exception $e)
	{
		CSS_DisplayError("Unable to connect to SQLite database.  " . $e->getMessage());
	}

	if (!$db->TableExists("users"))  CSS_DisplayError("Database table is missing.  Run 'install.php' first.");

	require_once $rootpath . "/support/event_manager.php";

	$em = new EventManager();

	$userhelper = new CSS_UserHelper();
	$userhelper->Init($config, $db, $em);

	// Load all server extensions.
	$serverexts = CSS_LoadServerExtensions();

	// Let each server extension register event handler callbacks and initialize.
	foreach ($serverexts as $serverext)
	{
		$serverext->RegisterHandlers($em);
		$serverext->InitServer();
	}

	require_once $rootpath . "/support/web_server.php";
	require_once $rootpath . "/support/websocket_server.php";

	$webserver = new WebServer();
	$wsserver = new WebSocketServer();

	// Enable writing files to the system.
	$cachedir = sys_get_temp_dir();
	$cachedir = str_replace("\\", "/", $cachedir);
	if (substr($cachedir, -1) !== "/")  $cachedir .= "/";
	$cachedir .= "cloudstorage/";
	@mkdir($cachedir, 0770, true);
	$webserver->SetCacheDir($cachedir);

	// Enable longer active client times.
	$webserver->SetDefaultClientTimeout(300);
	$webserver->SetMaxRequests(200);

	echo "Starting server...\n";
	$result = $webserver->Start($config["host"], $config["port"], (isset($config["sslopts"]) ? $config["sslopts"] : false));
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	echo "Ready.\n";

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	$tracker = array();
	$tracker2 = array();

	do
	{
		// Implement the stream_select() call directly since multiple server instances are involved.
// NOTE:  WebSocket server support is not actually implemented in this release.  There are packet size issues to deal with.
		$timeout = 3;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;
		$webserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);
		$wsserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);
		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)  break;

		// Web server.
		$result = $webserver->Wait(0);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			if (!isset($tracker[$id]))
			{
				echo "Client ID " . $id . " connected.\n";

				$tracker[$id] = array("validapikey" => false, "userrow" => false, "guestrow" => false, "currext" => false, "pathparts" => false);
			}

			// Check for a valid API key.
			if (!$tracker[$id]["validapikey"] && (isset($client->headers["X-Apikey"]) || isset($client->requestvars["apikey"])))
			{
				$apikey = explode("-", (isset($client->headers["X-Apikey"]) ? $client->headers["X-Apikey"] : $client->requestvars["apikey"]));
				if (count($apikey) == 2)
				{
					$result2 = $userhelper->GetUserByID($apikey[1], $apikey[0]);
					if ($result2["success"])
					{
						echo "Valid user API key used.\n";

						$tracker[$id]["validapikey"] = true;
						$tracker[$id]["userrow"] = $result2["info"];
					}
				}
				else if (count($apikey) == 3)
				{
					$result2 = $userhelper->GetGuestByID($apikey[2], $apikey[1], $apikey[0]);
					if ($result2["success"])
					{
						$result3 = $userhelper->GetUserByID($apikey[1]);
						if ($result3["success"])
						{
							echo "Valid guest API key used.\n";

							$tracker[$id]["validapikey"] = true;
							$tracker[$id]["userrow"] = $result3["info"];
							$tracker[$id]["guestrow"] = $result2["info"];
						}
					}
				}
			}

			if ($tracker[$id]["validapikey"])
			{
				if ($tracker[$id]["currext"] === false)
				{
					$url = HTTP::ExtractURL($client->url);
					$path = explode("/", $url["path"]);
					$tracker[$id]["pathparts"] = $path;
					if (count($path) > 1 && isset($serverexts[$path[1]]) && isset($tracker[$id]["userrow"]->serverexts[$path[1]]) && ($tracker[$id]["guestrow"] === false || isset($tracker[$id]["guestrow"]->serverexts[$path[1]])))  $tracker[$id]["currext"] = $path[1];
				}

				// Guaranteed to have at least the request line and headers if the request is incomplete.
				if (!$client->requestcomplete && $tracker[$id]["currext"] !== false)
				{
					// Let server extensions raise the default limit of ~1MB of transfer per request (not per connection) if they want to.
					// Extensions should only increase the limit for file uploads and should avoid decreasing the limit.
					$serverexts[$tracker[$id]["currext"]]->HTTPPreProcessAPI($tracker[$id]["pathparts"], $client, $tracker[$id]["userrow"], $tracker[$id]["guestrow"]);
				}
			}

			// Wait until the request is complete before fully processing inputs.
			if ($client->requestcomplete)
			{
				if (!$tracker[$id]["validapikey"])
				{
					echo "Missing API key.\n";

					$client->SetResponseCode(403);
					$client->SetResponseContentType("application/json");
					$client->AddResponseContent(json_encode(array("success" => false, "error" => "Invalid or missing 'apikey'.", "errorcode" => "invalid_missing_apikey")));
					$client->FinalizeResponse();
				}
				else if ($tracker[$id]["currext"] === false)
				{
					echo "Unknown or invalid extension.\n";

					$client->SetResponseCode(403);
					$client->SetResponseContentType("application/json");
					$client->AddResponseContent(json_encode(array("success" => false, "error" => "Unknown or invalid server extension requested.", "errorcode" => "bad_server_extension")));
					$client->FinalizeResponse();
				}
				else if ($client->mode === "init_response")
				{
//					// Handle WebSocket upgrade requests.
//					$id2 = $wsserver->ProcessWebServerClientUpgrade($webserver, $client);
//					if ($id2 !== false)
//					{
//						echo "Client ID " . $id . " upgraded to WebSocket.  WebSocket client ID is " . $id2 . ".\n";
//
//						$tracker2[$id2] = $tracker[$id];
//
//						unset($tracker[$id]);
//					}
//					else
//					{
						echo "Sending API response for:  " . $client->request["method"] . " " . implode("/", $tracker[$id]["pathparts"]) . "\n";

						// Check transfer limits.
						$userrow = $tracker[$id]["userrow"];
						$received = $client->httpstate["result"]["rawrecvsize"];

						$options = array();
						if ($userrow->transferstart < time() - 86400)
						{
							$options["transferstart"] = time();
							$userrow->transferbytes = 0;
						}
						$userrow->transferbytes += $received;
						$options["transferbytes"] = $userrow->transferbytes;

						$result2 = $userhelper->UpdateUser($userrow->id, $options);
						if ($result2["success"])
						{
							// Check transfer limits.
							if ($userrow->transferlimit > -1 && $userrow->transferlimit < $userrow->transferbytes)  $result2 = array("success" => false, "error" => "Daily transfer limit exceeded.  Try again tomorrow.", "errorcode" => "transfer_limit_exceeded");
							else
							{
								// Attempt to normalize input.
								if ($client->contenthandled)  $data = $client->requestvars;
								else if (!is_object($client->readdata))  $data = @json_decode($client->readdata, true);
								else
								{
									$client->readdata->Open();
									$data = @json_decode($client->readdata->Read(1000000), true);
								}

								// Process the request.
								if (!is_array($data))  $result2 = array("success" => false, "error" => "Data sent is not an array/object or was not able to be decoded.", "errorcode" => "invalid_data");
								else
								{
									$result2 = $serverexts[$tracker[$id]["currext"]]->ProcessAPI($tracker[$id]["pathparts"], $client, $userrow, $tracker[$id]["guestrow"], $data);
									if ($result2 === false)  $webserver->RemoveClient($id);
									else if (!is_array($result2))  $tracker[$id]["data"] = $data;
								}
							}
						}

						if ($result2 !== false)
						{
							// Prevent proxies from doing bad things.
							$client->AddResponseHeader("Expires", "Tue, 03 Jul 2001 06:00:00 GMT", true);
							$client->AddResponseHeader("Last-Modified", gmdate("D, d M Y H:i:s T"), true);
							$client->AddResponseHeader("Cache-Control", "max-age=0, no-cache, must-revalidate, proxy-revalidate", true);

							if (is_array($result2))
							{
								if (!$result2["success"])  $client->SetResponseCode(400);

								// Send the response.
								$client->SetResponseContentType("application/json");
								$client->AddResponseContent(json_encode($result2));
								$client->FinalizeResponse();
							}

							if ($client->responsefinalized)  $tracker[$id]["currext"] = false;
						}
//					}
				}
				else
				{
					// Continue where the API left off.
					$result2 = $serverexts[$tracker[$id]["currext"]]->ProcessAPI($tracker[$id]["pathparts"], $client, $tracker[$id]["userrow"], $tracker[$id]["guestrow"], $tracker[$id]["data"]);
					if ($result2 === false)  $webserver->RemoveClient($id);

					if ($client->responsefinalized)  $tracker[$id]["currext"] = false;
				}
			}
		}

		// Do something with removed clients.
		foreach ($result["removed"] as $id => $result2)
		{
			if (isset($tracker[$id]))
			{
				echo "Client ID " . $id . " disconnected.\n";

//				echo "Client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";

				unset($tracker[$id]);
			}
		}

		// Check the status of the two service file options for correct Service Manager integration.
		if ($lastservicecheck <= time() - 3)
		{
			if (file_exists($stopfilename))
			{
				// Initialize termination.
				echo "Stop requested.\n";

				$running = false;
			}
			else if (file_exists($reloadfilename))
			{
				// Reload configuration and then remove reload file.
				echo "Reload config requested.  Exiting.\n";

				$running = false;
			}

			$lastservicecheck = time();
		}
	} while ($running);
?>