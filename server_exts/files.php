<?php
	// Cloud Storage Server files extension.
	// (C) 2018 CubicleSoft.  All Rights Reserved.

	class CSS_Extension_files
	{
		public function Install()
		{
			global $rootpath, $config, $args, $suppressoutput;

			@mkdir($rootpath . "/user_init/files", 0770, true);
			@chmod($rootpath . "/user_init/files", 02770);
			if ($config["serviceuser"] !== "")  @chown($rootpath . "/user_init/files", $config["serviceuser"]);

			if (!isset($config["ext_file_uploadlimit"]))
			{
				$limit = CLI::GetUserInputWithArgs($args, "ext_files_uploadlimit", "[Files Ext] Default file upload limit (in bytes, KB, MB, GB, or TB; -1 for unlimited transfer)", "-1", "", $suppressoutput);
				$config["ext_file_uploadlimit"] = $limit;

				CSS_SaveConfig($config);
			}
		}

		public function AddUserExtension($userrow)
		{
			global $args, $suppressoutput;

			$read = CLI::GetYesNoUserInputWithArgs($args, "ext_read", "[Files Ext] Allow file download access", "Y", "", $suppressoutput);
			$write = CLI::GetYesNoUserInputWithArgs($args, "ext_write", "[Files Ext] Allow folder write, file upload, trash access", "Y", "", $suppressoutput);
			$delete = CLI::GetYesNoUserInputWithArgs($args, "ext_delete", "[Files Ext] Allow permanent folder and file delete access", "Y", "", $suppressoutput);
			$guests = CLI::GetYesNoUserInputWithArgs($args, "ext_guest", "[Files Ext] Allow guest creation/deletion", "Y", "", $suppressoutput);

			return array("success" => true, "info" => array("read" => $read, "write" => $write, "delete" => $delete, "guests" => $guests));
		}

		public function RegisterHandlers($em)
		{
		}

		public function InitServer()
		{
			global $config;

			$config["ext_file_uploadlimit"] = CSS_ConvertUserStrToBytes($config["ext_file_uploadlimit"]);
		}

		public function UpdateStreamsAndTimeout($prefix, &$timeout, &$readfps, &$writefps)
		{
		}

		public function HTTPPreProcessAPI($reqmethod, $pathparts, $client, $userrow, $guestrow)
		{
			global $config;

			// POST /files/v1/file/upload
			if ($reqmethod === "POST" && count($pathparts) > 4 && $pathparts[3] === "file" && $pathparts[4] === "upload" && $userrow->serverexts["files"]["write"] && ($guestrow === false || $guestrow->serverexts["files"]["write"]))
			{
				// Adjust recvlimit for the current request based on the quota limit for the user and the global per-upload limit.
				CSS_AdjustRecvLimit($client, CSS_GetMinAmountLeft(array(CSS_GetQuotaLeft($userrow), CSS_GetTransferLeft($userrow), $config["ext_file_uploadlimit"])));
			}
		}

		public static function InitUserFilesBasePath($userrow)
		{
			$basedir = $userrow->basepath . "/" . $userrow->id . "/files";
			@mkdir($basedir, 0770, true);

			return $basedir;
		}

		public static function GetUserFilesDB($basedir)
		{
			$filename = $basedir . "/main.db";

			// Only ProcessAPI() should create the database.
			if (!file_exists($filename))  return array("success" => false, "error" => "The database '" . $filename . "' does not exist.", "errorcode" => "db_not_found");

			$filesdb = new CSDB_sqlite();

			try
			{
				$filesdb->Connect("sqlite:" . $filename);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "The database failed to open.", "errorcode" => "db_open_error");
			}

			return array("success" => true, "db" => $filesdb);
		}

		// Does not test for the existence of another item by the same name first.
		private static function CreateDBFolder($filesdb, $pid, $name)
		{
			try
			{
				$filesdb->Query("INSERT", array("files", array(
					"pid" => $pid,
					"name" => $name,
					"type" => "folder",
					"status" => "",
					"size" => 0,
					"created" => time()
				), "AUTO INCREMENT" => "id"));

				$id = $filesdb->GetInsertID();

				return array("success" => true, "id" => $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while creating a folder.", "errorcode" => "db_query_error");
			}
		}

		public static function GetDBIDFromPath($filesdb, $filespath, $createmissing, $rootid = "0")
		{
			try
			{
				$id = $rootid;
				$type = "";
				$path = str_replace("\\", "/", $filespath);
				if ($id !== "0")  $path = ltrim($path, "/");
				$path = explode("/", $path);
				$y = count($path);
				for ($x = 0; $x < $y; $x++)
				{
					$part = $path[$x];

					if ($part === "." || $part === ".." || ($part === "" && $x))  continue;

					$row = $filesdb->GetRow("SELECT", array(
						"id, type",
						"FROM" => "?",
						"WHERE" => "pid = ? AND name = ? AND status = ''",
					), "files", $id, $part);

					if ($row)
					{
						$id = $row->id;
						$type = $row->type;
					}
					else
					{
						if (!$createmissing)  break;

						if ($type === "file")  return array("success" => false, "error" => "A regular file is included in the requested path hierarchy.  Unable to create new directory.", "errorcode" => "file_in_path");

						$result = self::CreateDBFolder($filesdb, $id, $part);
						if (!$result["success"])  return $result;

						$id = $result["id"];
						$type = "folder";
					}
				}

				$path = array_slice($path, $x);

				return array("success" => true, "id" => $id, "type" => $type, "remaining" => $path);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while retrieving an ID.", "errorcode" => "db_query_error");
			}
		}

		private static function GetFilePath($basedir, $id)
		{
			return $basedir . "/" . implode("/", str_split(substr(hash("crc32b", $id), 0, 6), 2));
		}

		private static function CopyExternalFileToStorage(&$bytesdiff, $srcfile, $basedir, $filesdb, $pid, $name)
		{
			try
			{
				if ($name === "")  return array("success" => false, "error" => "The specified filename is empty.", "errorcode" => "empty_name");
				if (strlen($name) > 255)  return array("success" => false, "error" => "The specified filename is too long.", "errorcode" => "name_too_long");

				$size = HTTP::RawFileSize($srcfile);

				// Try to find an existing file.
				$row = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "pid = ? AND name = ? AND status = ''",
				), "files", $pid, $name);

				if ($row)
				{
					if ($row->type !== "file")  return array("success" => false, "error" => "The specified name '" . $name . "' is a directory.", "errorcode" => "already_exists");

					$id = $row->id;

					$filesdb->Query("UPDATE", array("files", array(
						"size" => $size,
						"created" => time()
					), "WHERE" => "id = ?"), $id);

					$bytesdiff += $size - $row->size;
				}
				else
				{
					$filesdb->Query("INSERT", array("files", array(
						"pid" => $pid,
						"name" => $name,
						"type" => "file",
						"status" => "",
						"size" => $size,
						"created" => time()
					), "AUTO INCREMENT" => "id"));

					$id = $filesdb->GetInsertID();

					$bytesdiff += $size;
				}

				// Calculate real file system location.
				$path = self::GetFilePath($basedir, $id);
				@mkdir($path, 0770, true);

				$result = @copy($srcfile, $path . "/" . $id . ".dat");
				if ($result === false)  return array("success" => false, "error" => "Unable to copy file to destination.", "errorcode" => "copy_failed");

				return array("success" => true, "id" => $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while creating/updating a file.", "errorcode" => "db_query_error");
			}
		}

		private static function CopyExternalFilesToStorageByID(&$bytesdiff, $srcpath, $basedir, $filesdb, $pid)
		{
			$dir = @opendir($srcpath);
			if ($dir === false)  return array("success" => false, "error" => "Unable to open the directory '" . $srcpath . "' for reading.", "errorcode" => "opendir_failed");

			while (($file = readdir($dir)) !== false)
			{
				if ($file !== "." && $file !== "..")
				{
					$filename = $srcpath . "/" . $file;
					if (is_dir($filename))
					{
						$row2 = $filesdb->GetRow("SELECT", array(
							"id",
							"FROM" => "?",
							"WHERE" => "pid = ? AND name = ? AND status = ''"
						), "files", $pid, $file);

						if ($row2)  $id = $row2->id;
						else
						{
							$result = self::CreateDBFolder($filesdb, $pid, $file);
							if (!$result["success"])
							{
								closedir($dir);

								return $result;
							}

							$id = $result["id"];
						}

						$result = self::CopyExternalFilesToStorageByID($bytesdiff, $filename, $basedir, $filesdb, $id);
						if (!$result["success"])
						{
							closedir($dir);

							return $result;
						}
					}
					else
					{
						$result = self::CopyExternalFileToStorage($bytesdiff, $filename, $basedir, $filesdb, $pid, $file);
						if (!$result["success"])
						{
							closedir($dir);

							return $result;
						}
					}
				}
			}

			closedir($dir);

			return array("success" => true);
		}

		public static function CopyExternalFilesToStorage(&$bytesdiff, $srcdirfile, $basedir, $filesdb, $filespath)
		{
			if (!file_exists($srcdirfile))  return array("success" => false, "error" => "Source directory or file does not exist.", "errorcode" => "file_not_found");
			if (!file_exists($basedir))  return array("success" => false, "error" => "Destination storage base directory does not exist.", "errorcode" => "basedir_not_found");

			$result = self::GetDBIDFromPath($filesdb, $filespath, true);
			if (!$result["success"])  return $result;
			if ($result["type"] === "file")  return array("success" => false, "error" => "A regular file was specified in the target path.", "errorcode" => "file_in_path");

			$srcdirfile = str_replace("\\", "/", $srcdirfile);
			if (is_dir($srcdirfile))  return self::CopyExternalFilesToStorageByID($bytesdiff, $srcdirfile, $basedir, $filesdb, $result["id"]);

			$pos = strrpos($srcdirfile, "/");
			$name = ($pos === false ? $srcdirfile : substr($srcdirfile, $pos + 1));

			return self::CopyExternalFileToStorage($bytesdiff, $srcdirfile, $basedir, $filesdb, $result["id"], $name);
		}

		// Does not catch exceptions.
		private static function IsParentOfID($filesdb, $parentid, $id)
		{
			while ($parentid !== $id && $id !== "0")
			{
				$row = $filesdb->GetRow("SELECT", array(
					"pid",
					"FROM" => "?",
					"WHERE" => "id = ?",
				), "files", $id);

				if (!$row)  break;

				$id = $row->pid;
			}

			return ($parentid === $id);
		}

		private static function CopyStorageFileToStorage(&$bytesdiff, $basedir, $filesdb, $srcrow, $destrow)
		{
			try
			{
				if ($srcrow->type !== "file")  return array("success" => false, "error" => "Source object is not a file.", "errorcode" => "source_not_a_file");

				if ($destrow->type === "file")  $row = $destrow;
				else
				{
					// Try to find an existing file.
					$row = $filesdb->GetRow("SELECT", array(
						"*",
						"FROM" => "?",
						"WHERE" => "pid = ? AND name = ? AND status = ''",
					), "files", $destrow->id, $srcrow->name);
				}

				if ($row)
				{
					if ($row->type !== "file")  return array("success" => false, "error" => "Expected a file.  The specified name '" . $name . "' is a directory.", "errorcode" => "already_exists");

					$id = $row->id;

					$filesdb->Query("UPDATE", array("files", array(
						"name" => $srcrow->name,
						"status" => $srcrow->status,
						"size" => $srcrow->size,
						"created" => $srcrow->created
					), "WHERE" => "id = ?"), $id);

					// Calculate existing file system location.
					$path = self::GetFilePath($basedir, $id);

					@unlink($path . "/" . $id . ".dat");

					$bytesdiff += $srcrow->size - $row->size;
				}
				else
				{
					$filesdb->Query("INSERT", array("files", array(
						"pid" => $destrow->id,
						"name" => $srcrow->name,
						"type" => "file",
						"status" => $srcrow->status,
						"size" => $srcrow->size,
						"created" => $srcrow->created
					), "AUTO INCREMENT" => "id"));

					$id = $filesdb->GetInsertID();

					$bytesdiff += $srcrow->size;
				}

				// Calculate real file system locations.
				$srcpath = self::GetFilePath($basedir, $srcrow->id);
				$destpath = self::GetFilePath($basedir, $id);
				@mkdir($destpath, 0770, true);

				$result = @copy($srcpath . "/" . $srcrow->id . ".dat", $destpath . "/" . $id . ".dat");
				if ($result === false)  return array("success" => false, "error" => "Unable to copy file to destination.", "errorcode" => "copy_failed");

				return array("success" => true, "id" => $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while copying information.", "errorcode" => "db_query_error");
			}
		}

		private static function CopyStorageFolderToStorage(&$bytesdiff, $basedir, $filesdb, $srcrow, $destrow)
		{
			try
			{
				$result = $filesdb->Query("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "pid = ? AND status = ''"
				), "files", $srcrow->id);

				while ($row = $result->NextRow())
				{
					if ($row->type === "file")  $result2 = self::CopyStorageFileToStorage($bytesdiff, $basedir, $filesdb, $row, $destrow);
					else
					{
						$row2 = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "pid = ? AND name = ? AND status = ''"
						), "files", $destrow->id, $row->name);

						if (!$row2)
						{
							$result2 = self::CreateDBFolder($filesdb, $destrow->id, $row->name);
							if (!$result2["success"])  return $result2;

							$row2 = $filesdb->GetRow("SELECT", array(
								"*",
								"FROM" => "?",
								"WHERE" => "pid = ? AND name = ? AND status = ''"
							), "files", $destrow->id, $row->name);
						}
						else if ($row2->type === "file")
						{
							return array("success" => false, "error" => "Expected a directory.  The specified name '" . $row->name . "' is a file in directory " . $destrow->id . ".", "errorcode" => "already_exists");
						}

						$result2 = self::CopyStorageFolderToStorage($bytesdiff, $basedir, $filesdb, $row, $row2);
					}

					if (!$result2["success"])  return $result2;
				}

				return array("success" => true);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while copying information.", "errorcode" => "db_query_error");
			}
		}

		public static function CopyStorageToStorage(&$bytesdiff, $basedir, $filesdb, $srcid, $destid)
		{
			try
			{
				if (self::IsParentOfID($filesdb, $srcid, $destid))  return array("success" => false, "error" => "Source object is a parent of the destination.", "errorcode" => "hierarchy_violation");

				$srcrow = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ? AND status = ''"
				), "files", $srcid);

				if (!$srcrow)  return array("success" => false, "error" => "Source object not found.", "errorcode" => "object_not_found");

				if ($srcrow->pid === $destid)  return array("success" => true, "id" => $srcrow->id);
				if ($srcrow->type === "system")  return array("success" => false, "error" => "Copying system objects is not allowed.", "errorcode" => "cannot_copy_system_objects");

				$destrow = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ? AND status = ''"
				), "files", $destid);

				if (!$destrow)  return array("success" => false, "error" => "Destination object not found.", "errorcode" => "object_not_found");

				$filesdb->BeginTransaction();

				if ($srcrow->type === "file" || $destrow->type === "file")  $result = self::CopyStorageFileToStorage($bytesdiff, $basedir, $filesdb, $srcrow, $destrow);
				else
				{
					// Create a directory inside the destination of the same name as the source.
					$result = self::GetDBIDFromPath($filesdb, "/" . $srcrow->name, true, $destrow->id);
					if ($result["success"])
					{
						$destrow = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ? AND status = ''"
						), "files", $result["id"]);

						if (!$destrow)  $result = array("success" => false, "error" => "Destination object not found.", "errorcode" => "object_not_found");
						else
						{
							$result = self::CopyStorageFolderToStorage($bytesdiff, $basedir, $filesdb, $srcrow, $destrow);
							if ($result["success"])  $result["id"] = $destrow->id;
						}
					}
				}

				$filesdb->Commit();

				return $result;
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while retrieving information.", "errorcode" => "db_query_error");
			}
		}

		public static function MoveStorageToStorage($basedir, $filesdb, $srcid, $destid)
		{
			try
			{
				if (self::IsParentOfID($filesdb, $srcid, $destid))  return array("success" => false, "error" => "Source object is a parent of the destination.", "errorcode" => "hierarchy_violation");

				$srcrow = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ? AND status = ''"
				), "files", $srcid);

				if (!$srcrow)  return array("success" => false, "error" => "Source object not found.", "errorcode" => "object_not_found");

				if ($srcrow->type === "system")  return array("success" => false, "error" => "Moving system objects is not allowed.", "errorcode" => "cannot_move_system_objects");
				if ($srcrow->status !== "")  return array("success" => false, "error" => "Moving non-normal/trashed objects is not allowed.", "errorcode" => "cannot_move_trashed_objects");

				$destrow = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ? AND status = ''"
				), "files", $destid);

				if (!$destrow)  return array("success" => false, "error" => "Destination object not found.", "errorcode" => "object_not_found");

				if ($destrow->type === "file")  return array("success" => false, "error" => "Destination is a file and already exists.", "errorcode" => "destination_exists");

				$row = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "pid = ? AND name = ? AND status = ''"
				), "files", $destrow->id, $srcrow->name);

				if ($row)  return array("success" => false, "error" => "Destination already exists.", "errorcode" => "destination_exists");

				$filesdb->Query("UPDATE", array("files", array(
					"pid" => $destrow->id,
				), "WHERE" => "id = ?"), $srcrow->id);

				$srcrow->pid = $destrow->id;

				return array("success" => true, "object" => (array)$srcrow);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while retrieving information.", "errorcode" => "db_query_error");
			}
		}

		public static function RenameStorageObject($filesdb, $id, $name)
		{
			// Only allow renames to take place in the same parent directory.
			$name = str_replace(array("\\", "/"), "", $name);

			try
			{
				$filesdb->BeginTransaction();

				$row = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ? AND status = ''",
				), "files", $id);

				if (!$row)
				{
					$filesdb->Rollback();

					return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");
				}

				$row2 = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "pid = ? AND name = ? AND status = ''",
				), "files", $row->pid, $name);

				if ($row2)
				{
					$filesdb->Rollback();

					return array("success" => false, "error" => "The specified name already exists.", "errorcode" => "already_exists");
				}

				$filesdb->Query("UPDATE", array("files", array(
					"name" => $name,
				), "WHERE" => "id = ?"), $row->id);

				$filesdb->Commit();

				$row->name = $name;

				return array("success" => true, "object" => (array)$row);
			}
			catch (Exception $e)
			{
				$filesdb->Rollback();

				return array("success" => false, "error" => "A database query failed while retrieving or updating the object.", "errorcode" => "db_query_error");
			}
		}

		public static function TrashStorageObject($filesdb, $id)
		{
			try
			{
				$row = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ?",
				), "files", $id);

				if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");
				if ($row->type === "system")  return array("success" => false, "error" => "Trashing system objects is not allowed.", "errorcode" => "cannot_trash_system_objects");

				$filesdb->Query("UPDATE", array("files", array(
					"status" => "trash",
				), "WHERE" => "id = ?"), $row->id);

				$row->status = "trash";

				return array("success" => true, "object" => (array)$row);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while retrieving or updating the object.", "errorcode" => "db_query_error");
			}
		}

		public static function RestoreStorageObject($filesdb, $id)
		{
			try
			{
				$filesdb->BeginTransaction();

				$row = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ?",
				), "files", $id);

				if (!$row)
				{
					$filesdb->Rollback();

					return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");
				}

				if ($row->status !== "trash")
				{
					$filesdb->Rollback();

					return array("success" => false, "error" => "Object is not in the trash.", "errorcode" => "object_not_in_trash");
				}

				$row2 = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "pid = ? AND name = ? AND status = ''",
				), "files", $row->pid, $row->name);

				if ($row2)
				{
					$filesdb->Rollback();

					return array("success" => false, "error" => "An object not in the trash already exists with the same name.", "errorcode" => "already_exists");
				}

				$filesdb->Query("UPDATE", array("files", array(
					"status" => "",
				), "WHERE" => "id = ?"), $row->id);

				$filesdb->Commit();

				$row->status = "";

				return array("success" => true, "object" => (array)$row);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while retrieving or updating the object.", "errorcode" => "db_query_error");
			}
		}

		public static function DeleteFromStorage(&$bytesdiff, $basedir, $filesdb, $id)
		{
			try
			{
				$row = $filesdb->GetRow("SELECT", array(
					"*",
					"FROM" => "?",
					"WHERE" => "id = ?",
				), "files", $id);

				if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");
				if ($row->type === "system")  return array("success" => false, "error" => "Deleting system objects is not allowed.", "errorcode" => "cannot_delete_system_objects");

				// Orphan the object.  Doesn't delete it (yet).
				$filesdb->Query("UPDATE", array("files", array(
					"pid" => 0,
				), "WHERE" => "id = ?"), $id);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "A database query failed while retrieving or orphaning the object.", "errorcode" => "db_query_error");
			}

			try
			{
				$filesdb->BeginTransaction();

				do
				{
					$found = false;

					// Find all orphaned objects and delete them.  This causes implicit recursion.
					$result = $filesdb->Query("SELECT", array(
						"f.*",
						"FROM" => "? AS f LEFT OUTER JOIN ? AS p ON (f.pid = p.id)",
						"WHERE" => "p.id IS NULL AND f.type <> 'system'"
					), "files", "files");

					while ($row = $result->NextRow())
					{
						if ($row->type === "file")
						{
							$path = self::GetFilePath($basedir, $row->id);
							@unlink($path . "/" . $row->id . ".dat");

							while (@rmdir($path))
							{
								$pos = strrpos($path, "/");
								if ($pos === false)  break;
								$path = substr($path, 0, $pos);
							}

							$bytesdiff -= $row->size;
						}

						$filesdb->Query("DELETE", array("files", "WHERE" => "id = ?"), $row->id);
					}

				} while ($found);

				$filesdb->Commit();

				return array("success" => true);
			}
			catch (Exception $e)
			{
				// Commit whatever is possible.
				$filesdb->Commit();

				return array("success" => false, "error" => "A database query failed while removing orphaned objects.", "errorcode" => "db_query_error");
			}
		}

		public static function GetTotalSize($filesdb)
		{
			try
			{
				// Calculate the size of all files.
				$size = (double)$filesdb->GetOne("SELECT", array(
					"SUM(size)",
					"FROM" => "?",
					"WHERE" => "status = ''",
				), "files");

				return $size;
			}
			catch (Exception $e)
			{
				return 0;
			}
		}

		private static function GuestHasObjectAccess($guestrow, $filesdb, $objectid)
		{
			// If the user is not a guest then they already have access.
			if ($guestrow === false)  return true;

			try
			{
				$rootid = $guestrow->serverexts["files"]["rootid"];

				return self::IsParentOfID($filesdb, $rootid, $objectid);
			}
			catch (Exception $e)
			{
				return false;
			}
		}

		public function ProcessAPI($reqmethod, $pathparts, $client, $userrow, $guestrow, $data)
		{
			global $rootpath, $userhelper;

			$basedir = self::InitUserFilesBasePath($userrow);

			$filename = $basedir . "/main.db";

			$runinit = !file_exists($filename);

			$filesdb = new CSDB_sqlite();

			try
			{
				$filesdb->Connect("sqlite:" . $filename);
			}
			catch (Exception $e)
			{
				return array("success" => false, "error" => "The database failed to open.", "errorcode" => "db_open_error");
			}

			if ($runinit)
			{
				// Create database tables.
				if (!$filesdb->TableExists("files"))
				{
					try
					{
						$filesdb->Query("CREATE TABLE", array("files", array(
							"id" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true, "PRIMARY KEY" => true, "AUTO INCREMENT" => true),
							"pid" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
							"name" => array("STRING", 1, 255, "NOT NULL" => true),
							"type" => array("STRING", 1, 255, "NOT NULL" => true),
							"status" => array("STRING", 1, 255, "NOT NULL" => true),
							"size" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
							"created" => array("INTEGER", 8, "UNSIGNED" => true, "NOT NULL" => true),
						),
						array(
							array("KEY", array("pid", "name"), "NAME" => "files_pidname"),
							array("KEY", array("status"), "NAME" => "files_status"),
						)));

						// Insert the root directory.
						$filesdb->Query("INSERT", array("files", array(
							"pid" => 0,
							"name" => "",
							"type" => "system",
							"status" => "",
							"size" => 0,
							"created" => time()
						)));
					}
					catch (Exception $e)
					{
						$filesdb->Disconnect();
						@unlink($filename);

						return array("success" => false, "error" => "Database table creation failed.", "errorcode" => "db_table_error");
					}
				}

				// Copy staging files into database.  Ignore all failures.
				$bytesdiff = 0;
				self::CopyExternalFilesToStorage($bytesdiff, $rootpath . "/user_init/files", $basedir, $filesdb, "/");

				// Adjust total bytes stored.
				$userhelper->AdjustUserTotalBytes($userrow->id, $bytesdiff);
			}

			// Main API.
			$y = count($pathparts);
			if ($y < 4)  return array("success" => false, "error" => "Invalid API call.", "errorcode" => "invalid_api_call");

			if ($pathparts[3] === "folder")
			{
				// Folder API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /files/v1/folder.", "errorcode" => "invalid_api_call");

				if ($pathparts[4] === "list")
				{
					// /files/v1/folder/list
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/folder/list/ID", "errorcode" => "use_get_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of parent for:  /files/v1/folder/list/ID", "errorcode" => "missing_id");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					try
					{
						$items = array();

						$result = $filesdb->Query("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "pid = ? AND status = ''"
						), "files", $pathparts[5]);

						while ($row = $result->NextRow())
						{
							$row->size = (double)$row->size;
							$row->created = (int)$row->created;

							$items[] = (array)$row;
						}

						return array("success" => true, "items" => $items);
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving folder list.", "errorcode" => "db_query_error");
					}
				}
				else if ($pathparts[4] === "create")
				{
					// /files/v1/folder/create
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/folder/create", "errorcode" => "use_post_request");
					if (!isset($data["folderid"]))  return array("success" => false, "error" => "Missing 'folderid'.", "errorcode" => "missing_folderid");
					if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
					if (!$userrow->serverexts["files"]["write"] || ($guestrow !== false && !$guestrow->serverexts["files"]["write"]))  return array("success" => false, "error" => "Write access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $data["folderid"]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					// Can create multiple folders at once.
					$path = "/" . str_replace("\\", "/", $data["name"]);
					$result = self::GetDBIDFromPath($filesdb, $path, true, $data["folderid"]);
					if (!$result["success"])  return $result;

					try
					{
						$row = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ?",
						), "files", $result["id"]);

						if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");

						return array("success" => true, "folder" => (array)$row);
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving object.", "errorcode" => "db_query_error");
					}
				}
			}
			else if ($pathparts[3] === "trash")
			{
				// Trash API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /files/v1/trash.", "errorcode" => "invalid_api_call");

				if ($pathparts[4] === "list")
				{
					// /files/v1/trash/list
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/trash/list", "errorcode" => "use_get_request");
					if ($y > 5 && !self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					try
					{
						$items = array();

						if ($y < 6)
						{
							$result = $filesdb->Query("SELECT", array(
								"*",
								"FROM" => "?",
								"WHERE" => "status = 'trash'"
							), "files");
						}
						else
						{
							$result = $filesdb->Query("SELECT", array(
								"*",
								"FROM" => "?",
								"WHERE" => "pid = ?"
							), "files", $pathparts[5]);
						}

						while ($row = $result->NextRow())
						{
							if ($y < 6 && !self::GuestHasObjectAccess($guestrow, $filesdb, $row->id))  continue;

							$row->size = (double)$row->size;
							$row->created = (int)$row->created;

							$items[] = (array)$row;
						}

						return array("success" => true, "items" => $items);
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving folder list.", "errorcode" => "db_query_error");
					}
				}
			}
			else if ($pathparts[3] === "file")
			{
				// File API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /files/v1/file.", "errorcode" => "invalid_api_call");

				if ($pathparts[4] === "upload")
				{
					// /files/v1/file/upload/ID
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/file/upload/ID", "errorcode" => "use_post_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of parent for:  /files/v1/file/upload/ID", "errorcode" => "missing_id");
					if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
					if (!isset($data["data"]) || !is_object($data["data"]))  return array("success" => false, "error" => "Missing upload 'data'.", "errorcode" => "missing_data");
					if (!$userrow->serverexts["files"]["write"] || ($guestrow !== false && !$guestrow->serverexts["files"]["write"]))  return array("success" => false, "error" => "Write access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					$filename = $data["data"]->filename;
					$size = HTTP::RawFileSize($filename);
					$bytesleft = CSS_GetQuotaLeft($userrow);
					if ($bytesleft > -1 && $bytesleft < $size)  return array("success" => false, "error" => "The size of the uploaded data exceeds the quota left.  If replacing an existing file, delete it first and try again.", "errorcode" => "upload_exceeds_quota_left");

					try
					{
						$row = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ? AND status = ''",
						), "files", $pathparts[5]);

						if (!$row)  return array("success" => false, "error" => "Parent object not found.", "errorcode" => "object_not_found");
						if ($row->type === "file")  return array("success" => false, "error" => "Parent object is a file.", "errorcode" => "invalid_parent_type");
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving object.", "errorcode" => "db_query_error");
					}

					$bytesdiff = 0;
					$result = self::CopyExternalFileToStorage($bytesdiff, $filename, $basedir, $filesdb, $row->id, (string)$data["name"]);

					$userhelper->AdjustUserTotalBytes($userrow->id, $bytesdiff);

					return $result;
				}
				else if ($pathparts[4] === "download" || $pathparts[4] === "downloaddatabase")
				{
					// /files/v1/file/download/ID[/filename] OR /files/v1/file/downloaddatabase[/filename]
					if (!isset($userrow->fp))
					{
						// First time through this API.
						if ($pathparts[4] === "download")
						{
							// Download file.
							if (!($client instanceof WebServer_Client))  return array("success" => false, "error" => "HTTP/HTTPS is required for:  /files/v1/file/download/ID[/filename]", "errorcode" => "use_http_https");
							if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/file/download/ID[/filename]", "errorcode" => "use_get_request");
							if ($y < 6)  return array("success" => false, "error" => "Missing ID for:  /files/v1/file/download/ID[/filename]", "errorcode" => "missing_id");
							if (!$userrow->serverexts["files"]["read"] || ($guestrow !== false && !$guestrow->serverexts["files"]["read"]))  return array("success" => false, "error" => "Download access denied.", "errorcode" => "access_denied");
							if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

							try
							{
								$row = $filesdb->GetRow("SELECT", array(
									"*",
									"FROM" => "?",
									"WHERE" => "id = ? AND status = ''",
								), "files", $pathparts[5]);
							}
							catch (Exception $e)
							{
								return array("success" => false, "error" => "A database query failed while retrieving object.", "errorcode" => "db_query_error");
							}

							if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");
							if ($row->type !== "file")  return array("success" => false, "error" => "Object is not a file.", "errorcode" => "invalid_type");

							$bytesleft = CSS_GetTransferLeft($userrow);
							if ($bytesleft > -1 && $row->size > $bytesleft)  return array("success" => false, "error" => "Downloading the file would result in exceeding the daily transfer limit.  Try again tomorrow.", "errorcode" => "would_exceed_transfer_limit");

							$options = array();
							$userrow->transferbytes += $row->size;
							$options["transferbytes"] = $userrow->transferbytes;

							$result = $userhelper->UpdateUser($userrow->id, $options);
							if (!$result["success"])  return $result;

							$path = self::GetFilePath($basedir, $row->id);

							$fp = @fopen($path . "/" . $row->id . ".dat", "rb");
							if ($fp === false)  return array("success" => false, "error" => "An error occurred while attempting to open the file on the server for download.  Try again later.", "errorcode" => "fopen_failed");

							$userrow->fp = $fp;
							$userrow->fpsize = $row->size;

							// Send the response.
							$client->SetResponseContentType("application/octet-stream");
							if ($y < 7)  $client->AddResponseHeader("Content-Disposition", "attachment; filename=\"" . HTTP::FilenameSafe($row->name) . "\"", true);
							$client->SetResponseContentLength($row->size);
						}
						else
						{
							// Download database.
							if (!($client instanceof WebServer_Client))  return array("success" => false, "error" => "HTTP/HTTPS is required for:  /files/v1/file/download/downloaddatabase[/filename]", "errorcode" => "use_http_https");
							if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/file/downloaddatabase[/filename]", "errorcode" => "use_get_request");
							if (!$userrow->serverexts["files"]["read"] || $guestrow !== false)  return array("success" => false, "error" => "Download access denied.", "errorcode" => "access_denied");

							$size = HTTP::RawFileSize($basedir . "/main.db");

							$bytesleft = CSS_GetTransferLeft($userrow);
							if ($bytesleft > -1 && $size > $bytesleft)  return array("success" => false, "error" => "Downloading the file would result in exceeding the daily transfer limit.  Try again tomorrow.", "errorcode" => "would_exceed_transfer_limit");

							$options = array();
							$userrow->transferbytes += $size;
							$options["transferbytes"] = $userrow->transferbytes;

							$result = $userhelper->UpdateUser($userrow->id, $options);
							if (!$result["success"])  return $result;

							$fp = @fopen($basedir . "/main.db", "rb");
							if ($fp === false)  return array("success" => false, "error" => "An error occurred while attempting to open the file on the server for download.  Try again later.", "errorcode" => "fopen_failed");

							$userrow->fp = $fp;
							$userrow->fpsize = $size;

							// Send the response.
							$client->SetResponseContentType("application/octet-stream");
							if ($y < 6)  $client->AddResponseHeader("Content-Disposition", "attachment; filename=\"main.db\"", true);
							$client->SetResponseContentLength($size);
						}
					}

					// Returning 'true' will trigger another call later, 'false' will terminate the connection.
					$data2 = @fread($userrow->fp, ($userrow->fpsize > 1048576 ? 1048576 : $userrow->fpsize));
					if ($data2 === false)  $data2 = "";
					if (($data2 === "" && $userrow->fpsize) || strlen($data2) > $userrow->fpsize)
					{
						@fclose($userrow->fp);
						unset($userrow->fp);

						return false;
					}

					$userrow->fpsize -= strlen($data2);
					$client->AddResponseContent($data2);

					if (!$userrow->fpsize)
					{
						@fclose($userrow->fp);
						unset($userrow->fp);

						$client->FinalizeResponse();
					}

					return true;
				}
			}
			else if ($pathparts[3] === "object")
			{
				// Object API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /files/v1/object.", "errorcode" => "invalid_api_call");

				if ($pathparts[4] === "bypath")
				{
					// /files/v1/object/bypath/...
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/object/bypath/...", "errorcode" => "use_get_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing path for:  /files/v1/object/bypath/...", "errorcode" => "missing_path");

					// Guests get a subview based on their 'rootid'.
					$path = "/" . implode("/", array_slice($pathparts, 5));
					$result = self::GetDBIDFromPath($filesdb, $path, false, ($guestrow === false ? "0" : $guestrow->serverexts["files"]["rootid"]));
					if (!$result["success"])  return $result;
					if (count($result["remaining"]))  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");

					try
					{
						$row = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ?",
						), "files", $result["id"]);

						if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");

						return array("success" => true, "object" => (array)$row);
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving object.", "errorcode" => "db_query_error");
					}
				}
				else if ($pathparts[4] === "byname")
				{
					// /files/v1/object/byname/ID/NAME
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/object/byname/ID/NAME", "errorcode" => "use_get_request");
					if ($y < 7)  return array("success" => false, "error" => "Missing folder ID or name for:  /files/v1/object/byname/ID/NAME", "errorcode" => "missing_id_or_name");

					// Normally resolve the object.
					$path = "/" . $pathparts[6];
					$result = self::GetDBIDFromPath($filesdb, $path, false, $pathparts[5]);
					if (!$result["success"])  return $result;

					// Check guest access.
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $result["id"]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					if (count($result["remaining"]))  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");

					try
					{
						$row = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ?",
						), "files", $result["id"]);

						if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");

						return array("success" => true, "object" => (array)$row);
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving object.", "errorcode" => "db_query_error");
					}
				}
				else if ($pathparts[4] === "byid")
				{
					// /files/v1/object/byid/ID
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/object/byid/ID", "errorcode" => "use_get_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing folder ID or name for:  /files/v1/object/byid/ID", "errorcode" => "missing_id");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					try
					{
						$row = $filesdb->GetRow("SELECT", array(
							"*",
							"FROM" => "?",
							"WHERE" => "id = ?",
						), "files", $pathparts[5]);

						if (!$row)  return array("success" => false, "error" => "Object not found.", "errorcode" => "object_not_found");

						return array("success" => true, "object" => (array)$row);
					}
					catch (Exception $e)
					{
						return array("success" => false, "error" => "A database query failed while retrieving object.", "errorcode" => "db_query_error");
					}
				}
				else if ($pathparts[4] === "copy")
				{
					// /files/v1/object/copy
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/object/copy", "errorcode" => "use_post_request");
					if (!isset($data["srcid"]))  return array("success" => false, "error" => "Missing 'srcid'.", "errorcode" => "missing_srcid");
					if (!isset($data["destid"]))  return array("success" => false, "error" => "Missing 'destfolderid'.", "errorcode" => "missing_destid");
					if (!$userrow->serverexts["files"]["write"] || ($guestrow !== false && !$guestrow->serverexts["files"]["write"]))  return array("success" => false, "error" => "Write access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $data["srcid"]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $data["destid"]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					if ($userrow->quota > -1 && $userrow->totalbytes >= $userrow->quota)  return array("success" => false, "error" => "User quota has been exceeded.", "errorcode" => "quota_exceeded");

					$bytesdiff = 0;
					$result = self::CopyStorageToStorage($bytesdiff, $basedir, $filesdb, (string)$data["srcid"], (string)$data["destid"]);

					$userhelper->AdjustUserTotalBytes($userrow->id, $bytesdiff);

					return $result;
				}
				else if ($pathparts[4] === "move")
				{
					// /files/v1/object/move
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/object/move", "errorcode" => "use_post_request");
					if (!isset($data["srcid"]))  return array("success" => false, "error" => "Missing 'srcid'.", "errorcode" => "missing_srcid");
					if (!isset($data["destfolderid"]))  return array("success" => false, "error" => "Missing 'destfolderid'.", "errorcode" => "missing_destfolderid");
					if (!$userrow->serverexts["files"]["write"] || ($guestrow !== false && !$guestrow->serverexts["files"]["write"]))  return array("success" => false, "error" => "Write access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $data["srcid"]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $data["destfolderid"]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					return self::MoveStorageToStorage($basedir, $filesdb, (string)$data["srcid"], (string)$data["destfolderid"]);
				}
				else if ($pathparts[4] === "rename")
				{
					// /files/v1/object/rename/ID
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/object/rename/ID", "errorcode" => "use_post_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of object for:  /files/v1/object/rename/ID", "errorcode" => "missing_id");
					if (!isset($data["name"]))  return array("success" => false, "error" => "Missing 'name'.", "errorcode" => "missing_name");
					if (!$userrow->serverexts["files"]["write"] || ($guestrow !== false && !$guestrow->serverexts["files"]["write"]))  return array("success" => false, "error" => "Write access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					return self::RenameStorageObject($filesdb, $pathparts[5], $data["name"]);
				}
				else if ($pathparts[4] === "trash" || $pathparts[4] === "restore")
				{
					// /files/v1/object/trash/ID OR /files/v1/object/restore/ID
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/object/" . $pathparts[4] . "/ID", "errorcode" => "use_post_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of object for:  /files/v1/object/" . $pathparts[4] . "/ID", "errorcode" => "missing_id");
					if (!$userrow->serverexts["files"]["write"] || ($guestrow !== false && !$guestrow->serverexts["files"]["write"]))  return array("success" => false, "error" => "Write access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					if ($pathparts[4] === "trash")  return self::TrashStorageObject($filesdb, $pathparts[5]);
					else  return self::RestoreStorageObject($filesdb, $pathparts[5]);
				}
				else if ($pathparts[4] === "delete")
				{
					// /files/v1/object/delete/ID
					if ($reqmethod !== "DELETE")  return array("success" => false, "error" => "DELETE request required for:  /files/v1/object/delete/ID", "errorcode" => "use_delete_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of object for:  /files/v1/object/delete/ID", "errorcode" => "missing_id");
					if (!$userrow->serverexts["files"]["delete"] || ($guestrow !== false && !$guestrow->serverexts["files"]["delete"]))  return array("success" => false, "error" => "Delete access denied.", "errorcode" => "access_denied");
					if (!self::GuestHasObjectAccess($guestrow, $filesdb, $pathparts[5]))  return array("success" => false, "error" => "Guest access denied to requested object.", "errorcode" => "access_denied");

					$bytesdiff = 0;
					$result = self::DeleteFromStorage($bytesdiff, $basedir, $filesdb, $pathparts[5]);

					$userhelper->AdjustUserTotalBytes($userrow->id, $bytesdiff);

					return $result;
				}
			}
			else if ($pathparts[3] === "user")
			{
				// User API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /files/v1/user.", "errorcode" => "invalid_api_call");

				if ($pathparts[4] === "root")
				{
					// /files/v1/user/root
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/user/root", "errorcode" => "use_get_request");

					$result = array(
						"success" => true,
						"id" => ($guestrow === false ? "0" : $guestrow->serverexts["files"]["rootid"]),
						"time" => time(),
						"download" => ($userrow->serverexts["files"]["read"] && ($guestrow === false || $guestrow->serverexts["files"]["read"])),
						"upload" => ($userrow->serverexts["files"]["write"] && ($guestrow === false || $guestrow->serverexts["files"]["write"])),
						"delete" => ($userrow->serverexts["files"]["delete"] && ($guestrow === false || $guestrow->serverexts["files"]["delete"])),
						"guests" => ($guestrow === false && $userrow->serverexts["files"]["guests"]),
						"expires" => ($guestrow === false ? -1 : (int)$guestrow->expires)
					);

					return $result;
				}
				else if ($pathparts[4] === "limits")
				{
					// /files/v1/user/limits
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/user/limits", "errorcode" => "use_get_request");

					global $config;

					$result = array(
						"success" => true,
						"info" => array(
							"quota" => (double)$userrow->quota,
							"transferlimit" => (double)$userrow->transferlimit,
							"fileuploadlimit" => $config["ext_file_uploadlimit"],

							// Probably the most important numbers.
							"uploadbytesleft" => CSS_GetMinAmountLeft(array(CSS_GetQuotaLeft($userrow), CSS_GetTransferLeft($userrow), $config["ext_file_uploadlimit"])),
							"downloadbytesleft" => CSS_GetTransferLeft($userrow)
						)
					);

					return $result;
				}
			}
			else if ($pathparts[3] === "guest")
			{
				// Guest API.
				if ($y < 5)  return array("success" => false, "error" => "Invalid API call to /files/v1/guest.", "errorcode" => "invalid_api_call");
				if ($guestrow !== false)  return array("success" => false, "error" => "Guest API key detected.  Access denied to /files/v1/guest.", "errorcode" => "access_denied");
				if (!$userrow->serverexts["files"]["guests"])  return array("success" => false, "error" => "Insufficient privileges.  Access denied to /files/v1/guest.", "errorcode" => "access_denied");

				if ($pathparts[4] === "list")
				{
					// /files/v1/guest/list
					if ($reqmethod !== "GET")  return array("success" => false, "error" => "GET request required for:  /files/v1/guest/list", "errorcode" => "use_get_request");

					return $userhelper->GetGuestsByServerExtension($userrow->id, "files");
				}
				else if ($pathparts[4] === "create")
				{
					// /files/v1/guest/create
					if ($reqmethod !== "POST")  return array("success" => false, "error" => "POST request required for:  /files/v1/guest/create", "errorcode" => "use_post_request");
					if (!isset($data["rootid"]))  return array("success" => false, "error" => "Missing 'rootid'.", "errorcode" => "missing_rootid");
					if (!isset($data["read"]))  return array("success" => false, "error" => "Missing 'read'.", "errorcode" => "missing_read");
					if (!isset($data["write"]))  return array("success" => false, "error" => "Missing 'write'.", "errorcode" => "missing_write");
					if (!isset($data["delete"]))  return array("success" => false, "error" => "Missing 'delete'.", "errorcode" => "missing_delete");
					if (!isset($data["expires"]))  return array("success" => false, "error" => "Missing 'expires'.", "errorcode" => "missing_expires");

					$options = array(
						"rootid" => (string)$data["rootid"],
						"read" => (bool)(int)$data["read"],
						"write" => (bool)(int)$data["write"],
						"delete" => (bool)(int)$data["delete"]
					);

					$expires = (int)$data["expires"];

					if ($expires <= time())  return array("success" => false, "error" => "Invalid 'expires' timestamp.", "errorcode" => "invalid_expires");

					return $userhelper->CreateGuest($userrow->id, "files", $options, $expires);
				}
				else if ($pathparts[4] === "delete")
				{
					// /files/v1/guest/delete/ID
					if ($reqmethod !== "DELETE")  return array("success" => false, "error" => "DELETE request required for:  /files/v1/guest/delete/ID", "errorcode" => "use_delete_request");
					if ($y < 6)  return array("success" => false, "error" => "Missing ID of guest for:  /files/v1/guest/delete/ID", "errorcode" => "missing_id");

					return $userhelper->DeleteGuest($pathparts[5], $userrow->id);
				}
			}

			return array("success" => false, "error" => "Invalid API call.", "errorcode" => "invalid_api_call");
		}
	}
?>