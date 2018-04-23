<?php
	// Cloud Storage Server files SDK class.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	// Load dependencies.
	if (!class_exists("HTTP", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/http.php";
	if (!class_exists("WebBrowser", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/web_browser.php";
	if (!class_exists("RemotedAPI", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/sdk_remotedapi.php";

	// This class only supports the /files API.
	class CloudStorageServerFiles
	{
		private $web, $fp, $host, $apikey, $cafile, $cacert, $cert, $neterror;

		public function __construct()
		{
			$this->web = new WebBrowser();
			$this->fp = false;
			$this->host = false;
			$this->apikey = false;
			$this->cafile = false;
			$this->cacert = false;
			$this->cert = false;
		}

		public function SetAccessInfo($host, $apikey, $cafile, $cert)
		{
			$this->web = new WebBrowser();
			if (is_resource($this->fp))  @fclose($this->fp);
			$this->fp = false;
			if (substr($host, -1) === "/")  $host = substr($host, 0, -1);
			$this->host = $host;
			$this->apikey = $apikey;
			$this->cafile = $cafile;
			$this->cert = $cert;
		}

		public function GetSSLInfo()
		{
			if ($this->host === false)  return array("success" => false, "error" => self::CSS_Translate("Missing host."), "errorcode" => "no_access_info");

			if ($this->cafile !== false && $this->cacert === false)  $this->cacert = @file_get_contents($this->cafile);

			if (substr($this->host, 0, 7) === "http://" || RemotedAPI::IsRemoted($this->host))
			{
				$this->cacert = "";
				$this->cert = "";
			}
			else if ($this->cacert === false || $this->cert === false)
			{
				$this->cacert = false;
				$this->cert = false;

				$this->neterror = "";

				$options = array(
					"peer_cert_callback" => array($this, "Internal_PeerCertificateCheck"),
					"peer_cert_callback_opts" => "",
					"sslopts" => self::InitSSLOpts(array("verify_peer" => false, "capture_peer_cert_chain" => true))
				);

				$result = $this->web->Process($this->host . "/", $options);

				if (!$result["success"])
				{
					$result["error"] .= "  " . $this->neterror;

					return $result;
				}
			}

			if ($this->cert === false)  return array("success" => false, "error" => self::CSS_Translate("Unable to retrieve server certificate."), "errorcode" => "cert_retrieval_failed");
			if ($this->cacert === false)  return array("success" => false, "error" => self::CSS_Translate("Unable to retrieve server CA certificate."), "errorcode" => "cacert_retrieval_failed");

			return array("success" => true, "cacert" => $this->cacert, "cert" => $this->cert);
		}

		public function InitSSLCache($host, $cafile, $certfile)
		{
			if (!file_exists($cafile) || !file_exists($certfile))
			{
				$this->SetAccessInfo($host, false, false, false);

				$result = $this->GetSSLInfo();
				if (!$result["success"])  return array("success" => false, "error" => "Unable to get SSL information.", "errorcode" => "get_ssl_info_failed", "info" => $result);

				file_put_contents($cafile, $result["cacert"]);
				file_put_contents($certfile, $result["cert"]);
			}

			return array("success" => true);
		}

		public function GetObjectByPath($path)
		{
			if (substr($path, 0, 1) !== "/")  $path = "/" . $path;

			return $this->RunAPI("GET", "object/bypath" . $path);
		}

		public function GetRootFolderID()
		{
			return $this->RunAPI("GET", "user/root");
		}

		public function GetFolderList($folderid = "0")
		{
			$result = $this->RunAPI("GET", "folder/list/" . $folderid);
			if (!$result["success"])  return $result;

			$folders = array();
			$files = array();
			foreach ($result["body"]["items"] as $item)
			{
				if ($item["type"] === "file")  $files[$item["name"]] = $item;
				else  $folders[$item["name"]] = $item;
			}

			return array("success" => true, "id" => $folderid, "folders" => $folders, "files" => $files);
		}

		public function GetObjectIDByName($folderid, $name)
		{
			return $this->RunAPI("GET", "object/byname/" . $folderid . "/" . $name);
		}

		public function CreateFolder($folderid, $name)
		{
			$options = array(
				"folderid" => $folderid,
				"name" => $name
			);

			$result = $this->RunAPI("POST", "folder/create", $options);
			if (!$result["success"])  return $result;

			$result["id"] = $result["body"]["folder"]["id"];

			return $result;
		}

		public function GetTrashList($folderid = false)
		{
			return $this->RunAPI("GET", "trash/list" . ($folderid !== false ? "/" . $folderid : ""));
		}

		public function GetObjectByID($id)
		{
			return $this->RunAPI("GET", "object/byid/" . $id);
		}

		public function CopyObject($srcid, $destid)
		{
			$options = array(
				"srcid" => $srcid,
				"destid" => $destid
			);

			$result = $this->RunAPI("POST", "object/copy", $options);
			if (!$result["success"])  return $result;

			$result["id"] = $result["body"]["id"];

			return $result;
		}

		public function MoveObject($srcid, $destfolderid)
		{
			$options = array(
				"srcid" => $srcid,
				"destfolderid" => $destfolderid
			);

			return $this->RunAPI("POST", "object/move", $options);
		}

		public function RenameObject($id, $newname)
		{
			$options = array(
				"name" => $newname
			);

			return $this->RunAPI("POST", "object/rename/" . $id, $options);
		}

		public function TrashObject($id)
		{
			return $this->RunAPI("POST", "object/trash/" . $id);
		}

		public function RestoreObject($id)
		{
			return $this->RunAPI("POST", "object/restore/" . $id);
		}

		public function DeleteObject($id)
		{
			return $this->RunAPI("DELETE", "object/delete/" . $id);
		}

		public function GetUserLimits()
		{
			return $this->RunAPI("GET", "user/limits");
		}

		public function UploadFile($folderid, $destfilename, $data, $srcfilename, $fileid = false, $callback = false, $callbackopts = false)
		{
			// Determine if there is a file at the target already.  It is more efficient than uploading and discovering afterwards.
			if ($fileid === false)
			{
				$result = $this->GetObjectIDByName($folderid, $destfilename);
				if (!$result["success"] && $result["errorcode"] !== "object_not_found")  return $result;

				if ($result["success"] && $result["body"]["object"]["type"] !== "file")  return array("success" => false, "error" => self::CSS_Translate("Parent folder already contains an object named '%s' that is not a file.", $destfilename), "errorcode" => "object_already_exists");
			}

			$fileinfo = array(
				"name" => "data",
				"filename" => $destfilename,
				"type" => "application/octet-stream"
			);

			if ($srcfilename !== false)  $fileinfo["datafile"] = $srcfilename;
			else  $fileinfo["data"] = $data;

			$options = array(
				"debug_callback" => $callback,
				"debug_callback_opts" => $callbackopts,
				"postvars" => array(
					"name" => $destfilename
				),
				"files" => array($fileinfo)
			);

			return $this->RunAPI("POST", "file/upload/" . $folderid, $options, 200, false);
		}

		public function DownloadFile__Internal($response, $body, &$opts)
		{
			fwrite($opts["fp"], $body);

			if (is_callable($opts["callback"]))  call_user_func_array($opts["callback"], array(&$opts));

			return true;
		}

		// Callback option only used when destination is a file.
		public function DownloadFile($destfileorfp, $fileid, $callback = false, $database = false)
		{
			if ($destfileorfp === false)  $options = array();
			else
			{
				$fp = (is_resource($destfileorfp) ? $destfileorfp : fopen($destfileorfp, "wb"));
				if ($fp === false)  return array("success" => false, "error" => self::CSS_Translate("Invalid destination filename or handle."), "errorcode" => "invalid_filename_or_handle");

				$options = array(
					"read_body_callback" => array($this, "DownloadFile__Internal"),
					"read_body_callback_opts" => array("fp" => $fp, "fileid" => $fileid, "callback" => $callback)
				);
			}

			if ($database)  $result = $this->RunAPI("GET", "file/downloaddatabase", $options, 200, true, false);
			else  $result = $this->RunAPI("GET", "file/download/" . $fileid, $options, 200, true, false);

			if ($destfileorfp !== false && !is_resource($destfileorfp))  fclose($fp);

			return $result;
		}

		public function CreateGuest($rootid, $read, $write, $delete, $expires)
		{
			$options = array(
				"rootid" => $rootid,
				"read" => (int)(bool)$read,
				"write" => (int)(bool)$write,
				"delete" => (int)(bool)$delete,
				"expires" => (int)$expires
			);

			return $this->RunAPI("POST", "guest/create", $options);
		}

		public function GetGuestList()
		{
			return $this->RunAPI("GET", "guest/list");
		}

		public function DeleteGuest($id)
		{
			return $this->RunAPI("DELETE", "guest/delete/" . $id);
		}

		private static function CSS_Translate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}

		// Internal function to retrieve a X509 SSL certificate during the initial connection to confirm that this is the correct target server.
		public function Internal_PeerCertificateCheck($type, $cert, $opts)
		{
			if (is_array($cert))
			{
				// The server is incorrectly configured if it doesn't have the self-signed root certificate in the chain.
				if (count($cert) < 2)
				{
					$this->neterror = "Certificate chain is missing the root certificate.  Remote host is incorrectly configured.";

					return false;
				}

				// The last entry is the root cert.
				if (!openssl_x509_export($cert[count($cert) - 1], $str))
				{
					$this->neterror = "Certificate chain contains an invalid root certificate.  Corrupted on remote host?";

					return false;
				}

				$this->cacert = $str;
			}
			else
			{
				if (!openssl_x509_export($cert, $str))
				{
					$this->neterror = "Server certificate is invalid.  Corrupted on remote host?";

					return false;
				}

				// Initial setup automatically trusts the SSL/TLS certificate of the host.
				if ($this->cert === false)  $this->cert = $str;
				else if ($str !== $this->cert)
				{
					$this->neterror = "Certificate does not exactly match local certificate.  Your client is either under a MITM attack or the remote host changed certificates.";

					return false;
				}
			}

			return true;
		}

		private static function InitSSLOpts($options)
		{
			$result = array_merge(array(
				"ciphers" => "ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-AES128-SHA256:ECDHE-RSA-AES128-SHA256:ECDHE-ECDSA-AES128-SHA:ECDHE-RSA-AES256-SHA384:ECDHE-RSA-AES128-SHA:ECDHE-ECDSA-AES256-SHA384:ECDHE-ECDSA-AES256-SHA:ECDHE-RSA-AES256-SHA:DHE-RSA-AES128-SHA256:DHE-RSA-AES128-SHA:DHE-RSA-AES256-SHA256:DHE-RSA-AES256-SHA:ECDHE-ECDSA-DES-CBC3-SHA:ECDHE-RSA-DES-CBC3-SHA:EDH-RSA-DES-CBC3-SHA:AES128-GCM-SHA256:AES256-GCM-SHA384:AES128-SHA256:AES256-SHA256:AES128-SHA:AES256-SHA:DES-CBC3-SHA:!DSS",
				"disable_compression" => true,
				"allow_self_signed" => false,
				"verify_peer_name" => false,
				"verify_depth" => 3,
				"capture_peer_cert" => true,
			), $options);

			return $result;
		}

		private function RunAPI($method, $apipath, $options = array(), $expected = 200, $encodejson = true, $decodebody = true)
		{
			if ($this->host === false || $this->apikey === false)  return array("success" => false, "error" => self::CSS_Translate("Missing host or API key."), "errorcode" => "no_access_info");
			if ($this->cafile === false || $this->cert === false)  return array("success" => false, "error" => self::CSS_Translate("Missing SSL Certificate or Certificate Authority filename.  Call GetSSLInfo() to initialize for the first time and be sure to save the results."), "errorcode" => "critical_ssl_info_missing");

			$url = $this->host;

			// Handle Remoted API connections.
			if ($this->fp === false && RemotedAPI::IsRemoted($this->host))
			{
				$result = RemotedAPI::Connect($this->host);
				if (!$result["success"])  return $result;

				$this->fp = $result["fp"];
				$url = $result["url"];

				$options2 = array(
					"fp" => $this->fp,
					"method" => $method,
					"headers" => array(
						"Connection" => "keep-alive",
						"X-APIKey" => $this->apikey
					)
				);
			}
			else if ($this->fp !== false)
			{
				$url = RemotedAPI::ExtractRealHost($url);

				$options2 = array(
					"fp" => $this->fp,
					"method" => $method,
					"headers" => array(
						"Connection" => "keep-alive"
					)
				);
			}
			else
			{
				$options2 = array(
					"method" => $method,
					"headers" => array(
						"Connection" => "keep-alive",
						"X-APIKey" => $this->apikey
					),
					"peer_cert_callback" => array($this, "Internal_PeerCertificateCheck"),
					"peer_cert_callback_opts" => "",
					"sslopts" => self::InitSSLOpts(array("cafile" => $this->cafile, "verify_peer" => true))
				);
			}

			if ($encodejson && $method !== "GET")
			{
				$options2["headers"]["Content-Type"] = "application/json";
				$options2["body"] = json_encode($options);
			}
			else
			{
				$options2 = array_merge($options2, $options);
			}

			$result = $this->web->Process($url . "/files/v1/" . $apipath, $options2);

			if (!$result["success"] && $this->fp !== false)
			{
				// If the server terminated the connection, then re-establish the connection and rerun the request.
				@fclose($this->fp);
				$this->fp = false;

				return $this->RunAPI($method, $apipath, $options, $expected, $encodejson, $decodebody);
			}

			if (!$result["success"])  return $result;

			if (isset($result["fp"]) && is_resource($result["fp"]))  $this->fp = $result["fp"];
			else  $this->fp = false;

			// Cloud Storage Server always responds with 400 Bad Request for errors.  Attempt to decode the error.
			if ($result["response"]["code"] == 400)
			{
				$error = @json_decode($result["body"], true);
				if (is_array($error) && isset($error["success"]) && !$error["success"])  return $error;
			}

			if ($result["response"]["code"] != $expected)  return array("success" => false, "error" => self::CSS_Translate("Expected a %d response from Cloud Storage Server.  Received '%s'.", $expected, $result["response"]["line"]), "errorcode" => "unexpected_cloud_storage_server_response", "info" => $result);

			if ($decodebody)  $result["body"] = json_decode($result["body"], true);

			return $result;
		}
	}
?>