<?php

global $config;

$config = array(
	
	// Database settings for local database.
	'dbhost' 			=> 'localhost',
	'dbusername' 		=> '_db_user_',
	'dbpassword' 		=> '_db_password_',
	'dbname'			=> '_db_name_',
	
	// Full path to folder to transfer dump files to on local server.
	'dump_path'			=> '_dump_directory_full_path_',
	
	// Delimiter used to parse out SQL statements in the dump files.
	'delimiter'			=> "\n",
	
	// Max number of rows to process at once when find and replacing.
	// Decrease this number if "Allowed memory size" errors occur.
	'select_limit'		=> 100,
	
	// The relative or full path to the log file.
	'log'				=> '',
);

