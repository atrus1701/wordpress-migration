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
	
	// Full path to the local WordPress install folder.
	'wp_path'			=> '',
	
	// SSL settings for the server for the WP install that is being duplicated.
	'remote_server' 	=> '',
	'remote_username' 	=> '',
	
	// Full path to the remote WordPress install folder.
	'remote_wp_path'	=> '',
	
	// Empty local folder before copying remote folder.
	'clean_copy'		=> false,
	
	// Copy all folders including the very large blogs.dir and uploads
	'copy_all'			=> false,
	
	// The full path to the folder that contains WinSCP.com file.
	'winscp_folder'		=> '',

	// The relative or full path to config data.
	'config'			=> 'config_copy_files.php',

	// The relative or full path to the log file.
	'log'				=> '',
);





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
	
	$exclude_files = '--exclude wp-config.php --exclude=.git --exclude=error_log';
	if( !$copy_all )
	{
		$exclude_files .= ' --exclude=wp-content/blogs.dir --exclude=wp-content/uploads';
	}
	
	passthru( "rsync -azP $exclude_files '$remote_username@$remote_server:$remote_wp_path/'  $wp_path" );
}
endif;


//========================================================================================
//============================================================================= MAIN =====

// Include the required functions.
require_once( __DIR__.'/functions.php' );


print_header( 'Copying files started' );


// Process args.
process_args();


// Include the custom config data.
$args_config = $config;
if( !empty($config['config']) && file_exists($config['config']) )
	require_once( $config['config'] );
merge_config( $config, $args_config );


// Verify that all the config values are valid.
verify_config_values( array('winscp_folder') );


// Extract config into individual global variables.
extract($config);


main();


print_header( 'Copying files ended' );
