<?php
	// Remoted API WebServer class extension.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	if (!class_exists("WebServer", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/web_server.php";

	// Depends on the CubicleSoft WebServer, WebSocket, and WebRoute classes.
	class RemotedAPIWebServer extends WebServer
	{
		private $rws, $rhost, $rwr, $rwrclients, $rwrnextclientid;

		public function __construct()
		{
			parent::__construct();

			$this->rws = false;
			$this->rhost = false;
			$this->rwr = false;
			$this->rwrclients = array();
			$this->rwrnextclientid = 1;
		}

		// Helper function to decide which class to use to handle the server.
		public static function IsRemoted($host)
		{
			return (strtolower(substr($host, 0, 6)) === "rws://" || strtolower(substr($host, 0, 7)) === "rwss://");
		}

		// Reconnects to the WebSocket.
		// This is a blocking call.  In theory, that shouldn't cause too many problems.
		private function ReconnectRemotedAPIWebSocket()
		{
			if (!class_exists("HTTP", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/http.php";
			if (!class_exists("WebRoute", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/webroute.php";

			$url = $this->rhost;
			if ($url["scheme"] !== "rws" && $url["scheme"] !== "rwss")  return array("success" => false, "error" => HTTP::HTTPTranslate("Invalid Remoted API URL scheme."), "errorcode" => "invalid_scheme");
			if ($url["loginusername"] === "")  return array("success" => false, "error" => HTTP::HTTPTranslate("Remoted API URL is missing server key."), "errorcode" => "missing_server_key");

			$options = array("headers" => array());
			$options["headers"]["X-Remoted-APIKey"] = $url["loginusername"];

			$url["scheme"] = ($url["scheme"] === "rws" ? "ws" : "wss");
			unset($url["loginusername"]);
			unset($url["login"]);

			$url2 = "http://" . $url["host"];

			$url = HTTP::CondenseURL($url);

			return $this->rws->Connect($url, $url2, "auto", $options);
		}

		// Overrides the default behavior to start a server on a given host and port.
		// $host expected URL format:  rws://serverapikey@host/webroutepath
		// Where 'serverapikey' is the Remoted API Server server API key and 'webroutepath' is the unique path to connect under.
		public function Start($host, $port, $sslopts = false)
		{
			$this->Stop();

			if (!class_exists("WebSocket", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/websocket.php";
			if (!class_exists("WebRoute", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/webroute.php";

			$this->rws = new WebSocket();
			$this->rhost = HTTP::ExtractURL($host);

			$this->rwr = new WebRoute();

			return $this->ReconnectRemotedAPIWebSocket();
		}

		public function Stop()
		{
			parent::Stop();

			if ($this->rws !== false)  $this->rws->Disconnect();

			foreach ($this->rwrclients as $id => $client)
			{
				if ($client["state"]->webstate !== false && $client["state"]->webstate["httpstate"] !== false)
				{
					HTTP::ForceClose($client["state"]->webstate["httpstate"]);
				}
			}

			$this->rwrclients = array();
		}

		// Adds the WebSocket server stream and WebRoute clients.
		public function UpdateStreamsAndTimeout($prefix, &$timeout, &$readfps, &$writefps)
		{
			if ($this->rws !== false)
			{
				if ($this->rws->GetStream() === false)  $this->ReconnectRemotedAPIWebSocket();

				if ($this->rws->GetStream() === false)  $timeout = ($timeout === false || $timeout > 1 ? 1 : 0);
				else
				{
					$readfps[$prefix . "rws_http_s"] = $this->rws->GetStream();

					if ($this->rws->NeedsWrite())  $writefps[$prefix . "rws_http_s"] = $this->rws->GetStream();
				}
			}

			foreach ($this->rwrclients as $id => $client)
			{
				if ($client["state"]->webstate !== false && $client["state"]->webstate["httpstate"] !== false)
				{
					if (HTTP::WantRead($client["state"]->webstate["httpstate"]))  $readfps[$prefix . "rws_http_c_" . $id] = $client["state"]->webstate["httpstate"]["fp"];
					if (HTTP::WantWrite($client["state"]->webstate["httpstate"]))  $writefps[$prefix . "rws_http_c_" . $id] = $client["state"]->webstate["httpstate"]["fp"];
				}
			}

			parent::UpdateStreamsAndTimeout($prefix, $timeout, $readfps, $writefps);
		}

		protected function HandleNewConnections(&$readfps, &$writefps)
		{
			if (isset($readfps["rws_http_s"]) || isset($writefps["rws_http_s"]))
			{
				$result = $this->rws->ProcessQueuesAndTimeoutState(isset($readfps["rws_http_s"]), isset($writefps["rws_http_s"]));
				if ($result["success"])
				{
					// Initiate a new async WebRoute for each incoming connection request.
					$result = $this->rws->Read();
					while ($result["success"] && $result["data"] !== false)
					{
						$data = json_decode($result["data"]["payload"], true);

						if (isset($data["ipaddr"]) && isset($data["id"]) && isset($data["timeout"]))
						{
							$url = $this->rhost;

							$options = array(
								"async" => true,
								"headers" => array()
							);

							$options["headers"]["X-Remoted-APIKey"] = $url["loginusername"];

							$url["scheme"] = ($url["scheme"] === "rws" ? "wr" : "wrs");
							unset($url["loginusername"]);
							unset($url["login"]);

							$data["url"] = HTTP::CondenseURL($url);
							$data["retries"] = 3;
							$data["options"] = $options;

							// Due to the async setting, this will only initiate the connection.  No data is actually sent/received at this point.
							$result = $this->rwr->Connect($data["url"], $data["id"], $data["timeout"], "auto", $data["options"]);
							if ($result["success"])
							{
								$result["data"] = $data;

								$this->rwrclients[$this->rwrnextclientid] = $result;

								$this->rwrnextclientid++;
							}
						}

						$result = $this->rws->Read();
					}
				}

				// WebSocket was disconnected due to a socket error.
				if (!$result["success"])  $this->rws->Disconnect();

				unset($readfps["rws_http_s"]);
				unset($writefps["rws_http_s"]);
			}

			// Handle WebRoute clients.
			foreach ($this->rwrclients as $id => $client)
			{
				$result = $this->rwr->ProcessState($client["state"]);
				if ($result["success"])
				{
					// WebRoute successfully established.  Convert it into a WebServer client.
					// At this point, the underlying WebServer class takes over.
					$client2 = $this->InitNewClient();
					$client2->fp = $result["fp"];
					$client2->ipaddr = $client["data"]["ipaddr"];

					// Remove the WebRoute client state.
					unset($this->rwrclients[$id]);
				}
				else if ($result["errorcode"] !== "no_data")
				{
					// Retry a few times in case of a flaky connection.
					$data = $client["data"];
					if ($data["retries"] > 0)
					{
						$data["retries"]--;

						$result = $this->rwr->Connect($data["url"], $data["id"], $data["timeout"], "auto", $data["options"]);
						if ($result["success"])
						{
							$result["data"] = $data;

							$this->rwrclients[$id] = $result;
						}
					}
					else
					{
						// Client connection failed.
						unset($this->rwrclients[$id]);
					}
				}
			}
		}
	}
?>