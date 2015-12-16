<?php
/**
 * Copies the dump files from a remote server and imports the contents into a local
 * database.
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
	
	// SSL settings for the server for the WP install that is being duplicated.
	'remote_server' 	=> '',
	'remote_username' 	=> '',
	
	// Full path of the folder to copy dump files from on the remote server.
	'remote_dump_path'	=> '',
	
	// WordPress table prefix.
	'wp_prefix'			=> 'wp_',	
	
	// Delimiter used to parse out SQL statements in the dump files.
	'delimiter'			=> "\n",

	// Max number of rows to process at once when find and replacing.
	// Try decreasing this number if "Allowed memory size" errors occur.
	'select_limit'		=> 100,

	// Changes to the domain and path from remote server to local install.
	// These values will also be used in the find and replace section.
	'domain_changes'	=> array(

		// 'remote_domain/path' 	=>  'local_domain/path',

	),

	// Find and replace values.
	// The key is the find and value is the replace.
	'find_replace'		=> array(

		// remote WordPress folder path to local WordPress folder path
		// '_remote_wordpress_directory_full_path_' => '_local_wordpress_directory_full_path_',

	),
	
	// The full path to the folder that contains WinSCP.com file.
	'winscp_folder'		=> '',

	// The relative or full path to the config file.
	'config'			=> 'config_import_db.php',

	// The relative or full path to the log file.
	'log'				=> '',
);


/*
 * Parses the find_replace and domain_changes values.  See below function for use.
 * @param   string  $key     The config key.
 * @param   array   $config  The array of values to parse.
 */
if( !function_exists('parse_config_values') ):
function parse_config_values( $key, &$config )
{
	if( !is_array($config) ) $config = array( $config );

	$keys = array_keys( $config );
	for( $i = 0; $i < count($keys); $i++ )
	{
		$key = $keys[$i];
		if( is_int($key) )
		{
			$value = $config[$key];
			unset( $config[$key] );
			
			$parts = explode( '=>', $value );
			if( count($parts) < 2 )
			{
				switch( $key )
				{
					case 'find_replace':
						script_die( 'The find_replace argument needs to be the following format: --find_repalce="find => replace"' );
						break;
					case 'domain_changes':
						script_die( 'The domain_changes argument needs to be the following format: --domain_changes="remote_domain/path => local_domain/path"' );
						break;
				}
			}

			$config[$parts[0]] = $parts[1];
		}
	}
}
endif;


/**
 * The main function of the script.
 */
if( !function_exists('main') ):
function main()
{
	clear_log();

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
 * Copy files from the remote dump folder to the local dump folder.
 */
if( !function_exists('copy_remote_dump_folder') ):
function copy_remote_dump_folder()
{
	global $remote_username, $remote_server, $remote_dump_path, $dump_path;
	$timer = new Timer;
	$timer->start();
	$return_value = 0;
	
	// Copy dump files from remote location
	echo2( "\nCopying dump files.\n" );
	if( !is_windows() )
	{
		passthru( "rsync -azP '$remote_username@$remote_server:$remote_dump_path/' $dump_path", $return_value );
	}
	else
	{
		$winscp_path = get_winscp_path();
		if( !$winscp_path )
		{
			script_die( 
				'Unable to find WinSCP.com install.', 
				'Please download and install WinSCP from winscp.net.', 
				'You can install the full version or unzip the portable version into the script folder.'
			);
		}

		passthru( "$winscp_path\winscp.com /command \"option batch abort\" \"option confirm off\" \"option reconnecttime 600\" \"open scp://$remote_username@$remote_server\" \"synchronize local -resumesupport=on $dump_path $remote_dump_path\" \"close\" \"close\"", $return_value );
	}

	if( $return_value !== 0 )
	{
		script_die( 'The copy encountered an error and the script needs to stop.' );
	}

	echo2( "\nCopying the dump files took {$timer->get_elapsed_time()} seconds.\n\n" );
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


/**
 * Performs find and replace on all data in the local database.
 */
if( !function_exists('find_and_replace') ):
function find_and_replace()
{
	global $db_connection, $dbname;
	$timer = new Timer;
	$timer->start();
	
	echo2( "\nFind and replacing the table data.\n" );
	
	if( !$db_connection )
		script_die( 'Must connect to database before find and replacing in the tables.' );
	
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
	foreach( $tables->fetchAll(PDO::FETCH_ASSOC) as $table )
	{
		$table_name = $table[ $key ];

		$n = str_pad( $current_table_count, strlen(''.$total_tables), '0', STR_PAD_LEFT );
		echo2( "Find and Replace [$n of $total_tables] $table_name\n" );

		find_and_replace_table_data( $table_name );
		$current_table_count++;
	}

	echo2( "\nPerforming the find and replace took {$timer->get_elapsed_time()} seconds.\n\n" );
}
endif;


/**
 * Performs find and replace on all data in the table.
 * @param   string  $table_name  The name of the table to process.
 */
if( !function_exists('find_and_replace_table_data') ):
function find_and_replace_table_data( $table_name )
{
	global $db_connection, $select_limit, $current_row;
	
	$primary_key = get_table_primary_key( $table_name );
	
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
	
	// Get table data
	for( $i = 0; $i < $row_count; $i += $select_limit )
	{
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
			if( empty($d[$primary_key]) )
			{
				add_error(
					$table_name,
					'Invalid primary value.',
					"\n".print_r($d, true)
				);
				continue;
			}

			$current_row = array(
				'id'	=> $d[$primary_key],
				'data'	=> $d,
			);
			
			$is_changed = false;
			foreach( $d as $column_name => &$value )
			{
				if( $column_name === $primary_key ) continue;
				if( !is_string($value) ) continue;
			
				find_and_replace_column( $table_name, $d[$primary_key], $column_name, $value, $is_changed );
			}
		
			if( $is_changed )
				update_row( $table_name, $primary_key, $d );
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
if( !function_exists('find_and_replace_column') ):
function find_and_replace_column( $table_name, $row_id, $column_name, &$value, &$is_changed )
{
	global $wp_prefix, $domain_changes, $current_row;
	
	switch( $table_name )
	{
		case $wp_prefix.'site':
		case $wp_prefix.'blogs':
			switch( $column_name )
			{
				case 'domain':
					foreach( $domain_changes as $domain_change )
					{
						if( $value == $domain_change['domain']['find'] )
						{
							$is_changed = true;
							$value = $domain_change['domain']['replace'];
						}
					}
					return;
				case 'path':
					foreach( $domain_changes as $domain_change )
					{
						if( $current_row['data']['domain'] == $domain_change['domain']['find'] )
						{
							if( $domain_change['path']['find'] == $domain_change['path']['replace'] )
								continue;
							
							if( $table_name == $wp_prefix.'site' && 
								$value == $domain_change['path']['find'] )
							{
								$is_changed = true;
								$value = $domain_change['path']['replace'];
							}
							elseif( $table_name == $wp_prefix.'blogs')
							{
								$is_changed = true;
								$value = str_replace_first(
									$domain_change['path']['find'],
									$domain_change['path']['replace'],
									$value
								);
							}
						}
					}
					return;
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
		add_error(
			$table_name,
			$e->getMessage(),
			"\n".print_r($row, true)
		);
//		script_die( 'Unable to update row "'.$primary_field.'" for table "'.$table_name.'".', $e->getMessage() );
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
		script_die( 'Unable to retrieve the primary key for table "'.$table_name.'".', $e->getMessage() );
	}
	
	return $column_name;
}
endif;


//========================================================================================
//============================================================================= MAIN =====

// Include the required functions.
require_once( __DIR__.'/functions.php' );


print_header( 'Importing database started' );


// Process args.
process_args();


// Include the custom config data.
$args_config = $config;
if( !empty($config['config']) && file_exists($config['config']) )
	require_once( $config['config'] );
merge_config( $config, $args_config );


// Verify that all the config values are valid.
verify_config_values(
	array('winscp_folder'),
	array('domain_changes', 'find_replace')
);

if( !is_numeric($config['select_limit']) )
	script_die( 'The select_limit must be integer.' );
if( intval($config['select_limit']) <= 0 )
	script_die( 'The select_limit must be a positive integer greater than zero.' );
$config['select_limit'] = intval( $config['select_limit'] );
$config['winscp_folder'] = realpath($config['winscp_folder']);


// Parse find_replace values.
parse_config_values( 'find_replace', $config['find_replace'] );


// Parse domain_changes values.
parse_config_values( 'domain_changes', $config['domain_changes'] );
$keys = array_keys( $config['domain_changes'] );
foreach( $config['domain_changes'] as $find => $replace )
{
	unset( $config['domain_changes'][$find] );

	$find = preg_replace( "/(https?:)?\/\//i", '', trim($find) );
	$replace = preg_replace( "/(https?:)?\/\//i", '', trim($replace) );

	$fpu = parse_url( 'http://'.$find );
	$rpu = parse_url( 'http://'.$replace );

	if( !$fpu )
	{
		script_die( 'Unable to parse url: '.$find );
	}
	
	if( !$rpu )
	{
		script_die( 'Unable to parse url: '.$replace );
	}

	if( !isset($fpu['path']) )
		$fpu['path'] = '';
	elseif( substr($fpu['path'], -1) === '/' )
		$fpu['path'] = substr( $fpu['path'], 0, strlen($fpu['path'])-1 );
	
	if( !isset($rpu['path']) )
		$rpu['path'] = '';
	elseif( substr($rpu['path'], -1) === '/' )
		$rpu['path'] = substr( $rpu['path'], 0, strlen($rpu['path'])-1 );

	$domain_change = array();
	$domain_change['domain'] = array(
		'find' => $fpu['host'],
		'replace' => $rpu['host'],
	);
	$domain_change['path'] = array(
		'find' => $fpu['path'].'/',
		'replace' => $rpu['path'].'/',
	);

	$config['domain_changes'][] = $domain_change;
	$config['find_replace']['//'.$fpu['host'].$fpu['path']] = '//'.$rpu['host'].$rpu['path'];
}


// Extract config into individual global variables.
extract($config);


main();


print_header( 'Importing database ended' );
