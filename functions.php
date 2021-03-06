<?php
/**
 * Common functions used in all WordPress Migration scripts.
 * 
 * @package    wordpress-migrate
 * @author     Crystal Barton <atrus1701@gmail.com>
 */


// The amount of memory allocated for this script.
// Increase this number if "Allowed memory size" errors occur.
ini_set('memory_limit', '512M');

// Database connection object.
$db_connection = null;
$is_connection = null;

// A list of non-fatal errors that have occured.
$errors = array();


/**
 * Process an arguments included in the script call and add to the config array.
 */
if( !function_exists('process_args') ):
function process_args( $switches = array() )
{
	global $argv, $config;

	$opt_keys = array_keys( $config );
	
	foreach( $opt_keys as &$k )
	{
		if( !in_array($k, $switches) )
			$k .= ':';
	}
	
	$new_config = getopt( '', $opt_keys );
	
	foreach( $new_config as &$c )
	{
		if( in_array($c, $switches) )
			$c = true;
	}
	
	$config = array_merge( $config, $new_config );
}
endif;


/**
 * Merges two config arrays: the file config values and arguments config values.
 * @param   array  $a  The file config values.
 * @param   array  $b  The arguments config values.
 */
if( !function_exists('merge_config') ):
function merge_config( &$a, &$b )
{
	foreach( $b as $k => $v )
	{
		if( (!array_key_exists($k, $a)) ||
			(is_array($v) && count($v) > 0) ||
			(is_string($v) && $v !== '') )
		{
			$a[$k] = $b[$k];
		}
	}
}
endif;


/**
 * Determines if the current OS is Windows.
 * @return  bool  True if running on Windows OS, otherwise False.
 */
if( !function_exists('is_windows') ):
function is_windows()
{
	return ( DIRECTORY_SEPARATOR == '\\' );
}
endif;


/**
 * Get the WinSCP.com absolute path.
 * @eeturn  string|bool  The path to winscp.com file or false, if not found.
 */
if( !function_exists('get_winscp_path') ):
function get_winscp_path()
{
	if( !is_windows() ) return false;

	global $winscp_folder;
	$check_folders = array();

	if( !empty($winscp_folder) )
		$check_folders[] = $winscp_folder;
	$check_folders[] = __DIR__;

	foreach( $check_folders as $folder )
	{
		if( file_exists($folder.'\winscp.com') )
			return $folder;
	}

	return false;
}
endif;


/**
 * Verify that all config values have a value.
 * @param   array  $can_be_empty  A list of config values that can be empty.
 * @param   array  $is_array      A list of config values that should be an array.
 */
if( !function_exists('verify_config_values') ):
function verify_config_values( $can_be_empty = array(), $is_array = array() )
{
	global $config;
	
	foreach( $config as $key => &$value )
	{
		if( in_array($key, $is_array) )
		{
			if( is_array($value) ) continue;
			$value = array( $value );
			continue;
		}

		if( is_array($value) )
			$value = $value[ count($value)-1 ];

		if( in_array($key, $can_be_empty) ) continue;
		
		if( $value === null ) 	script_die( "The $key value is null." );
		if( $value === '' ) 	script_die( "The $key value is empty." );
	}
}
endif;


/**
 * Connect to the local database.
 */
if( !function_exists('sql_connect') ):
function sql_connect()
{
	global $db_connection, $is_connection, $dbhost, $dbname, $dbusername, $dbpassword;
	if( $db_connection ) return;
	
	// Create connection
	echo2( "\nConnecting to the database.\n" );
	try
	{
		$db_connection = new PDO( "mysql:host=$dbhost;dbname=$dbname;charset=utf8", $dbusername, $dbpassword );
		$db_connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

		$is_connection = new PDO( "mysql:host=$dbhost;dbname=information_schema;charset=utf8", $dbusername, $dbpassword );
		$is_connection->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
	}
	catch( PDOException $e )
	{
		$db_connection = null;
		$is_connection = null;
		script_die( 'Unable to connect to the database.', $e->getMessage() );
	}
}
endif;

	
/**
 * Close the connection to the local database.
 */
if( !function_exists('sql_close') ):
function sql_close()
{
	global $db_connection, $is_connection;
	$db_connection = null;
	$is_connection = null;
}
endif;


/**
 * Add an error by getting all function args and storing in the errors list.
 */
if( !function_exists('add_error') ):
function add_error()
{
	global $errors;
	$errors[] = func_get_args();
}
endif;
	

/**
 * Print all the errors stored in the errors list.
 */	
if( !function_exists('print_errors') ):
function print_errors()
{
	global $errors;
	
	if( count($errors) == 0 )
	{
		echo2( "\n.No errors were logged.\n\n" );
		return;
	}
	
	echo2( "\n".count($errors)." errors were logged.\n\n" );
	
	foreach( $errors as $error )
	{
		echo2( implode( ' : ', $error )."\n" );
	}
	
	echo2( "\n\n" );
}
endif;


/**
 * End the script by printing the error message that is passed in the function args.
 */
if( !function_exists('script_die') ):
function script_die()
{
	echo2( "\n\nERROR:\n".implode( "\n", func_get_args() )."\n\n" );
	die();
}
endif;


/**
 * Removes a directory.
 * @param   string  $folder  The directory to remove.
 * @param   int     $depth   The depth in the directory hierarchy the process is.
 *                           Should not be included in the initial function call.
 * @return  bool    True if the directory was deleted, otherwise False.
 */
if( !function_exists('remove_directory') ):
function remove_directory( $folder, $depth = 0 )
{
	if( !is_dir($folder) ) return;

	foreach( glob($folder . '/*') as $file )
	{
		if( strrpos($file, '/.') === strlen($file)-2 )  continue;
		if( strrpos($file, '/..') === strlen($file)-3 ) continue;
	
		if( !is_dir($file) ) @unlink( $file );
	}

	foreach( glob($folder . '/.*') as $file )
	{
		if( strrpos($file, '/.') === strlen($file)-2 )  continue;
		if( strrpos($file, '/..') === strlen($file)-3 ) continue;
	
		if( !is_dir($file) ) @unlink( $file );
	}

	foreach( glob($folder . '/*') as $file )
	{
		if( is_dir($file) ) remove_directory( $file, $depth+1 );
		else @unlink( $file );
	}

	@rmdir( $folder );

	if( is_dir($folder) )
	{
		exec( "rm -rf '$folder'" );
		if( is_dir($folder) )
		{
			return false;
		}
	}

	return true;
}
endif;


/**
 * Delete files and folders based on a wildcard (*) match.
 * @param   string  $filename  The wildcard file or folder name.
 */
if( !function_exists('delete_with_wildcard') ):
function delete_with_wildcard( $filename )
{
	foreach( glob($filename) as $file )
	{
		if( is_dir($file) )
			remove_directory( $file, true );
		else
			@unlink( $file );
	}
}
endif;


/**
 * Determins if a string is serialized.
 * * Copied directly from the WordPress sorce code. *
 * @param   string  $data    The string to analyze.
 * @param   bool    $strict  Use a strict comparison to determine if a serialized object/array.
 * @return  bool    True if the string is serialized data, otherwise False.
 */
if( !function_exists('is_serialized') ):
function is_serialized( $data, $strict = true )
{
	// if it isn't a string, it isn't serialized.
	if ( ! is_string( $data ) ) {
		return false;
	}
	$data = trim( $data );
	if ( 'N;' == $data ) {
		return true;
	}
	if ( strlen( $data ) < 4 ) {
		return false;
	}
	if ( ':' !== $data[1] ) {
		return false;
	}
	if ( $strict ) {
		$lastc = substr( $data, -1 );
		if ( ';' !== $lastc && '}' !== $lastc ) {
			return false;
		}
	} else {
		$semicolon = strpos( $data, ';' );
		$brace     = strpos( $data, '}' );
		// Either ; or } must exist.
		if ( false === $semicolon && false === $brace )
			return false;
		// But neither must be in the first X characters.
		if ( false !== $semicolon && $semicolon < 3 )
			return false;
		if ( false !== $brace && $brace < 4 )
			return false;
	}
	$token = $data[0];
	switch ( $token ) {
		case 's' :
			if ( $strict ) {
				if ( '"' !== substr( $data, -2, 1 ) ) {
					return false;
				}
			} elseif ( false === strpos( $data, '"' ) ) {
				return false;
			}
			// or else fall through
		case 'a' :
		case 'O' :
			return (bool) preg_match( "/^{$token}:[0-9]+:/s", $data );
		case 'b' :
		case 'i' :
		case 'd' :
			$end = $strict ? '$' : '';
			return (bool) preg_match( "/^{$token}:[0-9.E-]+;$end/", $data );
	}
	return false;
}
endif;


/**
 * Simple timer class to keep track of how long each section is taking.
 */
if( !class_exists('Timer') ):
class Timer
{
	private $start_time = null;
	public function __construct() {}
	public function start() { $this->start_time = time(); }
    public function get_elapsed_time() { return time() - $this->start_time; }
}
endif;


/**
 * Clear the log file, if one is specified.
 */
if( !function_exists('clear_log') ):
function clear_log()
{
	global $log;
	if( $log ) file_put_contents( $log, '' );
}
endif;


/**
 * Echo text to the screen and a log file, if one is specified.
 * @param   string  $text  The text to display.
 */
if( !function_exists('echo2') ):
function echo2( $text )
{
	global $log;
	echo $text;
	if( $log ) file_put_contents( $log, $text, FILE_APPEND );
}
endif;


/**
 * Prints the header and footer for the script output.
 * @param  string  $text  The action text, for example: Copying files started.
 */
if( !function_exists('print_header') ):
function print_header( $text )
{
	echo2( "\n\n" );
	echo2( "==========================================================================================\n" );
	echo2( $text.' on '.date( 'F j, Y h:i:s A' )."\n" );
	echo2( "==========================================================================================\n" );
	echo2( "\n\n" );
}
endif;


/**
 * Creates a time string to display the seconds in readable manner.
 * @param   int     $seconds  The seconds to convert into hours, minutes, and seconds format.
 * @return  string  The formatted time string.
 */
if( !function_exists('get_time_string') ):
function get_time_string( $seconds )
{
	$time = array(
		'hours'   => 0,
		'minutes' => 0,
		'seconds' => 0,
	);
	
	$minutes = $seconds / 60;
	$hours = $minutes / 60;

	if( $hours > 0 )
	{
		$time['seconds'] = $seconds - ($minutes * 60);
		$time['minutes'] = $minutes - ($hours * 60);
		$time['hours']   = $hours;
	}
	elseif( $minutes > 0 )
	{
		$time['seconds'] = $seconds - ($minutes * 60);
		$time['minutes'] = $minutes;
	}
	else
	{
		$time['seconds'] = $seconds;
	}

	foreach( $time as $key => &$value )
	{
		$value .= " $key";
	}

	return implode( ' ', $times );
}
endif;


if( !function_exists('is_numeric_column') ):
function is_numeric_column( $table_name, $column_name )
{
	global $is_connection, $dbname;

	$select_sql = "SELECT 1 FROM `columns` WHERE TABLE_SCHEMA = '{$dbname}' AND TABLE_NAME = '{$table_name}' AND COLUMN_NAME = '{$column_name}' AND NUMERic_PRECISION IS NOT NULL;";
	
	// Update table row
	try
	{
		$data = $is_connection->query( $select_sql );
	}
	catch( PDOException $e )
	{
		echo2( $e->getMessage() );
		add_error(
			$table_name,
			$e->getMessage(),
			"\n".print_r($select_sql, true)
		);
		return false;
	}

	$is_numeric_column = ( $data->rowCount() > 0 );
	$data = null;

	return $is_numeric_column;
}
endif;

