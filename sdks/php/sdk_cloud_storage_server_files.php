<?php
	// Cloud Storage Server files SDK class.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	// Load dependency.
	if (!class_exists("CloudStorageServer_APIBase", false))  require_once str_replace("\\", "/", dirname(__FILE__)) . "/sdk_cloud_storage_server_api_base.php";

	// This class only supports the /files API.
	class CloudStorageServerFiles extends CloudStorageServer_APIBase
	{
		public function __construct()
		{
			parent::__contstruct();

			$this->apiprefix = "/files/v1";
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
	}
?>