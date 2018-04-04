<?php
	// Remoted API PHP SDK.
	// (C) 2017 CubicleSoft.  All Rights Reserved.

	// Depends on the CubicleSoft WebRoute class.
	class RemotedAPI
	{
		public static function IsRemoted($url)
		{
			return (strtolower(substr($url, 0, 6)) === "rwr://" || strtolower(substr($url, 0, 7)) === "rwrs://");
		}

		public static function ExtractRealHost($url)
		{
			$urls = explode(" ", preg_replace('/\s+/', " ", $url));
			foreach ($urls as $url)
			{
				if (strtolower(substr($url, 0, 6)) !== "rwr://" && strtolower(substr($url, 0, 7)) !== "rwrs://")  return $url;
			}

			return "";
		}

		// Expected URL format:  rwr://clientapikey@host/webroutepath
		// Where 'clientapikey' is the Remoted API server client API key and 'webroutepath' is the path to a connected remoted API.
		// Multiple layers can be specified by separating URLs with spaces.
		public static function Connect($url, $timeout = false, $options = array(), $web = false)
		{
			if (!class_exists("WebRoute", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/webroute.php";
			if (!class_exists("WebBrowser", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/web_browser.php";
			if (!class_exists("HTTP", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/http.php";

			$wr = new WebRoute();
			if ($web === false)  $web = new WebBrowser();
			if (!isset($options["headers"]))  $options["headers"] = array();
			$urls = explode(" ", preg_replace('/\s+/', " ", $url));
			$result = false;
			foreach ($urls as $url)
			{
				$url2 = HTTP::ExtractURL($url);

				if ($url2["scheme"] !== "rwr" && $url2["scheme"] !== "rwrs")
				{
					if ($result === false)  return array("success" => false, "error" => WebRoute::WRTranslate("Invalid Remoted API URL scheme."), "errorcode" => "invalid_scheme");

					$result["url"] = $url;

					return $result;
				}

				if ($url2["loginusername"] === "")  return array("success" => false, "error" => WebRoute::WRTranslate("Remoted API URL is missing client key."), "errorcode" => "missing_client_key");

				$options["headers"]["X-Remoted-APIKey"] = $url2["loginusername"];

				$url2["scheme"] = ($url2["scheme"] === "rwr" ? "wr" : "wrs");
				unset($url2["loginusername"]);
				unset($url2["login"]);

				$url = HTTP::CondenseURL($url2);

				$result = $wr->Connect($url, false, $timeout, $options, $web);
				if (!$result["success"])  return $result;

				$options["fp"] = $result["fp"];
			}

			return $result;
		}
	}
?>