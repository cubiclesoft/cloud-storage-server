<?php
	// Cloud Storage Server management tool basic shell functions.
	// (C) 2016 CubicleSoft.  All Rights Reserved.

	function shell_cmd_adduser($line)
	{
		global $userhelper, $config;

		$options = array(
			"shortmap" => array(
				"b" => "basepath",
				"q" => "quota",
				"t" => "transferlimit",
				"?" => "help"
			),
			"rules" => array(
				"basepath" => array("arg" => true),
				"quota" => array("arg" => true),
				"transferlimit" => array("arg" => true),
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Adds a user\n";
			echo "Purpose:  Creates a new user.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] username\n";
			echo "Options:\n";
			echo "\t-b   Base file path.  Overrides default user path.\n";
			echo "\t-q   Quota in bytes, KB, MB, GB, or TB.  Overrides default quota.\n";
			echo "\t-t   Transfer limit in bytes, KB, MB, GB, or TB.  Overrides default transfer limit.\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " -q=5GB djohnson\n";

			return;
		}

		$options = array();
		if (isset($args["opts"]["basepath"]))  $options["basepath"] = $args["opts"]["basepath"];
		if (isset($args["opts"]["quota"]))  $options["quota"] = CSS_ConvertUserStrToBytes($args["opts"]["quota"]);
		if (isset($args["opts"]["transferlimit"]))  $options["transferlimit"] = CSS_ConvertUserStrToBytes($args["opts"]["transferlimit"]);

		$result = $userhelper->CreateUser($args["params"][0], $options);
		if (!$result["success"])  CSS_DisplayError("Unable to create user.", $result, false);
		else
		{
			echo "Host:  https://" . $config["publichost"] . ":" . $config["port"] . "\n";
			echo "API key:  " . $result["info"]->apikey . "-" . $result["info"]->id . "\n";
		}
	}

	function shell_cmd_deluser($line)
	{
		global $userhelper;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Permanently deletes a user and their files.  To only disable an account, remove all extensions.\n";
			echo "Purpose:  Delete a user account.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] username\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " djohnson\n";

			return;
		}

		$result = $userhelper->GetUserByUsername($args["params"][0]);
		if (!$result["success"])  CSS_DisplayError("Unable to find the user.", $result, false);
		else
		{
			$userrow = $result["info"];

			$result = $userhelper->DeleteUser($userrow->id);
			if (!$result["success"])  CSS_DisplayError("Unable to delete the user.", $result, false);
		}
	}

	function shell_cmd_adduserext($line)
	{
		global $userhelper, $serverexts;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || !isset($serverexts[$args["params"][1]]) || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Adds/Overwrites and configures an extension to a specific user account.\n";
			echo "Purpose:  Add/Overwrite user account server extension.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] username serverextension\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Available extensions:\n";
			foreach ($serverexts as $name => $obj)  echo "\t" . $name . "\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " djohnson files\n";

			return;
		}

		$result = $userhelper->GetUserByUsername($args["params"][0]);
		if (!$result["success"])  CSS_DisplayError("Unable to find the user.", $result, false);
		else
		{
			$userrow = $result["info"];

			$result = $serverexts[$args["params"][1]]->AddUserExtension($userrow);
			if (!$result["success"])  CSS_DisplayError("Unable to get user extension.", $result, false);
			else
			{
				$options = array();
				$options["serverexts"] = $userrow->serverexts;
				$options["serverexts"][$args["params"][1]] = $result["info"];

				$result = $userhelper->UpdateUser($userrow->id, $options);
				if (!$result["success"])  CSS_DisplayError("Unable to update the user.", $result, false);
			}
		}
	}

	function shell_cmd_deluserext($line)
	{
		global $userhelper;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Removes an extension from a specific user account.\n";
			echo "Purpose:  Removes user account server extension.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] username serverextension\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " djohnson\n";

			return;
		}

		$result = $userhelper->GetUserByUsername($args["params"][0]);
		if (!$result["success"])  CSS_DisplayError("Unable to find the user.", $result, false);
		else
		{
			$userrow = $result["info"];

			$options = array();
			$options["serverexts"] = $userrow->serverexts;
			unset($options["serverexts"][$args["params"][1]]);

			$result = $userhelper->UpdateUser($userrow->id, $options);
			if (!$result["success"])  CSS_DisplayError("Unable to update the user.", $result, false);
		}
	}

	function shell_cmd_setuserquota($line)
	{
		global $userhelper;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Sets a user's quota in bytes, KB, MB, GB, or TB.  A value of -1 is unlimited.  Extensions decide when to consume quota.\n";
			echo "Purpose:  Sets the limit of the number of 'quota bytes' a user can store.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] username newquota\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " djohnson 5GB\n";

			return;
		}

		$result = $userhelper->GetUserByUsername($args["params"][0]);
		if (!$result["success"])  CSS_DisplayError("Unable to find the user.", $result, false);
		else
		{
			$userrow = $result["info"];

			$options = array();
			$options["quota"] = CSS_ConvertUserStrToBytes($args["params"][1]);

			$result = $userhelper->UpdateUser($userrow->id, $options);
			if (!$result["success"])  CSS_DisplayError("Unable to update the user.", $result, false);
		}
	}

	function shell_cmd_setusertransferlimit($line)
	{
		global $userhelper;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) != 2 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Sets a user's daily transfer limit in bytes, KB, MB, GB, or TB.  A value of -1 is unlimited.  Transfer limits affect total network usage, not just upload/download.\n";
			echo "Purpose:  Sets the daily transfer limit for the user.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] username newtransferlimit\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " djohnson 15GB\n";

			return;
		}

		$result = $userhelper->GetUserByUsername($args["params"][0]);
		if (!$result["success"])  CSS_DisplayError("Unable to find the user.", $result, false);
		else
		{
			$userrow = $result["info"];

			$options = array();
			$options["transferlimit"] = CSS_ConvertUserStrToBytes($args["params"][1]);

			$result = $userhelper->UpdateUser($userrow->id, $options);
			if (!$result["success"])  CSS_DisplayError("Unable to update the user.", $result, false);
		}
	}

	function shell_cmd_showusers($line)
	{
		global $db;

		$options = array(
			"shortmap" => array(
				"?" => "help"
			),
			"rules" => array(
				"help" => array("arg" => false)
			)
		);
		$args = ParseCommandLine($options, $line);

		if (count($args["params"]) > 1 || isset($args["opts"]["help"]))
		{
			echo $args["file"] . " - Finds matching users and dumps account information.\n";
			echo "Purpose:  Shows user details.\n";
			echo "\n";
			echo "Syntax:  " . $args["file"] . " [options] [username]\n";
			echo "Options:\n";
			echo "\t-?   This help documentation.\n";
			echo "\n";
			echo "Example:  " . $args["file"] . " djohn\n";

			return;
		}

		try
		{
			if (count($args["params"]))
			{
				$result = $db->Query("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "username LIKE ?",
				), "users", "%" . $args["params"][0] . "%");
			}
			else
			{
				$result = $db->Query("SELECT", array(
					"*",
					"FROM" => "?",
				), "users");
			}

			while ($row = $result->NextRow())
			{
				echo $row->username . " (" . $row->id . "):\n";
				echo "  API key:  " . $row->apikey . "-" . $row->id . "\n";
				echo "  Base path:  " . $row->basepath . "\n";
				echo "  Extensions:\n";
				$row->serverexts = json_decode($row->serverexts, true);
				foreach ($row->serverexts as $name => $info)  echo "    " . $name . ":  " . json_encode($info) . "\n";
				echo "  Storage:  " . number_format($row->totalbytes, 0) . " bytes used\n";
				echo "  Quota:  " . ($row->quota == -1 ? "Unlimited" : number_format($row->quota, 0) . " bytes") . "\n";
				echo "  Transferred:  " . number_format($row->transferbytes, 0) . " bytes\n";
				echo "  Transfer limit:  " . ($row->transferlimit == -1 ? "Unlimited" : number_format($row->transferlimit, 0) . " bytes") . "\n";
				echo "  Last updated:  " . date("F j, Y @ g:i a", $row->lastupdated) . "\n";
				echo "\n";
			}
		}
		catch (Exception $e)
		{
			$result = array("success" => false, "error" => "Unable to retrieve users from the database.  " . $e->getMessage(), "errorcode" => "db_select_failed");
			CSS_DisplayError("Unable to find users.", $result, false);
		}
	}

	function shell_cmd_help($line)
	{
		echo "help - List available shell functions\n";
		echo "\n";
		echo "Functions:\n";

		$functions = array("quit", "exit");

		$result = get_defined_functions();
		if (isset($result["user"]))
		{
			foreach ($result["user"] as $name)
			{
				if (strtolower(substr($name, 0, 10)) == "shell_cmd_")  $functions[] = substr($name, 10);
			}
		}

		sort($functions);
		foreach ($functions as $name)  echo "\t" . $name . "\n";

		echo "\n";
	}
?>