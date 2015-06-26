<?php

global $config;

$config = array(
	
	// Full path to the local WordPress install folder.
	'wp_path'			=> '_local_wordpress_directory_full_path_',
	
	// SSL settings for the server for the WP install that is being duplicated.
	'remote_server' 	=> '_remote_server_address_',
	'remote_username' 	=> '_remote_server_user_',
	
	// Full path to the remote WordPress install folder.
	'remote_wp_path'	=> '_remote_wordpress_directory_full_path_',
	
	// Empty local folder before copying remote folder.
	'clean_copy'		=> false,
	
	// Copy all folders including the very large blogs.dir and uploads
	'copy_all'			=> false,
	
);

