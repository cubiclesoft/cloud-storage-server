<?php
	// Cloud Storage Server user management tool.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/str_basics.php";
	require_once $rootpath . "/support/css_functions.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
			"s" => "suppressoutput",
			"?" => "help"
		),
		"rules" => array(
			"suppressoutput" => array("arg" => false),
			"help" => array("arg" => false)
		),
		"userinput" => "="
	);
	$args = CLI::ParseCommandLine($options);

	if (isset($args["opts"]["help"]))
	{
		echo "Cloud Storage Server management command-line tool\n";
		echo "Purpose:  Manage users in the users database.\n";
		echo "\n";
		echo "This tool is question/answer enabled.  Just running it will provide a guided interface.  It can also be run entirely from the command-line if you know all the answers.\n";
		echo "\n";
		echo "Syntax:  " . $args["file"] . " [options] [cmd [cmdoptions]]\n";
		echo "Options:\n";
		echo "\t-s   Suppress most output.  Useful for capturing JSON output.\n";
		echo "\n";
		echo "Examples:\n";
		echo "\tphp " . $args["file"] . "\n";
		echo "\tphp " . $args["file"] . " create username=test\n";
		echo "\tphp " . $args["file"] . " -s list\n";
		echo "\tphp " . $args["file"] . " -s get-info test\n";

		exit();
	}

	$suppressoutput = (isset($args["opts"]["suppressoutput"]) && $args["opts"]["suppressoutput"]);

	$config = CSS_LoadConfig();

	if (!isset($config["quota"]))  CSS_DisplayError("Configuration is incomplete or missing.  Run 'install.php' first.");

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

	// Let each server extension register event handler callbacks (e.g. "CSS_UserHelper::DeleteUser").
	// For most events, it is best to wait until later to do anything about changes (i.e. lazy updates).
	foreach ($serverexts as $serverext)  $serverext->RegisterHandlers($em);

	// Get the command.
	$cmds = array("list" => "List all users", "create" => "Add a new user", "update" => "Change a user's transfer limit and quota.", "add-ext" => "Add user access to an API extension", "remove-ext" => "Remove user access to an API extension", "get-info" => "Get detailed information about a user", "delete" => "Delete a user");

	$cmd = CLI::GetLimitedUserInputWithArgs($args, "cmd", "Command", false, "Available commands:", $cmds, true, $suppressoutput);

	function DisplayResult($result)
	{
		echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

		exit();
	}

	function FixUserRow($row)
	{
		$row->apikey .= "-" . $row->id;
		$row->totalbytes = (double)$row->totalbytes;
		$row->quota = (double)$row->quota;
		$row->transferstart = (int)$row->transferstart;
		$row->transferbytes = (double)$row->transferbytes;
		$row->transferlimit = (double)$row->transferlimit;
		$row->lastupdated = (int)$row->lastupdated;
		$row->quotaleft = CSS_GetQuotaLeft($row);
		$row->transferleft = CSS_GetTransferLeft($row);
	}

	function GetUserList()
	{
		global $db;

		$result = array("success" => true, "users" => array());

		$result2 = $db->Query("SELECT", array(
			"*",
			"FROM" => "?",
		), "users");

		while ($row = $result2->NextRow())
		{
			$row->serverexts = json_decode($row->serverexts, true);

			FixUserRow($row);

			$result["users"][$row->username] = (array)$row;
		}

		ksort($result["users"]);

		return $result;
	}

	function GetUser()
	{
		global $suppressoutput, $args, $userhelper;

		if ($suppressoutput || CLI::CanGetUserInputWithArgs($args, "username"))
		{
			$username = CLI::GetUserInputWithArgs($args, "username", "Username", false, "", $suppressoutput);

			$result = $userhelper->GetUserByUsername($username);
			if (!$result["success"])  DisplayResult($result);

			FixUserRow($result["info"]);

			$result["info"] = (array)$result["info"];
		}
		else
		{
			$result = GetUserList();
			if (!$result["success"])  DisplayResult($result);

			$users = array();
			foreach ($result["users"] as $username => $user)
			{
				$options = array();
				if (!count($user["serverexts"]))  $options[] = "No extensions enabled";
				else  $options[] = (count($user["serverexts"]) == 1 ? "1 extension" : count($user["serverexts"]) . " extensions") . " enabled (" . implode(", ", array_keys($user["serverexts"])) . ")";

				if ($user["quotaleft"] > -1)  $options[] = Str::ConvertBytesToUserStr($user["quotaleft"]) . " storage left";
				if ($user["transferleft"] > -1)  $options[] = Str::ConvertBytesToUserStr($user["transferleft"]) . " transfer left";

				$users[$username] = implode(", ", $options);
			}
			if (!count($users))  CLI::DisplayError("No users have been created.  Try creating your first user with the command:  create");
			$username = CLI::GetLimitedUserInputWithArgs($args, "username", "Username", false, "Available users:", $users, true, $suppressoutput);
			$result["info"] = $result["users"][$username];
			unset($result["users"]);
		}

		return $result;
	}

	if ($cmd === "list")
	{
		DisplayResult(GetUserList());
	}
	else if ($cmd === "create")
	{
		// Get the username of the new user.
		do
		{
			$username = CLI::GetUserInputWithArgs($args, "username", "Username", false, "", $suppressoutput);

			$result = $userhelper->GetUserByUsername($username);
			if ($result["success"])  CLI::DisplayError("A user with the username '" . $username . "' already exists.", false, false);
			else if ($result["errorcode"] !== "no_user")  CLI::DisplayError("An error occurred while attempting to check the database.", $result);
			else  break;
		} while (1);

		$options = array();
		$options["basepath"] = CLI::GetUserInputWithArgs($args, "basepath", "Base path", $config["basepath"], "", $suppressoutput);
		$options["quota"] = CSS_ConvertUserStrToBytes(CLI::GetUserInputWithArgs($args, "quota", "Quota", $config["quota"], "", $suppressoutput));
		$options["transferlimit"] = CSS_ConvertUserStrToBytes(CLI::GetUserInputWithArgs($args, "transferlimit", "Transfer limit", $config["transferlimit"], "", $suppressoutput));

		$result = $userhelper->CreateUser($username, $options);
		if (!$result["success"])  DisplayResult($result);

		FixUserRow($result["info"]);

		DisplayResult($result);
	}
	else if ($cmd === "update")
	{
		$result = GetUser();
		$id = $result["info"]["id"];

		$options = array();
		$options["quota"] = CSS_ConvertUserStrToBytes(CLI::GetUserInputWithArgs($args, "quota", "Quota", Str::ConvertBytesToUserStr($result["info"]["quota"]), "", $suppressoutput));
		$options["transferlimit"] = CSS_ConvertUserStrToBytes(CLI::GetUserInputWithArgs($args, "transferlimit", "Transfer limit", Str::ConvertBytesToUserStr($result["info"]["transferlimit"]), "", $suppressoutput));

		$result = $userhelper->UpdateUser($id, $options);
		if (!$result["success"])  DisplayResult($result);

		$result = $userhelper->GetUserByID($id);
		if (!$result["success"])  DisplayResult($result);

		FixUserRow($result["info"]);

		DisplayResult($result);
	}
	else if ($cmd === "add-ext")
	{
		$result = GetUser();
		$id = $result["info"]["id"];

		$extensions = array();
		foreach ($serverexts as $name => $serverext)  $extensions[$name] = "/" . $name;
		$extension = CLI::GetLimitedUserInputWithArgs($args, "extension", "Extension", false, "Available extensions:", $extensions, true, $suppressoutput);

		$result2 = $serverexts[$extension]->AddUserExtension((object)$result["info"]);
		if (!$result2["success"])  DisplayResult($result2);

		$options = array();
		$options["serverexts"] = $result["info"]["serverexts"];
		$options["serverexts"][$extension] = $result2["info"];

		$result = $userhelper->UpdateUser($id, $options);
		if (!$result["success"])  DisplayResult($result);

		$result = $userhelper->GetUserByID($id);
		if (!$result["success"])  DisplayResult($result);

		FixUserRow($result["info"]);

		DisplayResult($result);
	}
	else if ($cmd === "remove-ext")
	{
		$result = GetUser();
		$id = $result["info"]["id"];

		$extensions = array();
		foreach ($result["info"]["serverexts"] as $name => $info)  $extensions[$name] = "/" . $name . " - " . json_encode($info, JSON_UNESCAPED_SLASHES);
		if (!count($extensions))  CLI::DisplayError("No extensions have been enabled for the user.");
		$extension = CLI::GetLimitedUserInputWithArgs($args, "extension", "Extension", false, "Enabled extensions:", $extensions, true, $suppressoutput);

		$options = array();
		$options["serverexts"] = $result["info"]["serverexts"];
		unset($options["serverexts"][$extension]);

		$result = $userhelper->UpdateUser($id, $options);
		if (!$result["success"])  DisplayResult($result);

		$result = $userhelper->GetUserByID($id);
		if (!$result["success"])  DisplayResult($result);

		FixUserRow($result["info"]);

		DisplayResult($result);
	}
	else if ($cmd === "get-info")
	{
		DisplayResult(GetUser());
	}
	else if ($cmd === "delete")
	{
		$result = GetUser();
		$id = $result["info"]["id"];

		$result = $userhelper->DeleteUser($id);

		DisplayResult($result);
	}
?>