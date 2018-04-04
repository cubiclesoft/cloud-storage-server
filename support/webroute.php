<?php
	// CubicleSoft PHP WebRoute class.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	// Requires the CubicleSoft PHP HTTP/HTTPS class.
	// Requires the CubicleSoft PHP WebBrowser class.
	// Requires the CubicleSoft PHP CSPRNG class.
	class WebRoute
	{
		private $csprng;

		const ID_GUID = "BE7204BD-47E6-49EE-9B0D-016E370644B2";

		public function __construct()
		{
			$this->csprng = false;
		}

		public static function ProcessState($state)
		{
			while ($state->state !== "done")
			{
				switch ($state->state)
				{
					case "initialize":
					{
						$result = $state->web->Process($state->url, $state->options);
						if (!$result["success"])  return $result;

						if (isset($state->options["async"]) && $state->options["async"])
						{
							$state->async = true;
							$state->webstate = $result["state"];

							$state->state = "process_async";
						}
						else
						{
							$state->result = $result;

							$state->state = "post_retrieval";
						}

						break;
					}
					case "process_async":
					{
						// Run a cycle of the WebBrowser state processor.
						$result = $state->web->ProcessState($state->webstate);
						if (!$result["success"])  return $result;

						$state->webstate = false;
						$state->result = $result;

						$state->state = "post_retrieval";

						break;
					}
					case "post_retrieval":
					{
						if ($state->result["response"]["code"] != 101)  return array("success" => false, "error" => self::WRTranslate("WebRoute::Connect() failed to connect to the WebRoute.  Server returned:  %s %s", $result["response"]["code"], $result["response"]["meaning"]), "errorcode" => "incorrect_server_response");
						if (!isset($state->result["headers"]["Sec-Webroute-Accept"]))  return array("success" => false, "error" => self::WRTranslate("Server failed to include a 'Sec-WebRoute-Accept' header in its response to the request."), "errorcode" => "missing_server_webroute_accept_header");

						// Verify the Sec-WebRoute-Accept response.
						if ($state->result["headers"]["Sec-Webroute-Accept"][0] !== base64_encode(sha1($state->options["headers"]["WebRoute-ID"] . self::ID_GUID, true)))  return array("success" => false, "error" => self::WRTranslate("The server's 'Sec-WebRoute-Accept' header is invalid."), "errorcode" => "invalid_server_webroute_accept_header");

						$state->state = "done";

						break;
					}
				}
			}

			return $state->result;
		}

		public function Connect($url, $id = false, $timeout = false, $options = array(), $web = false)
		{
			// Generate client ID.
			if ($id === false)
			{
				if (!class_exists("CSPRNG", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/random.php";

				if ($this->csprng === false)  $this->csprng = new CSPRNG();

				$id = $this->csprng->GenerateString(64);
			}

			if (!class_exists("WebBrowser", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/web_browser.php";

			// Use WebBrowser to initiate the connection.
			if ($web === false)  $web = new WebBrowser();

			// Transform URL.
			$url2 = HTTP::ExtractURL($url);
			if ($url2["scheme"] != "wr" && $url2["scheme"] != "wrs")  return array("success" => false, "error" => self::WRTranslate("WebRoute::Connect() only supports the 'wr' and 'wrs' protocols."), "errorcode" => "protocol_check");
			$url2["scheme"] = str_replace("wr", "http", $url2["scheme"]);
			$url2 = HTTP::CondenseURL($url2);

			// Generate correct request headers.
			if (!isset($options["headers"]))  $options["headers"] = array();
			$options["headers"]["Connection"] = "keep-alive, Upgrade";
			$options["headers"]["Pragma"] = "no-cache";
			$options["headers"]["WebRoute-Version"] = "1";
			$options["headers"]["WebRoute-ID"] = $id;
			if ($timeout !== false && is_int($timeout))  $options["headers"]["WebRoute-Timeout"] = (string)(int)$timeout;
			$options["headers"]["Upgrade"] = "webroute";

			// Initialize the process state object.
			$state = new stdClass();
			$state->async = false;
			$state->state = "initialize";
			$state->web = $web;
			$state->url = $url2;
			$state->options = $options;
			$state->webstate = false;
			$state->result = false;

			// Run at least one state cycle to finish initializing the state object.
			$result = $this->ProcessState($state);

			// Return the state for async calls.  Caller must call ProcessState().
			if ($state->async)  return array("success" => true, "id" => $id, "state" => $state);

			$result["id"] = $id;

			return $result;
		}

		// Implements the correct MultiAsyncHelper responses for WebRoute instances.
		public function ConnectAsync__Handler($mode, &$data, $key, $info)
		{
			switch ($mode)
			{
				case "init":
				{
					if ($info->init)  $data = $info->keep;
					else
					{
						$info->result = $this->Connect($info->url, $info->id, $info->timeout, $info->options, $info->web);
						if (!$info->result["success"])
						{
							$info->keep = false;

							if (is_callable($info->callback))  call_user_func_array($info->callback, array($key, $info->url, $info->result));
						}
						else
						{
							$info->id = $info->result["id"];
							$info->state = $info->result["state"];

							// Move to the live queue.
							$data = true;
						}
					}

					break;
				}
				case "update":
				case "read":
				case "write":
				{
					if ($info->keep)
					{
						$info->result = $this->ProcessState($info->state);
						if ($info->result["success"] || $info->result["errorcode"] !== "no_data")  $info->keep = false;

						if (is_callable($info->callback))  call_user_func_array($info->callback, array($key, $info->url, $info->result));

						if ($mode === "update")  $data = $info->keep;
					}

					break;
				}
				case "readfps":
				{
					if ($info->state->webstate["httpstate"] !== false && HTTP::WantRead($info->state->webstate["httpstate"]))  $data[$key] = $info->state->webstate["httpstate"]["fp"];

					break;
				}
				case "writefps":
				{
					if ($info->state->webstate["httpstate"] !== false && HTTP::WantWrite($info->state->webstate["httpstate"]))  $data[$key] = $info->state->webstate["httpstate"]["fp"];

					break;
				}
				case "cleanup":
				{
					// When true, caller is removing.  Otherwise, detaching from the queue.
					if ($data === true)
					{
						if (isset($info->state))
						{
							if ($info->state->webstate["httpstate"] !== false)  HTTP::ForceClose($info->state->webstate["httpstate"]);

							unset($info->state);
						}

						$info->keep = false;
					}

					break;
				}
			}
		}

		public function ConnectAsync($helper, $key, $callback, $url, $id = false, $timeout = false, $options = array(), $web = false)
		{
			$options["async"] = true;

			$info = new stdClass();
			$info->init = false;
			$info->keep = true;
			$info->callback = $callback;
			$info->url = $url;
			$info->id = $id;
			$info->timeout = $timeout;
			$info->options = $options;
			$info->web = $web;
			$info->result = false;

			$helper->Set($key, $info, array($this, "ConnectAsync__Handler"));

			return array("success" => true);
		}

		public static function WRTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>