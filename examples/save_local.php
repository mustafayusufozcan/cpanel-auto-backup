<?php

use MYO\cPanelAutoBackup;

require "vendor/autoload.php";

$autoBackup = new cPanelAutoBackup("https://example.com:2083", "username", "password");

$autoBackup->setBackupPath('backups');

$autoBackup->backup("database_name");