Cloud Storage Server
====================

An open source, extensible, self-hosted cloud storage API.  The base server implements a complete file system similar to Amazon Cloud Drive, B2 Cloud Storage, OpenDrive, and other providers.  Just don't expect to build a scalable service with this software.

Cloud Storage Server pairs quite nicely with [Cloud Backup](https://github.com/cubiclesoft/cloud-backup) and [Cloud Storage Tools](https://github.com/cubiclesoft/cloud-storage-tools).

[![Donate](https://cubiclesoft.com/res/donate-shield.png)](https://cubiclesoft.com/donate/)

Features
--------

* Completely self-contained.  No need for a separate web server or enterprise database engine - just install PHP and go.
* Works well with [Service Manager](https://github.com/cubiclesoft/service-manager/).  Automatically start Cloud Storage Server when the system boots up.
* Command-line driven user management interface.  Quickly set up API keys and access levels.
* The /files API implements everything needed in a file-based cloud storage provider:  Folder hierarchy management, file upload/download, copy, move, rename, trash and restore, delete, and guest access.
* User initialization for first time use of a specific server extension.  Useful for placing a 'welcome message' and/or initial folder setup in their account.
* Extensible. Only limited by your imagination.
* [Remoted API Server](https://github.com/cubiclesoft/remoted-api-server) capable.
* Standards-based, RESTful interface.  Also supports most operations over WebSocket.
* Secure.  Automatically generates and signs multi-year root and server SSL certificates.  You can use other certs if you want OR proxy requests from a properly configured web server.
* Per-user quota management.
* Per-user daily network transfer limits.
* Also has a liberal open source license.  MIT or LGPL, your choice.
* Designed for relatively painless integration into your environment.
* Sits on GitHub for all of that pull request and issue tracker goodness to easily submit changes and ideas respectively.

Uses
----

* Build your own private cloud storage solution.
* Use [Cloud Backup](http://cubiclesoft.com/cloud-backup) to send encrypted data to a friend's house who lives in the same town.  Rapidly recover lost data in the event of catastropic loss (e.g. fire, flood).
* Backup data from deep behind corporate firewalls via [Remoted API Server](https://github.com/cubiclesoft/remoted-api-server).
* Customized, user-oriented, permission-based APIs.
* See the Nifty Extensions section for more ideas.

Getting Started
---------------

Download or clone the latest software release.  If you do not have PHP installed, then download and install the command-line (CLI) version for your OS (e.g. 'apt install php-cli' on Debian/Ubuntu).  Windows users try [Portable Apache + PHP + Maria DB](https://github.com/cubiclesoft/portable-apache-maria-db-php-for-windows).

You'll also need to enable the SQLite and OpenSSL PHP modules for your PHP CLI version (e.g. 'apt install php-sqlite php-openssl' on Debian/Ubuntu, edit the 'php.ini' file on Windows).

From a command-line, run:

```
php install.php
```

The installer will ask a series of questions that will create the baseline server configuration.  If extensions will be used that require "root" privileges (e.g. /scripts and /feeds), be sure to enter "root" for the user.  When adding extensions or upgrading, re-run the installation command before starting the server to avoid problems.  Skip the service installation step until you are ready to have the software run at boot.

Run the user management interface and add a user with access to the 'files' extension (grants access to the /files API):

```
php manage.php

Ready.  This is a command-line interface.  Enter 'help' to get a list of available commands.

>adduser yourusername
Host:  https://localhost:9892
API key:  abcdef12......34567890-1
>adduserext yourusername files
[Files Ext] Allow file download access (Y/N):  Y
[Files Ext] Allow folder write, file upload, trash access (Y/N):  Y
[Files Ext] Allow permanent folder and file delete access (Y/N):  Y
[Files Ext] Allow guest creation/deletion (Y/N):  Y
>exit
```

Be sure to copy the `Host` and `API key` somewhere.  Depending on the configuration and setup, `Host` might not be correct.  Adjust it accordingly.

To make sure the server works correctly, run it directly at first:

```
php server.php
```

Then connect to the server with a valid client SDK using the `Host` and `API key` from earlier.

Once everything is good to go, re-run the installer to install the server as a system service:

```
php install.php
```

Nifty Extensions
----------------

* [/feeds](https://github.com/cubiclesoft/cloud-storage-server-ext-feeds) - A powerful and flexible API to send and schedule notifications with data payloads attached.  The /feeds API allows for scheduling future notifications and has powerful filtering features to only return information that a monitoring application is interested in.
* [/scripts](https://github.com/cubiclesoft/cloud-storage-server-ext-scripts) - A powerful and flexible API to initiate named, long-running scripts as other users on a system (e.g. root/SYSTEM).  The /scripts API uses a crontab-like, per-user definition file to define what scripts can be run.  Parameter and limited 'stdin' passing support.  While scripts are running, track status and/or monitor start and completion of script runs.

Got an idea for an extension that you would like to see included?  Open an issue on the issue tracker.  Due to Cloud Storage Server being a server product that is intended to be network-facing, all included extensions must pass rigorous CubicleSoft standards.  Extensions must also be dual licensed under MIT/LGPL.

Extension:  /files
------------------

The files extension implements the /files/v1 API.  To try to keep this page relatively short, here is the list of available APIs, the input request method, and successful return values (always JSON output with the exceptions of 'download' and 'downloaddatabase'):

GET /files/v1/folder/list/ID

* ID - Folder ID
* Returns:  success (boolean), items (array)

POST /files/v1/folder/create

* name - Folder name
* Returns:  success (boolean), folder (array)

GET /files/v1/trash/list

* Returns:  success (boolean), items (array)

POST /files/v1/file/upload/ID

* ID - Parent folder ID
* name - Filename
* data - File data
* Returns:  success (boolean), id (string)

GET /files/v1/file/download/ID[/filename]

* ID - File ID
* filename - Ignored but used by most web browsers
* Returns:  Binary data

GET /files/v1/file/downloaddatabase[/filename]

* filename - Ignored but used by most web browsers
* Returns:  Binary data (the user's entire SQLite files database)

GET /files/v1/object/bypath/...

* ... - A human-readable path in the system from the root of the user's access level (i.e. a guest user's root is usually a subset of the entire system)
* Returns:  success (boolean), object (array)

GET /files/v1/object/byname/ID/NAME

* ID - Parent folder ID
* NAME - A human-readable name or path in the system from ID
* Returns:  success (boolean), object (array)

GET /files/v1/object/byid/ID

* ID - Object ID
* Returns:  success (boolean), object (array)

POST /files/v1/object/copy

* srcid - Source object ID
* destid - Destination object ID
* Returns:  success (boolean)

POST /files/v1/object/move

* srcid - Source object ID
* destfolderid - Destination folder ID
* Returns:  success (boolean)

POST /files/v1/object/rename/ID

* ID - Object ID
* name - New object name
* Returns:  success (boolean), object (array)

POST /files/v1/object/trash/ID

* ID - Object ID
* Returns:  success (boolean), object (array)

POST /files/v1/object/restore/ID

* ID - Object ID
* Returns:  success (boolean), object (array)

POST /files/v1/object/delete/ID

* ID - Object ID
* Returns:  success (boolean)

GET /files/v1/user/root

* Returns: success (boolean), id (string), download (boolean), upload (boolean), delete (boolean), guests (boolean), expires (integer)
* Summary:  Returns root ID, permissions, and expiration of the guest's API key.

GET /files/v1/user/limits

* Returns:  success (boolean), info (array)
* Summary:  The 'info' array contains:  quota, transferlimit, fileuploadlimit, uploadbytesleft, downloadbytesleft.

GET /files/v1/guest/list

* Returns: success (boolean), guests (array)

POST /files/v1/guest/create

* rootid - Root object ID
* read - Guest can download files
* write - Guest can upload, trash, and restore
* delete - Guest can permanently delete objects
* expires - Unix timestamp (integer)
* Returns: success (boolean), id (string), info (array)
* Summary:  The 'info' array contains:  apikey, created, expires, info (rootid, read, write, delete)

POST /files/v1/guest/delete/ID

* ID - Guest ID
* Returns:  success (boolean)
