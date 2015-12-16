<?php
/**
 * Copies the public_html folder from a remote server to the local machine.
 * 
 * @package    wordpress-migrate
 * @author     Crystal Barton <atrus1701@gmail.com>
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
	clear_log();
	
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
	echo2( "\nClearing files from WordPress install path.\n" );
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
	echo2( "\nCopying WordPress files.\n" );
	$return_value = 0;
	
	$exclude_files = array();
	$exclude_files[] = 'wp-config.php';
	$exclude_files[] = 'error_log';
	$exclude_files[] = '.git';
	$exclude_files[] = '.git*';

	if( !$copy_all )
	{
		$exclude_files[] = 'wp-content/blogs.dir/';
		$exclude_files[] = 'wp-content/uploads/';
	}

	if( !is_windows() )
	{
		$exclude_files_command = '';
		if( count($exclude_files) > 0 )
			$exclude_files_command = '--exclude='.implode( ' --exclude=', $exclude_files );
		
		passthru( "rsync -azP $exclude_files_command '$remote_username@$remote_server:$remote_wp_path/' $wp_path", $return_value );
	}
	else
	{
		$winscp_path = get_winscp_path();
		if( !$winscp_path )
		{
			script_die(
				'Unable to find WinSCP.com install.',
				'Please download and install WinSCP from winscp.net.',
				'You can install the full version or unzip the portable version into the script folder.' );
		}

		$exclude_files_command = '';
		if( count($exclude_files) > 0 )
			$exclude_files_command = implode( '; ', $exclude_files );

		passthru( "$winscp_path\winscp.com /command \"option batch abort\" \"option confirm off\" \"open scp://$remote_username@$remote_server\" \"synchronize local -filemask=\"\"| $exclude_files_command\"\" $wp_path $remote_wp_path\" \"close\" \"close\"", $return_value );
	}

	if( $return_value !== 0 )
	{
		script_die( 'The copy encountered an error and the script needs to stop.' );
	}
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
