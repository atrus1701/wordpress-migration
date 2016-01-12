<?php
/**
 * Dumps the current database to a series of SQL files in the dump folder.
 * 
 * @package    wordpress-migrate
 * @author     Crystal Barton <atrus1701@gmail.com>
 */


// Configuration settings.
// Empty strings are expected to be filled by the time verify_config_values is called.
$config = array(
	
	// Database settings for local database.
	'dbhost' 			=> '',
	'dbusername' 		=> '',
	'dbpassword' 		=> '',
	'dbname'			=> '',
	
	// Full path to folder to transfer dump files to on local server.
	'dump_path'			=> '',
	
	// Delimiter used to parse out SQL statements in the dump files.
	'delimiter'			=> "\n",
	
	// Max number of rows to process at once when find and replacing.
	// Decrease this number if "Allowed memory size" errors occur.
	'select_limit'		=> 100,

	// The relative or full path to config data.
	'config'			=> 'config_dump_db.php',

	// The relative or full path to the log file.
	'log'				=> '',
);


/**
 * The main function of the script.
 */
function main()
{
	clear_log();

	clear_dump_folder();
	
	sql_connect();
	
	dump_database_structure();
	dump_database_data();
	
	sql_close();
}


/**
 * Clears all files from the dump folder.
 */
if( !function_exists('clear_dump_folder') ):
function clear_dump_folder()
{
	global $dump_path;
	
	// Create dump directory
	echo2( "\nCreating dump directory.\n" );
	if( !is_dir($dump_path) )
	{
		if( !mkdir($dump_path) )
			script_die( 'Unable to create dump folder.' );
	}

	// Clear out dump directory
	delete_with_wildcard( "$dump_path/*" );		
}
endif;


/**
 * Dump the database structure (through DROP / CREATE TABLE commands) to a series of
 * SQL files.
 */
if( !function_exists('dump_database_structure') ):
function dump_database_structure()
{
	global $db_connection, $dbname;
	
	echo2( "\nDumping database's table structure...\n\n" );
	
	if( !$db_connection )
		script_die( 'Must connect to database before generating the table structure.' );
	
	try
	{
		$tables = $db_connection->query( 'SHOW TABLES' );
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to retrieve database table list.', $e->getMessage() );
	}
	
	$key = 'Tables_in_'.$dbname;

	// Dump each table's data.
	$total_tables = $tables->rowCount();
	$current_table_count = 1;
	while( $table = $tables->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT) )
	{
		$table_name = $table[ $key ];
		
		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo2( "Dumping table structure [$n of $total_tables] $table_name\n" );
		
		dump_table_structure( $table_name );
		$current_table_count++;
	}

	$tables = null;
}
endif;


/**
 * Dump a table's structure (through DROP / CREATE TABLE commands) to a SQL file.
 * @param   string  $table_name  The name of the table.
 */
if( !function_exists('dump_table_structure') ):
function dump_table_structure( $table_name )
{
	global $db_connection, $dump_path, $delimiter;
	
	$dump_file = "$dump_path/$table_name.sql";
	
	try
	{
		$create_table = $db_connection->query( "SHOW CREATE TABLE $table_name" );
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to retrieve create table data for "'.$table_name.'".', $e->getMessage() );
	}
	
	if( count($create_table) == 0 )
	{
		script_die( 'Unable to retrieve create table data for "'.$table_name.'".' );
	}
	
	$create_table_sql = $create_table->fetchColumn(1);
	$create_table_sql = str_replace( array("\r\n", "\r", "\n"), '', $create_table_sql );
	$drop_table_sql = "DROP TABLE IF EXISTS `$table_name`";
	
	file_put_contents( $dump_file, "$drop_table_sql;$delimiter", FILE_APPEND );
	file_put_contents( $dump_file, "$create_table_sql;$delimiter", FILE_APPEND );

	$create_table = null;
}
endif;


/**
 * Dump the database data (through INSERT commands) to a series of SQL files.
 */
if( !function_exists('dump_database_data') ):
function dump_database_data()
{
	global $db_connection, $dbname;
	
	echo2( "\nDumping database's table data.\n" );
	
	if( !$db_connection )
		script_die( 'Must connect to database before generating the table structure.' );
	
	// Get table listing
	try
	{
		$tables = $db_connection->query( 'SHOW TABLES' );
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to retrieve database table list.', $e->getMessage() );
	}
	
	$key = 'Tables_in_'.$dbname;

	// Dump each table's data.
	$total_tables = $tables->rowCount();
	$current_table_count = 1;
	while( $table = $tables->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT) )
	{
		$table_name = $table[ $key ];

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo2( "Dumping table data [$n of $total_tables] $table_name\n" );

		dump_table_data( $table_name );
		$current_table_count++;
	}

	$tables = null;
}
endif;


/**
 * Dump a table's structure (through INSERT commands) to a SQL file.
 * @param   string  $table_name  The name of the table.
 */
if( !function_exists('dump_table_data') ):
function dump_table_data( $table_name )
{
	global $db_connection, $dump_path, $delimiter, $select_limit;
	
	$dump_file = "$dump_path/$table_name.sql";

	// Get row count
	try
	{
		$rc_query = $db_connection->query( "SELECT COUNT(*) FROM $table_name" );
		$row_count = $rc_query->fetchColumn(0);
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to retrieve the row count for table "'.$table_name.'".', $e->getMessage() );
	}

	$rc_query = null;
	
	// Get table data
	for( $i = 0; $i < $row_count; $i += $select_limit )
	{
		// Get table data
		try
		{
			$data = $db_connection->query( "SELECT * FROM $table_name LIMIT $select_limit OFFSET $i" );
		}
		catch( PDOException $e )
		{
			script_die( 'Unable to retrieve content for table "'.$table_name.'".', $e->getMessage() );
		}
	
		// Process each row.
		while( $d = $data->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT) )
		{
			$insert_statement = create_insert_statement( $table_name, $d );
			file_put_contents( $dump_file, "$insert_statement;$delimiter", FILE_APPEND );
		}

		$data = null;
	}
}
endif;


/**
 * Create an insert SQL statement for a row of data in a table.
 * @param   string  $table_name  The name of table.
 * @param   array   $row         An array of key/value pairs that represent the row data.
 * @return  string  The constructed insert SQL statement.
 */
if( !function_exists('create_insert_statement') ):
function create_insert_statement( $table_name, &$row )
{
	global $db_connection;
	
	$fields = array();
	foreach( $row as $key => &$value )
	{
		$v = $value;
		if( is_null($value) )
			$v = 'NULL';
		elseif( true === $value )
			$v = 'true';
		elseif( false === $value )
			$v = 'false';
		elseif( is_numeric_column($table_name, $key) )
			$v = $value;
		else
			$v = $db_connection->quote( $value );
		
		$fields[] = "`$key`=$v";
	}
	
	$insert_sql = "INSERT INTO `$table_name` SET ".implode(',',$fields);
	return $insert_sql;
}
endif;
	

//========================================================================================
//============================================================================= MAIN =====

// Include the required functions.
require_once( __DIR__.'/functions.php' );


print_header( 'Dumping database started' );


// Process args.
process_args();


// Include the custom config data.
$args_config = $config;
if( !empty($config['config']) && file_exists($config['config']) )
	require_once( $config['config'] );
merge_config( $config, $args_config );


// Verify that all the config values are valid.
verify_config_values();
if( !is_numeric($config['select_limit']) )
	script_die( 'The select_limit must be integer.' );
if( intval($config['select_limit']) <= 0 )
	script_die( 'The select_limit must be a positive integer greater than zero.' );
$config['select_limit'] = intval( $config['select_limit'] );


// Extract config into individual global variables.
extract($config);


main();


print_header( 'Dumping database ended' );
