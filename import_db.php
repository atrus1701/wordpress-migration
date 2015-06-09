<?php
/**
 * Copies the dump files from a remote server and imports the contents into a local
 * database.
 * 
 * @package    wordpress-migrate
 * @author     Crystal Barton <cbarto11@uncc.edu>
 */


// Configuration settings.
// Empty strings are expected to be filled by the time verify_config_values is called.
$config = array(
	
	// Database settings for local database.
	'dbhost' 			=> '',
	'dbusername' 		=> '',
	'dbpassword' 		=> '',
	'dbname'			=> '',
	
	// Path to folder to transfer dump files to on local server.
	'dump_path'			=> '',
	
	// WordPress domain and path of local WordPress install.
	'domain'			=> '',
	'path'				=> '',
	
	// SSL settings for origin server.
	'remote_server' 	=> '',
	'remote_username' 	=> '',
	
	// Path to folder to transfer dump files from on origin server.
	'remote_dump_path'	=> '',
	
	// WordPress domain and path of origin WordPress install.
	'remote_domain'		=> '',
	'remote_path'		=> '',
	
	// Delimiter used to parse out SQL statements in the dump files.
	'delimiter'			=> "\n",
	
	// Max number of rows to process at once when find and replacing.
	// Decrease this number if "Allowed memory size" errors occur.
	'select_limit'		=> 100,

	// Find and replace values.
	// The key is the find and value is the replace.
	'find_replace'		=> array(

		// '//old_domain/old_path'	=> '//new_domain/new_path',
		// '/old/public/html/path'	=> '/new/public/html/path',

	),	
);


// Include general config data.
if( file_exists(dirname(__FILE__).'/config.php') )
	require_once( dirname(__FILE__).'/config.php' );

// Include the custom config data for the import_db script.
if( file_exists(dirname(__FILE__).'/config_import_db.php') )
	require_once( dirname(__FILE__).'/config_import_db.php' );


// Include the required functions.
require_once( dirname(__FILE__).'/functions.php' );


// Process args and verify config values.
process_args();
verify_config_values();
if( !is_int($config['select_limit']) )
	die( "The select_limit must be integer.\n\n" );
if( intval($config['select_limit']) <= 0 )
	die( "The select_limit be a positive integer greater than zero.\n\n" );
$config['select_limit'] = intval( $config['select_limit'] );


// Parse find_replace values, if necessary.
if( is_string($config['find_replace']) )
{
	$find_replace = array();
	
	$fr_pairs = explode( ';', $config['find_replace'] );
	foreach( $fr_pairs as &$fr_pair )
	{
		if( $fr_pair == '' ) continue;
		
		$fr_pair = explode( '=>', $fr_pair );
		if( count($fr_pair) < 2 ) continue;
		
		$fr_keys = explode( ',', $fr_pair[0] );
		foreach( $fr_keys as $fr_key )
		{
			$find_replace[trim($fr_key)] = trim($fr_pair[1]);
		}
	}
	
	$config['find_replace'] = $find_replace;
}

// var_dump($config);
// exit();


// Extract config into individual global variables.
extract($config);


/**
 * The main function of the script.
 */
if( !function_exists('main') ):
function main()
{
	clear_dump_folder();
 	copy_remote_dump_folder();
	
	sql_connect();
	
	drop_existing_tables();
	import_data();
	find_and_replace();
	
	sql_close();
	
	print_errors();
}
endif;


/**
 * Clears all files in the dump folder.
 */
if( !function_exists('clear_dump_folder') ):
function clear_dump_folder()
{
	global $dump_path;
	
	// Create dump directory
	echo "\nCreating dump directory.\n";
	if( !is_dir($dump_path) )
	{
		if( !mkdir($dump_path) )
			die( "Unable to create dump folder.\n\n" );
	}

	// Clear out dump directory
	delete_with_wildcard( "$dump_path/*" );		
}
endif;
	
	
/**
 * Copy files from the remote dump folder to the local dump folder.
 */
if( !function_exists('copy_remote_dump_folder') ):
function copy_remote_dump_folder()
{
	global $remote_username, $remote_server, $remote_dump_path, $dump_path;
	
	// Copy dump files from remote location
	echo "\nCopying dump files.\n";
	exec( "scp $remote_username@$remote_server:$remote_dump_path/* $dump_path", $output, $return_var );
	
	if( $return_var != 0 )
	{
		die( "Unable to copy dump files.\n\n" );
	}
}
endif;

	
/**
 * Drop all tables in the specified schema of the local database.
 */
if( !function_exists('drop_existing_tables') ):
function drop_existing_tables()
{
	global $db_connection, $dbname, $total_tables, $current_table_count;
	
	echo "\nDropping existing database tables.\n\n";
	
	if( !$db_connection )
		die( "Must connect to database before dropping the tables.\n" );
	
	// Get table listing
	try
	{
		$tables = $db_connection->query( 'SHOW TABLES' );
	}
	catch( PDOException $e )
	{
		die( "Unable to retrieve database table list.\n\n" );
	}
	
	$key = 'Tables_in_'.$dbname;

	// Dump each table's data.
	$total_tables = $tables->rowCount();
	$current_table_count = 1;
	while( $table = $tables->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT) )
	{
		$table_name = $table[ $key ];

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo "Dropping table [$n of $total_tables] $table_name\n";

		drop_table( $table_name );
		$current_table_count++;
	}
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
		die( "Unable to drop table '$table_name'.\n".$e->getMessage()."\n\n" );
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
	
	echo "\nImporting table data.\n\n";

	if( !$db_connection )
		die( "Must connect to database before importing the table data.\n" );

	// Import each table's data
	$files = glob( "$dump_path/*.sql" );
	$total_tables = count($files);
	$current_table_count = 1;
	foreach( $files as $file )
	{
		$table_name = basename( $file, '.sql' );

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo "Importing table [$n of $total_tables] $table_name\n";

		import_table_data( $table_name );
		$current_table_count++;
	}
}
endif;


/**
 * Import data from the table's SQL file.
 * @param   string  $table_name  The name of the table to import.
 */
if( !function_exists('') ):
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
		die( "Error while processing the SQL file '$dump_file'.\n".$e->getMessage()."\n\n" );
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
		die( "Unable to excecute query '$query'.\n".$e->getMessage()."\n\n" );
	}
}
endif;


/**
 * Performs find and replace on all data in the local database.
 */
if( !function_exists('find_and_replace') ):
function find_and_replace()
{
	global $db_connection, $dbname;
	
	echo "\nFind and replacing the table data.\n";
	
	if( !$db_connection )
		die( "Must connect to database before find and replacing in the tables.\n" );
	
	// Get table listing
	try
	{
		$tables = $db_connection->query( 'SHOW TABLES' );
	}
	catch( PDOException $e )
	{
		die( "Unable to retrieve database table list.\n".$e->getMessage()."\n\n" );
	}
	
	$key = 'Tables_in_'.$dbname;

	// Dump each table's data.
	$total_tables = $tables->rowCount();
	$current_table_count = 1;
	foreach( $tables->fetchAll(PDO::FETCH_ASSOC) as $table )
	{
		$table_name = $table[ $key ];

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo "Find and Replace [$n of $total_tables] $table_name\n";

		find_and_replace_table_data( $table_name );
		$current_table_count++;
	}
// 	$find_and_replace_table_data( 'wp_clas_uncc_signups' );
}
endif;


/**
 * Performs find and replace on all data in the table.
 * @param   string  $table_name  The name of the table to process.
 */
if( !function_exists('find_and_replace_table_data') ):
function find_and_replace_table_data( $table_name )
{
	global $db_connection, $select_limit;
	
	$primary_key = get_table_primary_key( $table_name );
	
	// Get row count
	try
	{
		$rc_query = $db_connection->query( "SELECT COUNT(*) FROM $table_name" );
		$row_count = $rc_query->fetchColumn(0);
	}
	catch( PDOException $e )
	{
		die( "Unable to retrieve the row count for table '$table_name'.\n".$e->getMessage()."\n\n" );
	}
	
	// Get table data
	for( $i = 0; $i < $row_count; $i += $select_limit )
	{
		try
		{
			$data = $db_connection->query( "SELECT * FROM $table_name LIMIT $select_limit OFFSET $i" );
		}
		catch( PDOException $e )
		{
			die( "Unable to retrieve content for table '$table_name'.\n".$e->getMessage()."\n\n" );
		}
	
		// Process each row.
		while( $d = $data->fetch(PDO::FETCH_ASSOC, PDO::FETCH_ORI_NEXT) )
		{
			if( empty($d[$primary_key]) )
			{
				add_error(
					$table_name,
					'Invalid primary value.',
					"\n".print_r($d, true)
				);
				continue;
			}
			
			$is_changed = false;
			foreach( $d as $column_name => &$value )
			{
				if( $column_name === $primary_key ) continue;
				if( !is_string($value) ) continue;
			
				find_and_replace_column( $table_name, $d[$primary_key], $column_name, $value, $is_changed );
			}
		
			if( $is_changed ) update_row( $table_name, $primary_key, $d );
		}
	}
}
endif;


/**
 * Find and replaces only if the value is at the beginning of the string.
 * @param   string  $find     The value to search for.
 * @param   string  $replace  The value to replace the find with.
 * @param   mixed   $value    The value to process.
 * @return  string  The processed value.
 */
if( !function_exists('str_replace_first') ):
function str_replace_first( $find, $replace, $value )
{
	if( strpos($value, $find) === 0 )
	{
		return $replace . substr( $value, count($find) );
	}
	return $value;
}
endif;


/**
 * Performs find and replace on a column value.
 * @param   string  $table_name   The name of the table that the value came from.
 * @param   string  $row_id       The row's primary key's value.
 * @param   string  $column_name  The column of the value from the table.
 * @param   mixed   $value        The value to process.
 * @param   string  $is_changed   Set to true if the value is changed, indicating an 
 *                                update is needed.
 */
if( !function_exists('') ):
function find_and_replace_column( $table_name, $row_id, $column_name, &$value, &$is_changed )
{
	global $domain, $path, $remote_domain, $remote_path;
	
	switch( $table_name )
	{
		case 'wp_site':
			switch( $column_name )
			{
				case 'domain':
					if( $domain != $remote_domain )
					{
						$is_changed = true;
						$value = $domain;
					}
					return;
				case 'path':
					if( $path != $remote_path )
					{
						$is_changed = true;
						$value = $path;
					}
					return;
			}
			break;
		
		case 'wp_blogs':
			switch( $column_name )
			{
				case 'domain':
					if( $domain != $remote_domain )
					{
						$is_changed = true;
						$value = $domain;
					}
					return;
				case 'path':
					if( $path != $remote_path )
					{
						$is_changed = true;
						$value = str_replace_first( $remote_path, $path, $value );
					}
					return;
			}
			break;
	}
	
	switch( $column_name )
	{
		case 'domain':
			if( $domain != $remote_domain )
			{
				$is_changed = true;
				$value = str_replace_first( $remote_domain, $domain, $value );
			}
			break;
		case 'path':
			if( $path != $remote_path )
			{
				$is_changed = true;
				$value = str_replace_first( $remote_path, $path, $value );
			}
			break;
	}
	
	find_and_replace_value( $table_name, $row_id, $column_name, $value, $is_changed );
}
endif;


/**
 * Performs find and replace on a value, not necessary the full column value.
 * @param   string  $table_name   The name of the table that the value came from.
 * @param   string  $row_id       The row's primary key's value.
 * @param   string  $column_name  The column of the value from the table.
 * @param   mixed   $value        The value to process.
 * @param   string  $is_changed   Set to true if the value is changed, indicating an 
 *                                update is needed.
 */
if( !function_exists('find_and_replace_value') ):
function find_and_replace_value( $table_name, $row_id, $column_name, &$value, &$is_changed )
{
	if( is_array($value) || is_object($value) )
	{
		find_and_replace_object( $table_name, $row_id, $column_name, $value, $is_changed );
	}
	elseif( is_string($value) )
	{
		if( is_serialized($value) )
		{
			find_and_replace_serialized_data( $table_name, $row_id, $column_name, $value, $is_changed );
		}
		else
		{
			find_and_replace_string( $table_name, $row_id, $column_name, $value, $is_changed );
		}
	}
}
endif;


/**
 * Performs find and replace on a string that is serializable.
 * @param   string  $table_name   The name of the table that the value came from.
 * @param   string  $row_id       The row's primary key's value.
 * @param   string  $column_name  The key or column of the value from the table.
 * @param   mixed   $value        The value to process.
 * @param   string  $is_changed   Set to true if the value is changed, indicating an 
 *                                update is needed.
 */
if( !function_exists('find_and_replace_serialized_data') ):
function find_and_replace_serialized_data( $table_name, $row_id, $column_name, &$value, &$is_changed )
{
	$serialized_data = @unserialize( $value );
	
	if( is_array($serialized_data) || is_object($serialized_data) )
	{
		find_and_replace_value( $table_name, $row_id, $column_name, $serialized_data, $is_changed );
		$value = serialize( $serialized_data );
		return;
	}
	
	if( is_a($serialized_data, '__PHP_Incomplete_Class') )
	{
		$serialized_array = array();
		foreach( $serialized_data as $k => &$v )
		{
			$serialized_array[$k] = $v;
		}
		
		$class_name = $serialized_array['__PHP_Incomplete_Class_Name'];
		unset( $serialized_array['__PHP_Incomplete_Class_Name'] );
		
		find_and_replace_value( $table_name, $row_id, $column_name, $serialized_array, $is_changed );
		
		$serialized_class_data = substr( serialize($serialized_array), 1 );
		$value = 'O:'.count($class_name).':"'.$class_name.'"'.$serialized_class_data;
		return;
	}

	if( $serialized_data === false )
	{
		if( $value !== serialize(false) )
		{
			add_error(
				$table_name,
				$row_id,
				$column_name,
				'Unable to unserialize all or part of column data.',
				"\n$value\n"
			);
		}
		return;
	}
	if( $serialized_data === true ) return;
	if( is_numeric($serialized_data) ) return;

	add_error(
		$table_name,
		$row_id,
		$column_name,
		'All or part of column data is an unknown type of serialized data.',
		"\n$value\n"
	);
}
endif;


/**
 * Performs find and replace on a array or object value.
 * @param   string  $table_name   The name of the table that the value came from.
 * @param   string  $row_id       The row's primary key's value.
 * @param   string  $column_name  The column of the value from the table.
 * @param   mixed   $value        The value to process.
 * @param   string  $is_changed   Set to true if the value is changed, indicating an 
 *                                update is needed.
 */
if( !function_exists('find_and_replace_object') ):
function find_and_replace_object( $table_name, $row_id, $column_name, &$value, &$is_changed )
{
	foreach( $value as $k => &$v )
	{
		find_and_replace_value( $table_name, $row_id, $column_name, $v, $is_changed );
	}
}
endif;


/**
 * Performs find and replace on a non-serializable string value.
 * @param   string  $table_name   The name of the table that the value came from.
 * @param   string  $row_id       The row's primary key's value.
 * @param   string  $column_name  The column of the value from the table.
 * @param   mixed   $value        The value to process.
 * @param   string  $is_changed   Set to true if the value is changed, indicating an 
 *                                update is needed.
 */
if( !function_exists('find_and_replace_string') ):
function find_and_replace_string( $table_name, $row_id, $column_name, &$value, &$is_changed )
{
	global $find_replace;
	
	foreach( $find_replace as $find => $replace )
	{
		if( strpos($value, $find) !== false )
		{
			$value = str_replace( $find, $replace, $value );
			$is_changed = true;
		}
	}
}
endif;


/**
 * Update the data of a table's row.
 * @param   string  $table_name   The name of the table.
 * @param   string  $primary_key  The key/column that is the primary key.
 * @param   array   $row          The row's data.
 */
if( !function_exists('update_row') ):
function update_row( $table_name, $primary_key, $row )
{
	global $db_connection;
	
	if( empty($row[$primary_key]) )
	{
		add_error(
			$table_name,
			'Invalid primary value.',
			"\n".print_r($row, true)
		);
		return;
	}
	
	$primary_value = $row[$primary_key];
	unset( $row[$primary_key] );
	
	$fields = array();
	foreach( $row as $column_name => &$value )
	{
		$v = $value;
		if( is_null($value) )
			$v = 'NULL';
		elseif( true === $value )
			$v = 'true';
		elseif( false === $value )
			$v = 'false';
		elseif( is_numeric($value) )
			$v = $value;
		else
			$v = $db_connection->quote( $value );
		
		$fields[] = "`$column_name`=$v";
	}
	
	if( !is_numeric($primary_value) )
		$primary_value = $db_connection->quote( $primary_value );
	
	$primary_field = "`$primary_key`=$primary_value";
	
	$update_sql = "UPDATE `$table_name` SET ".implode(',',$fields)." WHERE $primary_field;";
	
	// Update table row
	try
	{
		$data = $db_connection->query( $update_sql );
	}
	catch( PDOException $e )
	{
		die( "Unable to update row '$primary_field' for table '$table_name'.\n".$e->getMessage()."\n\n" );
	}
}
endif;


/**
 * Get the primary key of a table.
 * @param   string  $table_name   The name of the table.
 * @return  string  The key/column name that is the primary key.
 */
if( !function_exists('get_table_primary_key') ):
function get_table_primary_key( $table_name )
{
	global $db_connection, $dbname;
	$column_name = null;
	
	// Get table primary key
	try
	{
		$primary_key = $db_connection->query(
			"SELECT `COLUMN_NAME` 
			 FROM `information_schema`.`COLUMNS` 
			 WHERE (`TABLE_SCHEMA`='$dbname')
			   AND (`TABLE_NAME`='$table_name')
			   AND (`COLUMN_KEY`='PRI')"
		);
		$column_name = $primary_key->fetchColumn(0);
	}
	catch( PDOException $e )
	{
		die( "Unable to retrieve the primary key for table '$table_name'.\n".$e->getMessage()."\n\n" );
	}
	
	return $column_name;
}
endif;


//========================================================================================
//============================================================================= MAIN =====

main();

