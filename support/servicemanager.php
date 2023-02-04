<?php
	// Service Manager interface PHP SDK.
	// (C) 2022 CubicleSoft.  All Rights Reserved.

	class ServiceManager
	{
		private $rootpath;

		public function __construct($rootpath)
		{
			$this->rootpath = str_replace(array("\\", "/"), DIRECTORY_SEPARATOR, $rootpath);
		}

		public function Install($servicename, $phpfile, $args, $options = array(), $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			if (!isset($options["dir"]))  $options["dir"] = $this->rootpath;
			foreach ($options as $key => $val)  $cmd .= " " . escapeshellarg("-" . $key . "=" . $val);
			$cmd .= " install " . escapeshellarg($servicename);
			$cmd .= " " . escapeshellarg($phpfile . ".notify");
			$cmd .= " " . escapeshellarg($this->GetPHPBinary());
			$cmd .= " " . escapeshellarg($phpfile);
			foreach ($args as $arg)  $cmd .= " " . escapeshellarg($arg);

			return $this->RunCommand($cmd, $display);
		}

		public function Uninstall($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " uninstall " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function Start($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " start " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function Stop($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " stop " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function Restart($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " restart " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function Reload($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " reload " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function WaitFor($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " waitfor " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function Status($servicename, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " status " . escapeshellarg($servicename);

			return $this->RunCommand($cmd, $display);
		}

		public function GetConfig($servicename)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " configfile " . escapeshellarg($servicename);

			$result = $this->RunCommand($cmd);
			if (!$result["success"])  return $result;

			$filename = trim($result["output"]);
			if (strtolower(substr($filename, 0, 6)) === "error:")  return array("success" => false, "error" => self::SMTranslate("Unable to locate the '%s' configuration file.", $servicename), "errorcode" => "missing_config", "info" => $result);

			$fp = fopen($filename, "rb");
			if ($fp === false)  return array("success" => false, "error" => self::SMTranslate("Unable to open the configuration file '%s' for reading.", $filename), "errorcode" => "fopen_failed");

			$result = array(
				"success" => true,
				"filename" => $filename,
				"options" => array()
			);

			while (($line = fgets($fp)) !== false)
			{
				$line = trim($line);

				$pos = strpos($line, "=");
				if ($pos !== false)
				{
					$key = substr($line, 0, $pos);
					$val = (string)substr($line, $pos + 1);

					if (!isset($result["options"][$key]))  $result["options"][$key] = $val;
				}

				if (feof($fp))  break;
			}

			fclose($fp);

			return $result;
		}

		public function AddAction($servicename, $actionname, $actiondesc, $phpfile, $args, $display = false)
		{
			$cmd = escapeshellarg($this->GetServiceManagerRealpath());
			$cmd .= " addaction " . escapeshellarg($servicename);
			$cmd .= " " . escapeshellarg($actionname);
			$cmd .= " " . escapeshellarg($actiondesc);
			$cmd .= " " . escapeshellarg($this->GetPHPBinary());
			$cmd .= " " . escapeshellarg($phpfile);
			foreach ($args as $arg)  $cmd .= " " . escapeshellarg($arg);

			return $this->RunCommand($cmd, $display);
		}

		public function GetServiceManagerRealpath()
		{
			$os = php_uname("s");

			if (strtoupper(substr($os, 0, 3)) == "WIN")  $result = $this->rootpath . "\\servicemanager.exe";
			else
			{
				if (file_exists($this->rootpath . "/servicemanager_nix"))  $result = $this->rootpath . "/servicemanager_nix";
				else if (file_exists("/usr/local/bin/servicemanager"))  $result = "/usr/local/bin/servicemanager";
				else if (strtoupper(substr($os, 0, 6)) == "DARWIN")  $result = $this->rootpath . "/servicemanager_mac";
				else if (PHP_INT_SIZE >= 8)  $result = $this->rootpath . "/servicemanager_nix_64";
				else  $result = $this->rootpath . "/servicemanager_nix_32";

				@chmod($result, 0755);
			}

			return $result;
		}

		public function GetPHPBinary()
		{
			if (file_exists("/usr/bin/php") && realpath("/usr/bin/php") === PHP_BINARY)  $result = "/usr/bin/php";
			else  $result = PHP_BINARY;

			return $result;
		}

		public function RunCommand($cmd, $display = false)
		{
			if ($display)  echo self::SMTranslate("Running command:  %s", $cmd) . "\n\n";

			$fp = popen($cmd, "rb");
			if ($fp === false)  return array("success" => false, "error" => self::SMTranslate("The executable failed to start."), "errorcode" => "start_process", "info" => $cmd);

			$data = "";
			while (($data2 = fread($fp, 65536)) !== false)
			{
				if ($display)  echo $data2;
				$data .= $data2;

				if (feof($fp))  break;
			}

			pclose($fp);

			if ($display)  echo "\n";

			return array("success" => true, "cmd" => $cmd, "output" => $data);
		}

		protected static function SMTranslate()
		{
			$args = func_get_args();
			if (!count($args))  return "";

			return call_user_func_array((defined("CS_TRANSLATE_FUNC") && function_exists(CS_TRANSLATE_FUNC) ? CS_TRANSLATE_FUNC : "sprintf"), $args);
		}
	}
?>