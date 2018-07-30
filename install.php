<?php
	// Cloud storage server installation tool.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	if (!isset($_SERVER["argc"]) || !$_SERVER["argc"])
	{
		echo "This file is intended to be run from the command-line.";

		exit();
	}

	// Temporary root.
	$rootpath = str_replace("\\", "/", dirname(__FILE__));

	@mkdir($rootpath . "/data");
	@chmod($rootpath . "/data", 02775);

	// Legacy:  Move configuration information into data directory.
	@rename($rootpath . "/config.dat", $rootpath . "/data/config.dat");
	@rename($rootpath . "/cert.key", $rootpath . "/data/cert.key");
	@rename($rootpath . "/cert.pem", $rootpath . "/data/cert.pem");
	@rename($rootpath . "/main.db", $rootpath . "/data/main.db");

	require_once $rootpath . "/support/cli.php";
	require_once $rootpath . "/support/css_functions.php";

	// Process the command-line options.
	$options = array(
		"shortmap" => array(
		),
		"rules" => array(
		),
		"userinput" => "="
	);
	$args = CLI::ParseCommandLine($options);
	$suppressoutput = false;

	$config = CSS_LoadConfig();

	// Get the user the software will generally run as.
	if (function_exists("posix_geteuid"))
	{
		$uid = posix_geteuid();
		if ($uid !== 0)  CLI::DisplayError("The Cloud Storage Server installer must be run as the 'root' user (UID = 0) to install the system service on *NIX hosts.");
	}

	if (!isset($config["serviceuser"]))
	{
		if (!function_exists("posix_geteuid"))  $config["serviceuser"] = "";
		else
		{
			$serviceuser = CLI::GetUserInputWithArgs($args, "serviceuser", "System service user/group", "cloud-storage-server", "The next question asks what user the system service will run as.  Both a system user and group will be created unless 'root' is specified.", $suppressoutput);

			$config["serviceuser"] = $serviceuser;

			// Create the system user/group.
			if ($config["serviceuser"] !== "root")
			{
				ob_start();
				system("useradd -r -s /bin/false " . escapeshellarg($serviceuser));
				if ($suppressoutput)  ob_end_clean();
				else  ob_end_flush();
			}
		}

		CSS_SaveConfig($config);
	}

	// Create a certificate chain if it does not already exist.
	if (!file_exists($rootpath . "/data/cert.pem") || !file_exists($rootpath . "/data/cert.key"))
	{
		echo "Creating CA and server certificates... (this can take a while)\n";

		require_once $rootpath . "/support/phpseclib/Crypt/RSA.php";
		require_once $rootpath . "/support/phpseclib/Math/BigInteger.php";
		require_once $rootpath . "/support/phpseclib/File/X509.php";

		// Generate the CSR.
		echo "\tGenerating 4096 bit CA private key and CSR...\n";

		$rsa = new Crypt_RSA();
		$data = $rsa->createKey(4096);

		$ca_privatekey = new Crypt_RSA();
		$ca_privatekey->loadKey($data["privatekey"]);

		$ca_publickey = new Crypt_RSA();
		$ca_publickey->loadKey($data["publickey"]);

		$csr = new File_X509();
		$csr->setPrivateKey($ca_privatekey);
		$csr->setPublicKey($ca_publickey);

		// Use the specified commonName.
		$csr->removeDNProp("id-at-commonName");
		if (!$csr->setDNProp("id-at-commonName", "Class 1 Certificate Authority"))  CSS_DisplayError("Unable to set commonName (common name) in the CSR.");

		// Have to sign, save, and load the CSR to add extensions.
		$csr->loadCSR($csr->saveCSR($csr->signCSR("sha256WithRSAEncryption")));

		$keyusage2 = explode(",", "keyCertSign, cRLSign");
		foreach ($keyusage2 as $num => $val)  $keyusage2[$num] = trim($val);
		if (!$csr->setExtension("id-ce-keyUsage", $keyusage2))  CSS_DisplayError("Unable to set extension keyUsage in the CSR.");

		$domains2 = array();
		$domains2[] = array("dNSName" => "Class 1 Certificate Authority");
		if (!$csr->setExtension("id-ce-subjectAltName", $domains2))  CSS_DisplayError("Unable to set extension subjectAltName in the CSR.");

		// Sign and save the CSR.
		$ca_csr = $csr->saveCSR($csr->signCSR("sha256WithRSAEncryption"));

		// Generate the certificate.
		echo "\tGenerating CA certificate...\n";

		$issuer = new File_X509();
		$issuer->loadCSR($ca_csr);
		$issuer->setPrivateKey($ca_privatekey);
		if ($issuer->validateSignature() !== true)  CSS_DisplayError("Unable to validate the CSR's signature.");

		$subject = new File_X509();
		$subject->loadCSR($ca_csr);
		if ($subject->validateSignature() !== true)  CSS_DisplayError("Unable to validate the CSR's signature.");

		$certsigner = new File_X509();
		$certsigner->makeCA();
		$certsigner->setStartDate("-1 day");
		$certsigner->setEndDate("+3650 day");
		$certsigner->setSerialNumber("1", 10);

		$signed = $certsigner->sign($issuer, $subject, "sha256WithRSAEncryption");
		if ($signed === false)  CSS_DisplayError("Unable to self-sign CSR.");
		$ca_cert = $certsigner->saveX509($signed);


		echo "\tGenerating 4096 bit server private key and CSR...\n";

		$rsa = new Crypt_RSA();
		$data = $rsa->createKey(4096);

		$server_privatekey = new Crypt_RSA();
		$server_privatekey->loadKey($data["privatekey"]);

		$server_publickey = new Crypt_RSA();
		$server_publickey->loadKey($data["publickey"]);

		$csr = new File_X509();
		$csr->setPrivateKey($server_privatekey);
		$csr->setPublicKey($server_publickey);

		// Use the specified commonName.
		$csr->removeDNProp("id-at-commonName");
		if (!$csr->setDNProp("id-at-commonName", "Cloud Storage Server"))  CSS_DisplayError("Unable to set commonName (common name) in the CSR.");

		// Have to sign, save, and load the CSR to add extensions.
		$csr->loadCSR($csr->saveCSR($csr->signCSR("sha256WithRSAEncryption")));

		$keyusage2 = explode(",", "digitalSignature, keyEncipherment, keyAgreement");
		foreach ($keyusage2 as $num => $val)  $keyusage2[$num] = trim($val);
		if (!$csr->setExtension("id-ce-keyUsage", $keyusage2))  CSS_DisplayError("Unable to set extension keyUsage in the CSR.");

		$domains2 = array();
		$domains2[] = array("dNSName" => "Cloud Storage Server");
		if (!$csr->setExtension("id-ce-subjectAltName", $domains2))  CSS_DisplayError("Unable to set extension subjectAltName in the CSR.");

		// Sign and save the CSR.
		$server_csr = $csr->saveCSR($csr->signCSR("sha256WithRSAEncryption"));

		// Generate the certificate.
		echo "\tGenerating server certificate...\n";

		$issuer = new File_X509();
		$issuer->loadX509($ca_cert);
		$issuer->setPrivateKey($ca_privatekey);

		$subject = new File_X509();
		$subject->loadCSR($server_csr);
		if ($subject->validateSignature() !== true)  CSS_DisplayError("Unable to validate the CSR's signature.");

		$certsigner = new File_X509();
		$certsigner->setStartDate("-1 day");
		$certsigner->setEndDate("+3650 day");
		$certsigner->setSerialNumber("2", 10);

		$signed = $certsigner->sign($issuer, $subject, "sha256WithRSAEncryption");
		if ($signed === false)  CSS_DisplayError("Unable to self-sign CSR.");
		$server_cert = $certsigner->saveX509($signed);

		file_put_contents($rootpath . "/data/cert.pem", $server_cert . "\n" . $ca_cert);
		file_put_contents($rootpath . "/data/cert.key", $server_privatekey->getPrivateKey());
		@chmod($rootpath . "/data/cert.key", 0600);

		echo "\tDone.\n\n";
	}

	if (!isset($config["sslopts"]))  $config["sslopts"] = array();
	if ((!isset($config["sslopts"]["local_cert"]) || !file_exists($config["sslopts"]["local_cert"])) && file_exists($rootpath . "/data/cert.pem"))  $config["sslopts"]["local_cert"] = $rootpath . "/data/cert.pem";
	if ((!isset($config["sslopts"]["local_pk"]) || !file_exists($config["sslopts"]["local_pk"])) && file_exists($rootpath . "/data/cert.key"))  $config["sslopts"]["local_pk"] = $rootpath . "/data/cert.key";

	CSS_SaveConfig($config);

	// Allow the user to read various files/directories.
	if ($config["serviceuser"] !== "")
	{
		@chown($rootpath . "/data", $config["serviceuser"]);
		@chown($rootpath . "/data/cert.key", $config["serviceuser"]);
		@chown($rootpath . "/data/config.dat", $config["serviceuser"]);
	}

	if (!isset($config["host"]))
	{
		$host = CLI::GetUserInputWithArgs($args, "remotedapi", "Remoted API server URL (leave blank unless you have one)", "", "The next question asks for a Remoted API server URL.  If you are connecting this Cloud Storage Server installation to Remoted API server, then enter the URL for it.  Otherwise, leave it blank.", $suppressoutput);
		if ($host !== "")
		{
			$config["host"] = $host;
			$config["port"] = 0;
			$config["addlocalhost"] = false;
		}
		else
		{
			$ipv6 = CLI::GetYesNoUserInputWithArgs($args, "ipv6", "IPv6", "N", "", $suppressoutput);
			$localhost = CLI::GetYesNoUserInputWithArgs($args, "localhostonly", "Localhost only and no SSL", "Y", "", $suppressoutput);

			$config["host"] = ($ipv6 ? ($localhost ? "[::1]" : "[::0]") : ($localhost ? "127.0.0.1" : "0.0.0.0"));
			if ($localhost)  $config["addlocalhost"] = false;
		}

		CSS_SaveConfig($config);
	}

	if (!isset($config["publichost"]))
	{
		$publichost = CLI::GetUserInputWithArgs($args, "publichost", "Hostname", "localhost", "The next question asks for the hostname from a public perspective such as a domain name.  Only used to construct base URLs for use with SDKs.", $suppressoutput);
		$config["publichost"] = $publichost;

		CSS_SaveConfig($config);
	}

	if (!isset($config["port"]))
	{
		$port = (int)CLI::GetUserInputWithArgs($args, "port", "Port", "9892", "The next question asks for the TCP/IP port to run the server on.  If this will be an internal localhost-only server, use another port like 9893.", $suppressoutput);

		if ($port < 0 || $port > 65535)  $port = 9892;
		$config["port"] = $port;

		CSS_SaveConfig($config);
	}

	if (!isset($config["addlocalhost"]))
	{
		$addlocalhost = CLI::GetYesNoUserInputWithArgs($args, "addlocalhost", "Add localhost only server without SSL at port " . ($config["port"] + 1), "Y", "", $suppressoutput);
		$config["addlocalhost"] = $addlocalhost;

		CSS_SaveConfig($config);
	}

	if (!isset($config["basepath"]))
	{
		$basepath = CLI::GetUserInputWithArgs($args, "basepath", "Default user base path", $rootpath . "/users", "The next question asks for the base path where user files will be stored.", $suppressoutput);
		$config["basepath"] = $basepath;

		CSS_SaveConfig($config);
	}

	// Adjust base path permissions.
	@mkdir($config["basepath"]);
	@chmod($config["basepath"], 02770);
	if ($config["serviceuser"] !== "")  @chown($config["basepath"], $config["serviceuser"]);

	if (!isset($config["transferlimit"]))
	{
		$transferlimit = CLI::GetUserInputWithArgs($args, "transferlimit", "Default daily user transfer limit (in bytes, KB, MB, GB, or TB; -1 for unlimited transfer)", "-1", "", $suppressoutput);
		$config["transferlimit"] = $transferlimit;

		CSS_SaveConfig($config);
	}

	if (!isset($config["quota"]))
	{
		$quota = CLI::GetUserInputWithArgs($args, "quota", "Default user quota (in bytes, KB, MB, GB, or TB; -1 for unlimited storage)", "-1", "", $suppressoutput);
		$config["quota"] = $quota;

		CSS_SaveConfig($config);
	}

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

	// Create database tables.
	if (!$db->TableExists("users"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("users", array(
				"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
				"apikey" => array("STRING", 1, 255, "NOT NULL" => true),
				"username" => array("STRING", 1, 255, "NOT NULL" => true),
				"serverexts" => array("STRING", 2, "NOT NULL" => true),
				"basepath" => array("STRING", 2, "NOT NULL" => true),
				"totalbytes" => array("INTEGER", 8, "NOT NULL" => true),
				"quota" => array("INTEGER", 8, "NOT NULL" => true),
				"transferstart" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"transferbytes" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"transferlimit" => array("INTEGER", 8, "NOT NULL" => true),
				"lastupdated" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
			),
			array(
				array("KEY", array("username"), "NAME" => "users_username"),
			)));
		}
		catch (Exception $e)
		{
			CSS_DisplayError("Unable to create the database table 'users'.  " . $e->getMessage());
		}
	}

	if (!$db->TableExists("guests"))
	{
		try
		{
			$db->Query("CREATE TABLE", array("guests", array(
				"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
				"uid" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"apikey" => array("STRING", 1, 255, "NOT NULL" => true),
				"serverexts" => array("STRING", 2, "NOT NULL" => true),
				"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
				"expires" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
			),
			array(
				array("KEY", array("uid", "apikey"), "NAME" => "guests_uidapikey"),
			)));
		}
		catch (Exception $e)
		{
			CSS_DisplayError("Unable to create the database table 'users'.  " . $e->getMessage());
		}
	}

	// Load all server extensions.
	$serverexts = CSS_LoadServerExtensions();

	// Run the installer portion of each server extension.
	@mkdir($rootpath . "/user_init");
	@chmod($rootpath . "/user_init", 02755);
	if ($config["serviceuser"] !== "")  @chown($rootpath . "/user_init", $config["serviceuser"]);
	foreach ($serverexts as $serverext)  $serverext->Install();

	$db->Disconnect();

	// Fix the database permissions.
	@chmod($rootpath . "/data/main.db", 0660);
	if ($config["serviceuser"] !== "")  @chown($rootpath . "/data/main.db", $config["serviceuser"]);

	echo "\n";
	echo "**********\n";
	echo "Configuration file is located at '" . $rootpath . "/config.dat'.  It can be manually edited.\n\n";
	echo "Now you can run 'manage.php' to setup and manage users.  Run 'server.php' to start the server.\n";
	echo "**********\n\n";


	if (!isset($config["servicename"]))
	{
		$servicename = CLI::GetUserInputWithArgs($args, "servicename", "System service name", "cloud-storage-server", "The next question asks what the name of the system service will be.  Enter a single hyphen '-' to not install this software as a system service at this time.", $suppressoutput);

		if ($servicename !== "-")
		{
			$config["servicename"] = $servicename;

			CSS_SaveConfig($config);

			// Install and start 'server.php' as a system service.
			if (!$suppressoutput)  echo "\nInstalling system service...\n";
			ob_start();
			system(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " install");
			system(escapeshellarg(PHP_BINARY) . " " . escapeshellarg($rootpath . "/server.php") . " start");
			echo "\n\n";
			if ($suppressoutput)  ob_end_clean();
			else  ob_end_flush();
		}
	}

	echo "Done.\n";
?>