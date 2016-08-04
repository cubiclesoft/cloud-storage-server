<?php
	// Cloud storage server installation tool.
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

	// Create a certificate chain if it does not already exist.
	if (!file_exists($rootpath . "/cert.pem") || !file_exists($rootpath . "/cert.key"))
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

		file_put_contents($rootpath . "/cert.pem", $server_cert . "\n" . $ca_cert);
		file_put_contents($rootpath . "/cert.key", $server_privatekey->getPrivateKey());
		@chmod($rootpath . "/cert.key", 0600);

		echo "\tDone.\n\n";
	}

	if (!isset($config["sslopts"]))  $config["sslopts"] = array();
	if (!isset($config["sslopts"]["local_cert"]) && file_exists($rootpath . "/cert.pem"))  $config["sslopts"]["local_cert"] = $rootpath . "/cert.pem";
	if (!isset($config["sslopts"]["local_pk"]) && file_exists($rootpath . "/cert.key"))  $config["sslopts"]["local_pk"] = $rootpath . "/cert.key";

	CSS_SaveConfig($config);

	if (!isset($config["host"]))
	{
		echo "IPv6 (Y/N):  ";
		$ipv6 = (substr(strtoupper(trim(fgets(STDIN))), 0, 1) == "Y");
		$config["host"] = ($ipv6 ? "[::0]" : "0.0.0.0");

		CSS_SaveConfig($config);
	}

	if (!isset($config["publichost"]))
	{
		echo "Hostname (from the public perspective - e.g. something.com):  ";
		$publichost = trim(fgets(STDIN));
		$config["publichost"] = $publichost;

		CSS_SaveConfig($config);
	}

	if (!isset($config["port"]))
	{
		echo "Port (leave blank for the default - 9892):  ";
		$port = trim(fgets(STDIN));
		if ($port === "")  $port = 9892;
		$port = (int)$port;
		if ($port < 0 || $port > 65535)  $port = 9892;
		$config["port"] = $port;

		CSS_SaveConfig($config);
	}

	if (!isset($config["basepath"]))
	{
		echo "Default user base path (where files will be stored):  ";
		$path = trim(fgets(STDIN));
		$config["basepath"] = $path;

		CSS_SaveConfig($config);
	}

	if (!isset($config["transferlimit"]))
	{
		echo "Default daily user transfer limit (in bytes, KB, MB, GB, or TB; -1 for unlimited transfer):  ";
		$transferlimit = trim(fgets(STDIN));
		$config["transferlimit"] = $transferlimit;

		CSS_SaveConfig($config);
	}

	if (!isset($config["quota"]))
	{
		echo "Default user quota (in bytes, KB, MB, GB, or TB; -1 for unlimited storage):  ";
		$quota = trim(fgets(STDIN));
		$config["quota"] = $quota;

		CSS_SaveConfig($config);
	}

	require_once $rootpath . "/support/db.php";
	require_once $rootpath . "/support/db_sqlite.php";

	$db = new CSDB_sqlite();

	try
	{
		$db->Connect("sqlite:" . $rootpath . "/main.db");
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
	foreach ($serverexts as $serverext)  $serverext->Install();

	$db->Disconnect();

	echo "\n";
	echo "**********\n";
	echo "Configuration file is located at '" . $rootpath . "/config.dat'.  It can be manually edited.\n\n";
	echo "Now you can run 'manage.php' to setup and manage users.  Run 'server.php' to start the server.\n";
	echo "**********\n\n";

	echo "Done.\n";
?>