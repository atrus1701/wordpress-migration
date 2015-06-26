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
	
	// WordPress domain and path of local WordPress install.
	'domain'			=> '_local_wp_domain_',
	'path'				=> '_local_wp_path_',
	
	// SSL settings for the server for the WP install that is being duplicated.
	'remote_server' 	=> '_remote_server_address_',
	'remote_username' 	=> '_remote_server_user_',
	
	// Full path of the folder to copy dump files from on the remote server.
	'remote_dump_path'	=> '_remote_server_dump_directory_full_path_',
	
	// WordPress domain and path of WordPress install being duplicated.
	'remote_domain'		=> '_remote_wp_domain_',
	'remote_path'		=> '_remote_wp_path_',
	
	// Delimiter used to parse out SQL statements in the dump files.
	'delimiter'			=> "\n",
	
	// Max number of rows to process at once when find and replacing.
	// Try decreasing this number if "Allowed memory size" errors occur.
	'select_limit'		=> 100,

	// Find and replace values.
	// The key is the find and value is the replace.
	'find_replace'		=> array(

		// remote domain/path to local domain/path
		'//_remote_domain_and_path_' => '//_local_domain_and_path_',
		
		// remote WordPress folder path to local WordPress folder path
		'_remote_wordpress_directory_full_path_' => '_local_wordpress_directory_full_path_',

	),
);

