<?php
	// Cloud Storage Server user management tool.
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
	// For most events, it is best to wait until later to do anything about it (i.e. lazy updates).
	foreach ($serverexts as $serverext)  $serverext->RegisterHandlers($em);

	// Load all management functions.
	$dir = opendir($rootpath . "/manage_shell_exts");
	if ($dir !== false)
	{
		while (($file = readdir($dir)) !== false)
		{
			if (substr($file, -4) === ".php")  require_once $rootpath . "/manage_shell_exts/" . $file;
		}

		closedir($dir);
	}

	require_once $rootpath . "/support/cli.php";

	echo "Ready.  This is a command-line interface.  Enter 'help' to get a list of available commands.\n\n";

	echo ">";
	while (($line = fgets(STDIN)) !== false)
	{
		$line = trim($line);

		if ($line == "quit" || $line == "exit" || $line == "logout")  break;

		// Parse the command.
		$pos = strpos($line, " ");
		if ($pos === false)  $pos = strlen($line);
		$cmd = substr($line, 0, $pos);

		if ($cmd != "")
		{
			if (!function_exists("shell_cmd_" . $cmd))  echo "The shell command '" . $cmd . "' does not exist.\n";
			else
			{
				$cmd = "shell_cmd_" . $cmd;
				$cmd($line);
			}
		}

		echo ">";
	}
?>