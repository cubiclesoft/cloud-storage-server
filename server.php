<?php
	// Cloud Storage Server.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/css_functions.php";

	// Load configuration.
	$config = CSS_LoadConfig();

	if ($argc > 1)
	{
		// Service Manager PHP SDK.
		require_once $rootpath . "/support/servicemanager.php";

		$sm = new ServiceManager($rootpath . "/servicemanager");

		echo "Service manager:  " . $sm->GetServiceManagerRealpath() . "\n\n";

		$servicename = preg_replace('/[^a-z0-9]/', "-", $config["servicename"]);

		if ($argv[1] == "install")
		{
			// Install the service.
			$args = array();
			$options = array(
				"nixuser" => $config["serviceuser"],
				"nixgroup" => $config["serviceuser"]
			);

			$result = $sm->Install($servicename, __FILE__, $args, $options, true);
			if (!$result["success"])  CLI::DisplayError("Unable to install the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "start")
		{
			// Start the service.
			$result = $sm->Start($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to start the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "stop")
		{
			// Stop the service.
			$result = $sm->Stop($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to stop the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "uninstall")
		{
			// Uninstall the service.
			$result = $sm->Uninstall($servicename, true);
			if (!$result["success"])  CLI::DisplayError("Unable to uninstall the '" . $servicename . "' service.", $result);
		}
		else if ($argv[1] == "dumpconfig")
		{
			$result = $sm->GetConfig($servicename);
			if (!$result["success"])  CLI::DisplayError("Unable to retrieve the configuration for the '" . $servicename . "' service.", $result);

			echo "Service configuration:  " . $result["filename"] . "\n\n";

			echo "Current service configuration:\n\n";
			foreach ($result["options"] as $key => $val)  echo "  " . $key . " = " . $val . "\n";
		}
		else
		{
			echo "Command not recognized.  Run the service manager directly for anything other than 'install', 'start', 'stop', 'uninstall', and 'dumpconfig'.\n";
		}

		exit();
	}

	// Initialize server.
	if (!isset($config["quota"]))  CSS_DisplayError("Configuration is incomplete or missing.  Run 'install.php' first.");
	$config["quota"] = CSS_ConvertUserStrToBytes($config["quota"]);

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	$db = new CSDB_sqlite();

	try
	{
		$db->Connect("sqlite:" . $rootpath . "/data/main.db");
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
	require_once $rootpath . "/support/remotedapi_web_server.php";
	require_once $rootpath . "/support/websocket_server.php";

	$wsserver = new WebSocketServer();
	$webservers = array();
	$webserver = (RemotedAPIWebServer::IsRemoted($config["host"]) ? new RemotedAPIWebServer() : new WebServer());

	// Enable writing files to the system.
	$cachedir = sys_get_temp_dir();
	$cachedir = str_replace("\\", "/", $cachedir);
	if (substr($cachedir, -1) !== "/")  $cachedir .= "/";
	$cachedir .= "cloudstorage_" . microtime(true) . "/";
	@mkdir($cachedir, 0770, true);
	$webserver->SetCacheDir($cachedir);

	// Enable longer active client times.
	$webserver->SetDefaultClientTimeout(300);
	$webserver->SetMaxRequests(200);

	echo "Starting server...\n";
	$result = $webserver->Start($config["host"], $config["port"], ($config["host"] !== "[::1]" && $config["host"] !== "127.0.0.1" && isset($config["sslopts"]) ? $config["sslopts"] : false));
	if (!$result["success"])
	{
		var_dump($result);
		exit();
	}

	$webservers[] = $webserver;

	if ($config["addlocalhost"])
	{
		$webserver = new WebServer();

		// Enable writing files to the system.
		$cachedir = sys_get_temp_dir();
		$cachedir = str_replace("\\", "/", $cachedir);
		if (substr($cachedir, -1) !== "/")  $cachedir .= "/";
		$cachedir .= "cloudstorage_local_" . microtime(true) . "/";
		@mkdir($cachedir, 0770, true);
		$webserver->SetCacheDir($cachedir);

		// Enable longer active client times.
		$webserver->SetDefaultClientTimeout(300);
		$webserver->SetMaxRequests(200);

		echo "Starting localhost server...\n";
		$result = $webserver->Start(($config["host"]{0} === "[" ? "[::1]" : "127.0.0.1"), $config["port"] + 1);
		if (!$result["success"])
		{
			var_dump($result);
			exit();
		}

		$webservers[] = $webserver;
	}

	echo "Ready.\n";

	$stopfilename = __FILE__ . ".notify.stop";
	$reloadfilename = __FILE__ . ".notify.reload";
	$lastservicecheck = time();
	$running = true;

	do
	{
		// Implement the stream_select() call directly since multiple server instances are involved.
		$timeout = 3;
		$readfps = array();
		$writefps = array();
		$exceptfps = NULL;

		foreach ($webservers as $servernum => $webserver)  $webserver->UpdateStreamsAndTimeout($servernum . "_", $timeout, $readfps, $writefps);
		foreach ($serverexts as $ext)  $ext->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);
		$wsserver->UpdateStreamsAndTimeout("", $timeout, $readfps, $writefps);

		$result = @stream_select($readfps, $writefps, $exceptfps, $timeout);
		if ($result === false)
		{
			if ($webservers[0] instanceof RemotedAPIWebServer)
			{
				sleep(5);
			}
			else
			{
				break;
			}
		}

		// Web server.
		foreach ($webservers as $servernum => $webserver)
		{
			$result = $webserver->Wait(0);

			// Handle active clients.
			foreach ($result["clients"] as $id => $client)
			{
				if ($client->appdata === false)
				{
					echo "Server " . $servernum . ", Client ID " . $id . " connected.\n";

					$client->appdata = array("validapikey" => false, "userrow" => false, "guestrow" => false, "currext" => false, "pathparts" => false);
				}

				// Check for a valid API key.
				if (!$client->appdata["validapikey"] && (isset($client->headers["X-Apikey"]) || isset($client->requestvars["apikey"])))
				{
					$apikey = explode("-", (isset($client->headers["X-Apikey"]) ? $client->headers["X-Apikey"] : $client->requestvars["apikey"]));
					if (count($apikey) == 2)
					{
						$result2 = $userhelper->GetUserByID($apikey[1], $apikey[0]);
						if ($result2["success"])
						{
							echo "Valid user API key used.\n";

							$client->appdata["validapikey"] = true;
							$client->appdata["userrow"] = $result2["info"];
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

								$client->appdata["validapikey"] = true;
								$client->appdata["userrow"] = $result3["info"];
								$client->appdata["guestrow"] = $result2["info"];
							}
						}
					}
				}

				if ($client->appdata["validapikey"])
				{
					if ($client->appdata["currext"] === false)
					{
						$url = HTTP::ExtractURL($client->url);
						$path = explode("/", $url["path"]);
						$client->appdata["pathparts"] = $path;
						if (count($path) > 1 && isset($serverexts[$path[1]]) && isset($client->appdata["userrow"]->serverexts[$path[1]]) && ($client->appdata["guestrow"] === false || isset($client->appdata["guestrow"]->serverexts[$path[1]])))  $client->appdata["currext"] = $path[1];
					}

					// Guaranteed to have at least the request line and headers if the request is incomplete.
					if (!$client->requestcomplete && $client->appdata["currext"] !== false)
					{
						// Let server extensions raise the default limit of ~1MB of transfer per request (not per connection) if they want to.
						// Extensions should only increase the limit for file uploads and should avoid decreasing the limit.
						$serverexts[$client->appdata["currext"]]->HTTPPreProcessAPI($client->request["method"], $client->appdata["pathparts"], $client, $client->appdata["userrow"], $client->appdata["guestrow"]);
					}
				}

				// Wait until the request is complete before fully processing inputs.
				if ($client->requestcomplete)
				{
					if (!$client->appdata["validapikey"])
					{
						echo "Missing API key.\n";

						$client->SetResponseCode(403);
						$client->SetResponseContentType("application/json");
						$client->AddResponseContent(json_encode(array("success" => false, "error" => "Invalid or missing 'apikey'.", "errorcode" => "invalid_missing_apikey")));
						$client->FinalizeResponse();
					}
					else if ($client->appdata["currext"] === false)
					{
						echo "Unknown or invalid extension.\n";

						$client->SetResponseCode(403);
						$client->SetResponseContentType("application/json");
						$client->AddResponseContent(json_encode(array("success" => false, "error" => "Unknown or invalid server extension requested.", "errorcode" => "bad_server_extension")));
						$client->FinalizeResponse();
					}
					else if ($client->mode === "init_response")
					{
						// Handle WebSocket upgrade requests.
						$id2 = $wsserver->ProcessWebServerClientUpgrade($webserver, $client);
						if ($id2 !== false)
						{
							echo "Server " . $servernum . ", Client ID " . $id . " upgraded to WebSocket.  WebSocket client ID is " . $id2 . ".\n";
						}
						else
						{
							echo "Sending API response for:  " . $client->request["method"] . " " . implode("/", $client->appdata["pathparts"]) . "\n";

							// Check transfer limits.
							$userrow = $client->appdata["userrow"];
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
										$result2 = $serverexts[$client->appdata["currext"]]->ProcessAPI($client->request["method"], $client->appdata["pathparts"], $client, $userrow, $client->appdata["guestrow"], $data);
										if ($result2 === false)
										{
											$webserver->RemoveClient($id);

											echo "Server " . $servernum . ", Client ID " . $id . " removed.\n";
										}
										else if (!is_array($result2))
										{
											$client->appdata["data"] = $data;
										}
									}
								}
							}

							if ($result2 !== false)
							{
								// Prevent proxies from doing bad things.
								$client->SetResponseNoCache();

								if (is_array($result2))
								{
									if (!$result2["success"])  $client->SetResponseCode(400);

									// Send the response.
									$client->SetResponseContentType("application/json");
									$client->AddResponseContent(json_encode($result2));
									$client->FinalizeResponse();
								}

								if ($client->responsefinalized)  $client->appdata["currext"] = false;
							}
						}
					}
					else
					{
						// Continue where the API left off.
						$result2 = $serverexts[$client->appdata["currext"]]->ProcessAPI($client->request["method"], $client->appdata["pathparts"], $client, $client->appdata["userrow"], $client->appdata["guestrow"], $client->appdata["data"]);
						if ($result2 === false)
						{
							$webserver->RemoveClient($id);

							echo "Server " . $servernum . ", Client ID " . $id . " removed.\n";
						}
						else
						{
							if ($client->responsefinalized)  $client->appdata["currext"] = false;
						}
					}
				}
			}

			// Do something with removed clients.
			foreach ($result["removed"] as $id => $result2)
			{
				if ($result2["client"]->appdata !== false)
				{
					echo "Server " . $servernum . ", Client ID " . $id . " disconnected.\n";

//					echo "Client ID " . $id . " disconnected.  Reason:\n";
//					var_dump($result2["result"]);
//					echo "\n";
				}
			}
		}

		// WebSocket server.
		$result = $wsserver->Wait(0);

		// Handle active clients.
		foreach ($result["clients"] as $id => $client)
		{
			// Read the input as a normal API request.
			$ws = $client->websocket;

			$result2 = $ws->Read();
			while ($result2["success"] && $result2["data"] !== false)
			{
				echo "Sending API response via WebSocket.\n";

				// Attempt to normalize the input.
				$data = @json_decode($result2["data"]["payload"], true);

				// Process the request.
				if (!is_array($data))  $result3 = array("success" => false, "error" => "Data sent is not an array/object or was not able to be decoded.", "errorcode" => "invalid_data");
				else if (!isset($data["api_method"]) || !is_string($data["api_method"]))  $result3 = array("success" => false, "error" => "The 'api_method' is missing or invalid.", "errorcode" => "missing_invalid_api_method");
				else if (!isset($data["api_path"]) || !is_string($data["api_path"]))  $result3 = array("success" => false, "error" => "The 'api_path' is missing or invalid.", "errorcode" => "missing_invalid_api_path");
				else if (!isset($data["api_sequence"]) || !is_int($data["api_sequence"]))  $result3 = array("success" => false, "error" => "The 'api_sequence' is missing or invalid.", "errorcode" => "missing_invalid_api_sequence");
				else
				{
					$data["api_method"] = strtoupper($data["api_method"]);

					$path = explode("/", str_replace("\\", "/", $data["api_path"]));
					$client->appdata["pathparts"] = $path;
					if (count($path) > 1 && isset($serverexts[$path[1]]) && isset($client->appdata["userrow"]->serverexts[$path[1]]) && ($client->appdata["guestrow"] === false || isset($client->appdata["guestrow"]->serverexts[$path[1]])))  $client->appdata["currext"] = $path[1];

					echo "Sending API response for:  " . $data["api_method"] . " " . implode("/", $client->appdata["pathparts"]) . "\n";

					// Check transfer limits.
					$userrow = $client->appdata["userrow"];
					$received = $client->websocket->GetRawRecvSize();

					$options = array();
					if ($userrow->transferstart < time() - 86400)
					{
						$options["transferstart"] = time();
						$userrow->transferbytes = 0;
					}
					$userrow->transferbytes += $received;
					$options["transferbytes"] = $userrow->transferbytes;

					$result3 = $userhelper->UpdateUser($userrow->id, $options);
					if ($result3["success"])
					{
						// Check transfer limits.
						if ($userrow->transferlimit > -1 && $userrow->transferlimit < $userrow->transferbytes)  $result3 = array("success" => false, "error" => "Daily transfer limit exceeded.  Try again tomorrow.", "errorcode" => "transfer_limit_exceeded");
						else
						{
							// Process the request.
							$result3 = $serverexts[$client->appdata["currext"]]->ProcessAPI($data["api_method"], $client->appdata["pathparts"], $client, $userrow, $client->appdata["guestrow"], $data);
							if ($result3 === false || !is_array($result3))  $wsserver->RemoveClient($id);
							else  $result3["api_sequence"] = $data["api_sequence"];
						}
					}
				}

				// Send the response.
				$result2 = $ws->Write(json_encode($result3), $result2["data"]["opcode"]);

				$result2 = $ws->Read();
			}
		}

		foreach ($result["removed"] as $id => $result2)
		{
			if ($result2["client"]->appdata !== false)
			{
				echo "WebSocket client ID " . $id . " disconnected.\n";

//				echo "WebSocket client ID " . $id . " disconnected.  Reason:\n";
//				var_dump($result2["result"]);
//				echo "\n";
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