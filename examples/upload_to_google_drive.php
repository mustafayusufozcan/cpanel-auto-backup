<?php

use MYO\cPanelAutoBackup;

require "vendor/autoload.php";

$autoBackup = new cPanelAutoBackup("https://example.com:2083", "username", "password");

$autoBackup->setBackupPath('backups');

$autoBackup->setUploadToGDrive(true, false);

$autoBackup->setGClientCredentialsFile('client_credentials.json');

$autoBackup->setGClientRefreshToken("GDriveRefreshToken");

$autoBackup->backup("database_name");