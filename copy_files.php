<?php
/**
 * Copies the public_html folder from a remote server to the local machine.
 * 
 * @package    wordpress-migrate
 * @author     Crystal Barton <cbarto11@uncc.edu>
 */


// Configuration settings.
// Empty strings are expected to be filled by the time verify_config_values is called.
$config = array(
	
	// Path to the local public_html or WordPress install folder.
	'wp_path'			=> '',
	
	// SSL settings for origin server.
	'remote_server' 	=> '',
	'remote_username' 	=> '',
	
	// Path to the remote public_html or WordPress install folder.
	'remote_wp_path'	=> '',
	
	// Empty local folder before copying remote folder.
	'clean_copy'		=> false,
	
	// Copy all folders including the very large blogs.dir and uploads
	'copy_all'			=> false,
	
);


// Include general config data.
if( file_exists(dirname(__FILE__).'/config.php') )
	require_once( dirname(__FILE__).'/config.php' );

// Include the custom config data for the copy_files script.
if( file_exists(dirname(__FILE__).'/config_copy_files.php') )
	require_once( dirname(__FILE__).'/config_copy_files.php' );


// Include the required functions.
require_once( dirname(__FILE__).'/functions.php' );


// Process args and verify config values.
process_args( array('clean_copy', 'copy_all') );
verify_config_values();


// Extract config into individual global variables.
extract($config);


/**
 * The main function of the script.
 */
function main()
{
	clear_wp_folder();
 	copy_wp_folder();
}


/**
 * Clear out all files from the web server's html folder.
 */
if( !function_exists('clear_wp_folder') ):
function clear_wp_folder()
{
	global $clean_copy, $wp_path;
	
	if( !$clean_copy ) return;
	
	// Clear files from public_html
	echo "\nClearing files from WordPress install path.\n";
	remove_directory( "$wp_path" );
	exec( "mkdir $wp_path" );
}
endif;


/**
 * Copy the files from a remote server to the web server's html folder.
 */
if( !function_exists('copy_wp_folder') ):
function copy_wp_folder()
{
	global $copy_all, $remote_username, $remote_server, $remote_wp_path, $wp_path;
	// Copy files from remote location
	echo "\nCopying WordPress files.\n";
	
	$exclude_files = '--exclude=.git --exclude=error_log';
	if( $copy_all )
	{
		$exclude_files .= ' --exclude=wp-contents/blog.dir --exclude=wp-contents/uploads';
	}
	
	passthru( "rsync -azP $exclude_files '$remote_username@$remote_server:$remote_wp_path/'  $wp_path" );
}
endif;


//========================================================================================
//============================================================================= MAIN =====

main();

