<?php
	// Cloud storage server support functions.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	require_once $rootpath . "/support/random.php";

	$css_messages = array();
	function CSS_Log($msg)
	{
		global $css_messages;

		$css_messages[] = $msg . "\n";
		echo $msg . "\n";
	}

	function CSS_DisplayError($msg, $result = false, $exit = true)
	{
		ob_start();

		CSS_Log(($exit ? "[Error] " : "") . $msg);

		if ($result !== false)
		{
			if (isset($result["error"]))  CSS_Log("[Error] " . $result["error"] . " (" . $result["errorcode"] . ")");
			if (isset($result["info"]))  var_dump($result["info"]);
		}

		fwrite(STDERR, ob_get_contents());
		ob_end_clean();

		if ($exit)  exit();
	}

	function CSS_LoadConfig()
	{
		global $rootpath;

		if (file_exists($rootpath . "/data/config.dat"))  $result = json_decode(file_get_contents($rootpath . "/data/config.dat"), true);
		else  $result = array();
		if (!is_array($result))  $result = array();

		return $result;
	}

	function CSS_SaveConfig($config)
	{
		global $rootpath;

		file_put_contents($rootpath . "/data/config.dat", json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		@chmod($rootpath . "/data/config.dat", 0660);
	}

	function CSS_LoadServerExtensions()
	{
		global $rootpath;

		$serverexts = array();
		$dir = opendir($rootpath . "/server_exts");
		if ($dir !== false)
		{
			while (($file = readdir($dir)) !== false)
			{
				if (substr($file, -4) === ".php")
				{
					require_once $rootpath . "/server_exts/" . $file;

					$key = substr($file, 0, -4);
					$classname = "CSS_Extension_" . $key;
					$serverexts[$key] = new $classname;
				}
			}

			closedir($dir);
		}

		return $serverexts;
	}

	function CSS_CopyDir($srcdir, $destdir)
	{
		$dir = @opendir($srcdir);
		if ($dir)
		{
			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== "..")
				{
					$srcfile = $srcdir . "/" . $file;
					$destfile = $destdir . "/" . $file;
					if (is_dir($srcfile))
					{
						@mkdir($destfile, 0770);

						CSS_CopyDir($srcfile, $destfile);
					}
					else
					{
						@copy($srcfile, $destfile);
					}
				}
			}

			closedir($dir);
		}
	}

	function CSS_RemoveDir($path)
	{
		if (is_link($path))
		{
			$result = @unlink($path);
			if ($result === false)  return array("success" => false, "error" => "Unable to remove symbolic link '" . $path . "'.", "errorcode" => "unlink_failed");
		}
		else
		{
			$dir = @opendir($path);
			if ($dir === false)  return array("success" => false, "error" => "Unable to open the directory '" . $path . "' for reading.", "errorcode" => "opendir_failed");

			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== "..")
				{
					$filename = $path . "/" . $file;
					if (is_dir($filename))
					{
						$result = CSS_RemoveDir($filename);
						if (!$result["success"])
						{
							closedir($dir);

							return $result;
						}
					}
					else
					{
						$result = @unlink($filename);
						if ($result === false)
						{
							closedir($dir);

							return array("success" => false, "error" => "Unable to remove '" . $filename . "'.", "errorcode" => "unlink_failed");
						}
					}
				}
			}

			closedir($dir);

			$result = @rmdir($path);
			if ($result === false)  return array("success" => false, "error" => "Unable to remove directory '" . $path . "'.", "errorcode" => "rmdir_failed");
		}

		return array("success" => true);
	}

	function CSS_ConvertUserStrToBytes($str)
	{
		$str = trim($str);
		$num = (double)$str;
		if (strtoupper(substr($str, -1)) == "B")  $str = substr($str, 0, -1);
		switch (strtoupper(substr($str, -1)))
		{
			case "P":  $num *= 1024;
			case "T":  $num *= 1024;
			case "G":  $num *= 1024;
			case "M":  $num *= 1024;
			case "K":  $num *= 1024;
		}

		return $num;
	}

	function CSS_GetQuotaLeft($userrow)
	{
		$quota = (double)$userrow->quota;
		if ($quota < 0)  return -1;

		$totalbytes = (double)$userrow->totalbytes;
		return ($quota > $totalbytes ? $quota - $totalbytes : 0);
	}

	function CSS_GetTransferLeft($userrow)
	{
		$transferlimit = (double)$userrow->transferlimit;
		if ($transferlimit < 0)  return -1;

		$transferbytes = (double)$userrow->transferbytes;
		return ($transferlimit > $transferbytes ? $transferlimit - $transferbytes : 0);
	}

	function CSS_GetMinAmountLeft($amountsleft)
	{
		$result = false;
		foreach ($amountsleft as $bytesleft)
		{
			if ($bytesleft > -1 && ($result === false || $result > $bytesleft))  $result = $bytesleft;
		}

		return $result;
	}

	function CSS_AdjustRecvLimit($client, $recvlimit)
	{
		$options = $client->GetHTTPOptions();
		if ($recvlimit === false)  unset($options["recvlimit"]);
		else  $options["recvlimit"] = ($recvlimit > 1000000 ? $recvlimit + 1000000 : 1000000);
		$client->SetHTTPOptions($options);
	}

	// Constant-time string comparison.  Ported from CubicleSoft C++ code.
	function CSS_CTstrcmp($secret, $userinput)
	{
		$sx = 0;
		$sy = strlen($secret);
		$uy = strlen($userinput);
		$result = $sy - $uy;
		for ($ux = 0; $ux < $uy; $ux++)
		{
			$result |= ord($userinput{$ux}) ^ ord($secret{$sx});
			$sx = ($sx + 1) % $sy;
		}

		return $result;
	}

	class CSS_UserHelper
	{
		private $config, $rng, $db, $em;

		public function Init($config, $db, $em)
		{
			$this->config = $config;
			$this->rng = new CSPRNG();
			$this->db = $db;
			$this->em = $em;
		}

		public function CreateUser($username, $options = array())
		{
			$result = $this->GetUserByUsername($username);
			if ($result["success"])  return array("success" => false, "error" => "Unable to create the user.  User '" . $username . "' already exists.", "errorcode" => "already_exists");

			$options["apikey"] = $this->rng->GenerateString(64);
			$options["username"] = $username;
			$options["serverexts"] = json_encode(isset($options["serverexts"]) ? $options["serverexts"] : array());
			if (!isset($options["basepath"]))  $options["basepath"] = $this->config["basepath"];
			$options["totalbytes"] = 0;
			if (!isset($options["quota"]))  $options["quota"] = CSS_ConvertUserStrToBytes($this->config["quota"]);
			$options["transferstart"] = 0;
			$options["transferbytes"] = 0;
			if (!isset($options["transferlimit"]))  $options["transferlimit"] = CSS_ConvertUserStrToBytes($this->config["transferlimit"]);
			$options["lastupdated"] = time();

			try
			{
				$this->db->Query("INSERT", array("users", $options, "AUTO INCREMENT" => "id"));

				$id = $this->db->GetInsertID();
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to create the user.  " . $e->getMessage(), "errorcode" => "db_insert_failed");
			}

			$result = $this->GetUserByID($id);
			if (!$result["success"])  return $result;

			$userrow = $result["info"];

			@mkdir($userrow->basepath . "/" . $id, 0770, true);

			// Trigger registered handlers.
			$this->em->Fire("CSS_UserHelper::CreateUser", array($id, $userrow));

			return array("success" => true, "id" => $id, "info" => $userrow);
		}

		public function GetUserByUsername($username)
		{
			try
			{
				$userrow = $this->db->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "username = ?",
				), "users", $username);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to retrieve the user from the database.  " . $e->getMessage(), "errorcode" => "db_select_failed");
			}

			if ($userrow === false)  return array("success" => false, "error" => "User '" . $username . "' does not exist.", "errorcode" => "no_user");

			$userrow->basepath = str_replace("\\", "/", $userrow->basepath);
			while (substr($userrow->basepath, -1) === "/")  $userrow->basepath = substr($userrow->basepath, 0, -1);

			$userrow->serverexts = @json_decode($userrow->serverexts, true);

			return array("success" => true, "info" => $userrow);
		}

		public function GetUserByID($id, $apikey = false)
		{
			try
			{
				$userrow = $this->db->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ?",
				), "users", $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to retrieve the user from the database.  " . $e->getMessage(), "errorcode" => "db_select_failed");
			}

			if ($userrow === false)  return array("success" => false, "error" => "User " . $id . " does not exist.", "errorcode" => "no_user");

			if ($apikey !== false && CSS_CTstrcmp($userrow->apikey, $apikey))  return array("success" => false, "error" => "Invalid API key.", "errorcode" => "invalid_api_key");

			$userrow->basepath = str_replace("\\", "/", $userrow->basepath);
			while (substr($userrow->basepath, -1) === "/")  $userrow->basepath = substr($userrow->basepath, 0, -1);

			$userrow->serverexts = @json_decode($userrow->serverexts, true);

			return array("success" => true, "info" => $userrow);
		}

		public function UpdateUser($id, $options)
		{
			if (isset($options["serverexts"]))  $options["serverexts"] = json_encode($options["serverexts"]);
			$options["lastupdated"] = time();

			try
			{
				$this->db->Query("UPDATE", array("users", $options, "WHERE" => "id = ?"), $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to update the user.  " . $e->getMessage(), "errorcode" => "db_update_failed");
			}

			// Trigger registered handlers.
			$this->em->Fire("CSS_UserHelper::UpdateUser", array($id, $options));

			return array("success" => true);
		}

		public function AdjustUserTotalBytes($id, $diff)
		{
			try
			{
				$this->db->Query("UPDATE", array("users", array(), array(
					"totalbytes" => "totalbytes + " . (double)$diff,
				), "WHERE" => "id = ?"), $id);

				$this->db->Query("UPDATE", array("users", array(
					"totalbytes" => "0",
				), "WHERE" => "id = ? AND totalbytes < 0"), $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to update the user.  " . $e->getMessage(), "errorcode" => "db_update_failed");
			}

			return array("success" => true);
		}

		public function DeleteUser($id)
		{
			$result = $this->GetUserByID($id);
			if (!$result["success"])  return $result;

			$userrow = $result["info"];

			// Deny attempts to access anything while deletion is in progress.
			$options = array("serverexts" => array());
			$this->UpdateUser($id, $options);

			// Trigger registered handlers.
			$this->em->Fire("CSS_UserHelper::DeleteUser", array($id, $userrow));

			// Delete all directories and files for the user.
			if (is_dir($userrow->basepath . "/" . $id))
			{
				$result = CSS_RemoveDir($userrow->basepath . "/" . $id);
				if (!$result["success"])  return $result;
			}

			// Remove the actual user account.
			try
			{
				$this->db->Query("DELETE", array("users", "WHERE" => "id = ?"), $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to delete the user.  " . $e->getMessage(), "errorcode" => "db_delete_failed");
			}

			return array("success" => true);
		}

		public function CreateGuest($uid, $serverext, $serverextinfo, $expires)
		{
			$this->DeleteExpiredGuests();

			try
			{
				$this->db->Query("INSERT", array("guests", array(
					"uid" => $uid,
					"apikey" => $this->rng->GenerateString(64),
					"serverexts" => json_encode(array($serverext => $serverextinfo)),
					"created" => time(),
					"expires" => $expires
				), "AUTO INCREMENT" => "id"));

				$id = $this->db->GetInsertID();
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to create the user.  " . $e->getMessage(), "errorcode" => "db_insert_failed");
			}

			$result = $this->GetGuestByID($id, $uid);
			if (!$result["success"])  return $result;

			$guestrow = $result["info"];

			// Trigger registered handlers.
			$this->em->Fire("CSS_UserHelper::CreateGuest", array($id, $guestrow));

			$guestrow->apikey = $guestrow->apikey . "-" . $guestrow->uid . "-" . $guestrow->id;
			unset($guestrow->uid);

			$guestrow->info = $guestrow->serverexts[$serverext];
			unset($guestrow->serverexts);

			return array("success" => true, "id" => $id, "info" => $guestrow);
		}

		public function GetGuestByID($id, $uid, $apikey = false)
		{
			try
			{
				$guestrow = $this->db->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ? AND uid = ? AND expires > ?",
				), "guests", $id, $uid, time());
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to retrieve the user from the database.  " . $e->getMessage(), "errorcode" => "db_select_failed");
			}

			if ($guestrow === false)  return array("success" => false, "error" => "Guest does not exist.", "errorcode" => "no_user");

			if ($apikey !== false && CSS_CTstrcmp($guestrow->apikey, $apikey))  return array("success" => false, "error" => "Invalid API key.", "errorcode" => "invalid_api_key");

			$guestrow->serverexts = @json_decode($guestrow->serverexts, true);

			return array("success" => true, "info" => $guestrow);
		}

		public function GetGuestsByServerExtension($uid, $serverext)
		{
			$results = array();

			try
			{
				$result = $this->db->Query("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "uid = ? AND expires > ?",
				), "guests", $uid, time());

				while ($row = $result->NextRow())
				{
					$row->serverexts =  @json_decode($row->serverexts, true);

					if (isset($row->serverexts[$serverext]))
					{
						$row->info = $row->serverexts[$serverext];
						unset($row->serverexts);

						$row->apikey = $row->apikey . "-" . $row->uid . "-" . $row->id;
						unset($row->uid);

						$results[] = (array)$row;
					}
				}
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to retrieve the user from the database.  " . $e->getMessage(), "errorcode" => "db_select_failed");
			}

			return array("success" => true, "guests" => $results);
		}

		public function DeleteExpiredGuests()
		{
			// Trigger registered handlers.
			$this->em->Fire("CSS_UserHelper::DeleteExpiredGuests", array());

			try
			{
				$this->db->Query("DELETE", array("guests", "WHERE" => "expires <= ?"), time());
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to delete expired guests.  " . $e->getMessage(), "errorcode" => "db_delete_failed");
			}

			return array("success" => true);
		}

		public function DeleteGuest($id, $uid)
		{
			$this->DeleteExpiredGuests();

			// Trigger registered handlers.
			$this->em->Fire("CSS_UserHelper::DeleteGuest", array($id, $uid));

			try
			{
				$this->db->Query("DELETE", array("guests", "WHERE" => "id = ? AND uid = ?"), $id, $uid);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "Unable to delete guest.  " . $e->getMessage(), "errorcode" => "db_delete_failed");
			}

			return array("success" => true);
		}
	}

	// Check enabled extensions.
	if (!extension_loaded("openssl"))  CB_DisplayError("The 'openssl' PHP module is not enabled.  Please update the file '" . (php_ini_loaded_file() !== false ? php_ini_loaded_file() : "php.ini") . "' to enable the module.");
	if (!extension_loaded("zlib"))  CB_DisplayError("The 'zlib' PHP module is not enabled.  Please update the file '" . (php_ini_loaded_file() !== false ? php_ini_loaded_file() : "php.ini") . "' to enable the module.");
?>