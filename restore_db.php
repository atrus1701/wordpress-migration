<?php
/**
 * Restores the local database from the dump files on the local server.
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
	'config'			=> 'config_restore_db.php',

	// The relative or full path to the log file.
	'log'				=> '',
);


/**
 * The main function of the script.
 */
if( !function_exists('main') ):
function main()
{
	sql_connect();
	
	drop_existing_tables();
	import_data();
	
	sql_close();
	
	print_errors();
}
endif;


/**
 * Drop all tables in the specified schema of the local database.
 */
if( !function_exists('drop_existing_tables') ):
function drop_existing_tables()
{
	global $db_connection, $dbname, $total_tables, $current_table_count;
	$timer = new Timer;
	$timer->start();
	
	echo2( "\nDropping existing database tables.\n\n" );
	
	if( !$db_connection )
		script_die( 'Must connect to database before dropping the tables.' );
	
	// Get table listing
	try
	{
		$tables = $db_connection->query( 'SHOW TABLES' );
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to retrieve database table list.' );
	}
	
	$key = 'Tables_in_'.$dbname;

	// Dump each table's data.
	$total_tables = $tables->rowCount();
	$current_table_count = 1;
	while( $table = $tables->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT) )
	{
		$table_name = $table[ $key ];

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo2( "Dropping table [$n of $total_tables] $table_name\n" );

		drop_table( $table_name );
		$current_table_count++;
	}

	echo2( "\nDropping the tables took {$timer->get_elapsed_time()} seconds.\n\n" );
}
endif;


/**
 * Drop a table.
 * @param   string  $table_name  The name of the table to drop.
 */
if( !function_exists('drop_table') ):
function drop_table( $table_name )
{
	global $db_connection;
	
	try
	{
		$data = $db_connection->query( "DROP TABLE `$table_name`" );
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to drop table "'.$table_name.'".', $e->getMessage() );
	}
}
endif;


/**
 * Import data from the SQL files.
 */
if( !function_exists('import_data') ):
function import_data()
{
	global $db_connection, $dump_path;
	$timer = new Timer;
	$timer->start();
	
	echo2( "\nImporting table data.\n\n" );

	if( !$db_connection )
		script_die( 'Must connect to database before importing the table data.' );

	// Import each table's data
	$files = glob( "$dump_path/*.sql" );
	$total_tables = count($files);
	$current_table_count = 1;
	foreach( $files as $file )
	{
		$table_name = basename( $file, '.sql' );

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo2( "Importing table [$n of $total_tables] $table_name\n" );

		import_table_data( $table_name );
		$current_table_count++;
	}

	echo2( "\nImporting the table data took {$timer->get_elapsed_time()} seconds.\n\n" );
}
endif;


/**
 * Import data from the table's SQL file.
 * @param   string  $table_name  The name of the table to import.
 */
if( !function_exists('import_table_data') ):
function import_table_data( $table_name )
{
	global $db_connection, $dump_path, $delimiter;
	
	$dump_file = "$dump_path/$table_name.sql";
	
	// Get table data from file
	$previous_chunk = '';
	try
	{
		$handle = fopen( $dump_file, 'r' );
		while( !feof($handle) )
		{
			$chunk = fread( $handle, 4096 );
			$chunk = ''.$chunk;
		
			$chunk = $previous_chunk.$chunk;
			$new_rows = explode( $delimiter, $chunk );
			$previous_chunk = array_pop( $new_rows );
		
			foreach( $new_rows as $row )
			{
				execute_query( $row );
			}
		}
	
		$new_rows = explode( $delimiter, $previous_chunk );
		foreach( $new_rows as $row )
		{
			execute_query( $row );
		}
	
		fclose($handle);

	}
	catch( Exception $e )
	{
		script_die( 'Error while processing the SQL file "'.$dump_file.'".', $e->getMessage() );
	}
}
endif;


/**
 * Execute a SQL query statement.
 * @param   string  $query  The SQL query to execute.
 */
if( !function_exists('execute_query') ):
function execute_query( $query )
{
	global $db_connection;
	
	if( empty($query) ) return;

	try
	{
		$data = $db_connection->query( $query );
	}
	catch( PDOException $e )
	{
		script_die( 'Unable to excecute query "'.$query.'".', $e->getMessage() );
	}
}
endif;


//========================================================================================
//============================================================================= MAIN =====

// Include the required functions.
require_once( __DIR__.'/functions.php' );


print_header( 'Restoring database started' );


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


print_header( 'Restoring database ended' );

