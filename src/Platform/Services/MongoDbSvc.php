<?php
/**
 * This file is part of the DreamFactory Services Platform(tm) (DSP)
 *
 * DreamFactory Services Platform(tm) <http://github.com/dreamfactorysoftware/dsp-core>
 * Copyright 2012-2013 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Platform\Services;

use Kisma\Core\Utility\FilterInput;
use Kisma\Core\Utility\Option;
use Platform\Exceptions\BadRequestException;
use Platform\Exceptions\InternalServerErrorException;
use Platform\Exceptions\NotFoundException;
use Platform\Utility\DataFormat;

/**
 * MongoDbSvc.php
 *
 * A service to handle MongoDb NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class MongoDbSvc extends NoSqlDbSvc
{
	//*************************************************************************
	//	Constants
	//*************************************************************************

	/**
	 * Default record identifier field
	 */
	const DEFAULT_ID_FIELD = '_id';

	//*************************************************************************
	//	Members
	//*************************************************************************

	/**
	 * @var \MongoDB|null
	 */
	protected $_dbConn = null;

	//*************************************************************************
	//	Methods
	//*************************************************************************

	/**
	 * Create a new MongoDbSvc
	 *
	 * @param array $config
	 *
	 * @throws \InvalidArgumentException
	 * @throws \Exception
	 */
	public function __construct( $config )
	{
		parent::__construct( $config );

		$_credentials = Option::get( $config, 'credentials' );
		$_dsn = Option::get( $_credentials, 'dsn', '' );
		$_db = Option::get( $_credentials, 'db' );
		if ( empty( $_dsn ) )
		{
			$_dsn = 'mongodb://localhost:27017';
			if ( empty( $_db ) )
			{
				throw new InternalServerErrorException( "No MongoDb database selected in configuration." );
			}
		}
		else
		{
			if ( 0 != substr_compare( $_dsn, 'mongodb://', 0, 10, true ) )
			{
				$_dsn = 'mongodb://' . $_dsn;
			}
		}

		$_options = array();
		if ( !empty( $_db ) )
		{
			$_options['db'] = $_db;
		}
		else
		{
			$_db = trim( strstr( substr( $_dsn, strlen( 'mongodb://' ) ), '/' ), '/' );
			if ( empty( $_db ) )
			{
				throw new InternalServerErrorException( "No MongoDb database selected in configuration." );
			}
		}

		$_username = Option::get( $_credentials, 'user' );
		if ( !empty( $_username ) )
		{
			$_options['username'] = $_username;
			$_password = Option::get( $_credentials, 'pwd' );
			if ( !empty( $_password ) )
			{
				$_options['password'] = $_password;
			}
		}

		try
		{
			$_client = new \MongoClient( $_dsn, $_options );
			$this->_dbConn = $_client->selectDB( $_db );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Unexpected MongoDb Service Exception:\n{$ex->getMessage()}" );
		}
	}

	/**
	 * Object destructor
	 */
	public function __destruct()
	{
		try
		{
			$this->_dbConn = null;
		}
		catch ( \Exception $ex )
		{
			error_log( "Failed to disconnect from database.\n{$ex->getMessage()}" );
		}
	}

	/**
	 * @throws \Exception
	 */
	protected function checkConnection()
	{
		if ( !isset( $this->_dbConn ) )
		{
			throw new InternalServerErrorException( 'Database connection has not been initialized.' );
		}
	}

	/**
	 * @param $name
	 *
	 * @return \MongoCollection|null
	 */
	public function selectTable( $name )
	{
		$this->checkConnection();
		$_coll = $this->_dbConn->selectCollection( $name );

		return $_coll;
	}

	/**
	 * @param string $table
	 * @param string $access
	 *
	 * @throws \Exception
	 */
	protected function validateTableAccess( $table, $access = 'read' )
	{

		parent::validateTableAccess( $table, $access );
	}

	/**
	 * @param null|array $post_data
	 *
	 * @return array
	 */
	protected function _gatherExtrasFromRequest( $post_data = null )
	{
		$_extras = parent::_gatherExtrasFromRequest( $post_data );

		return $_extras;
	}

	// REST service implementation

	protected function _getCollectionArray()
	{
		try
		{
			return $this->_dbConn->getCollectionNames();
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to list collections of MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @throws \Exception
	 * @return array
	 */
	protected function _listResources()
	{
		$_out = array();
		$_result = $this->_getCollectionArray();
		foreach ( $_result as $_table )
		{
			$_out[] = array( 'name' => $_table );
		}

		return array( 'resource' => $_out );
	}

	/**
	 *
	 * @param array $tables
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function getTables( $tables = array() )
	{
		if ( empty( $tables ) )
		{
			$tables = $this->_getCollectionArray();
		}
		else
		{
			if ( !is_array( $tables ) )
			{
				$tables = array_map( 'trim', explode( ',', trim( $tables, ',' ) ) );
			}
		}

		$_out = array();
		foreach ( $tables as $_table )
		{
			if ( is_array( $_table ) )
			{
				$_table = Option::get( $_table, 'name' );
			}
			if ( empty( $_table ) )
			{
				throw new BadRequestException( "No 'name' field in data." );
			}
			try
			{
				$_out[] = $this->getTable( $_table );
			}
			catch ( \Exception $ex )
			{
				throw new InternalServerErrorException( "Failed to list containers of MongoDb service.\n" . $ex->getMessage() );
			}
		}

		return $_out;
	}

	/**
	 * Get any properties related to the table
	 *
	 * @param string $table Table name
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function getTable( $table )
	{
		$_coll = $this->selectTable( $table );
		$_out = array( 'name' => $_coll->getName() );
		$_out['indexes'] = $_coll->getIndexInfo();

		return $_out;
	}

	/**
	 * @param array $properties
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function createTable( $properties = array() )
	{
		$_name = Option::get( $properties, 'name' );
		if ( empty( $_name ) )
		{
			throw new BadRequestException( "No 'name' field in data." );
		}

		try
		{
			$_result = $this->_dbConn->createCollection( $_name );
			$_out = array( 'name' => $_result->getName() );
			$_out['indexes'] = $_result->getIndexInfo();

			return $_out;
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to create a container on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * Get any properties related to the table
	 *
	 * @param array $properties
	 *
	 * @return array
	 * @throws \Exception
	 */
	public function updateTable( $properties = array() )
	{
		$_name = Option::get( $properties, 'name' );
		if ( empty( $_name ) )
		{
			throw new BadRequestException( "No 'name' field in data." );
		}

		$this->selectTable( $_name );

//		throw new InternalServerErrorException( "Failed to update table '$table' on MongoDb service." );
		return array( 'name' => $_name );
	}

	/**
	 * @param array $tables
	 * @param bool  $check_empty
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function deleteTables( $tables = array(), $check_empty = false )
	{
		if ( !is_array( $tables ) )
		{
			// may be comma-delimited list of names
			$tables = array_map( 'trim', explode( ',', trim( $tables, ',' ) ) );
		}
		$_out = array();
		foreach ( $tables as $_table )
		{
			if ( is_array( $_table ) )
			{
				$_table = Option::get( $_table, 'name' );
			}
			if ( empty( $_table ) )
			{
				throw new BadRequestException( "No 'name' field in data." );
			}
			try
			{
				$_out[] = $this->deleteTable( $_table, $check_empty );
			}
			catch ( \Exception $ex )
			{
				throw new $ex;
			}
		}

		return $_out;
	}

	/**
	 * Delete the table and all of its contents
	 *
	 * @param string $table
	 * @param bool   $check_empty
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function deleteTable( $table, $check_empty = false )
	{
		try
		{
			$_result = $this->_dbConn->dropCollection( $table );

			return array( 'name' => $table );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to delete table '$table' from MongoDb service.\n" . $ex->getMessage() );
		}
	}

	//-------- Table Records Operations ---------------------
	// records is an array of field arrays

	/**
	 * @param        $table
	 * @param        $records
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function createRecords( $table, $records, $fields = null, $extras = array() )
	{
		if ( empty( $records ) || !is_array( $records ) )
		{
			throw new BadRequestException( 'There are no record sets in the request.' );
		}
		if ( !isset( $records[0] ) )
		{
			// single record possibly passed in without wrapper array
			$records = array( $records );
		}

		$_coll = $this->selectTable( $table );
		$_rollback = Option::get( $extras, 'rollback', false );
		try
		{
			$_result = $_coll->batchInsert( $records, array( 'continueOnError' => !$_rollback ) );
			$_out = static::cleanRecords( $records, $fields );

			return $_out;
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to create items in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @throws BadRequestException
	 * @return array
	 */
	public function createRecord( $table, $record, $fields = null, $extras = array() )
	{
		if ( empty( $record ) || !is_array( $record ) )
		{
			throw new BadRequestException( 'There are no record fields in the request.' );
		}

		$_coll = $this->selectTable( $table );
		$record = static::idToMongoId( $record );
		try
		{
			$_result = $_coll->save( $record ); // same as insert if no _id
			$_out = static::cleanRecord( $record, $fields );

			return $_out;
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to create item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $records
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function updateRecords( $table, $records, $fields = null, $extras = array() )
	{
		if ( empty( $records ) || !is_array( $records ) )
		{
			throw new BadRequestException( 'There are no record sets in the request.' );
		}
		if ( !isset( $records[0] ) )
		{
			// single record possibly passed in without wrapper array
			$records = array( $records );
		}

		$_coll = $this->selectTable( $table );
		$_out = array();
		foreach ( $records as $_record )
		{
			try
			{
				$_record = static::idToMongoId( $_record );
				$_result = $_coll->save( $_record ); // same as update if _id
				$_out[] = static::cleanRecord( $_record, $fields );
			}
			catch ( \Exception $ex )
			{
				throw new InternalServerErrorException( "Failed to update items in '$table' on MongoDb service.\n" . $ex->getMessage() );
			}
		}

		return $_out;
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @throws BadRequestException
	 * @return array
	 */
	public function updateRecord( $table, $record, $fields = null, $extras = array() )
	{
		if ( empty( $record ) || !is_array( $record ) )
		{
			throw new BadRequestException( 'There are no record fields in the request.' );
		}

		$_coll = $this->selectTable( $table );
		$record = static::idToMongoId( $record );
		try
		{
			$_result = $_coll->save( $record ); // same as update if _id

			return static::cleanRecord( $record, $fields );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $filter
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function updateRecordsByFilter( $table, $record, $filter = null, $fields = null, $extras = array() )
	{
		if ( !is_array( $record ) || empty( $record ) )
		{
			throw new BadRequestException( 'There are no fields in the record.' );
		}

		unset( $record[static::DEFAULT_ID_FIELD] ); // make sure the record has no identifier
		$_coll = $this->selectTable( $table );
		// build criteria from filter parameters
		$_criteria = static::buildFilterArray( $filter );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			$_result = $_coll->update( $_criteria, $record, array( 'multiple' => true ) );
			/** @var \MongoCursor $_result */
			$_result = $_coll->find( $_criteria, $_fieldArray );
			$_out = iterator_to_array( $_result );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param string $table
	 * @param array  $record
	 * @param string $id_list
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function updateRecordsByIds( $table, $record, $id_list, $fields = null, $extras = array() )
	{
		if ( !is_array( $record ) || empty( $record ) )
		{
			throw new BadRequestException( "No record fields were passed in the request." );
		}

		if ( empty( $id_list ) )
		{
			throw new BadRequestException( "Identifying values for '_id' can not be empty for update request." );
		}

		$_coll = $this->selectTable( $table );
		$_ids = static::idsToMongoIds( $id_list );
		// build criteria from filter parameters
		$_criteria = array( static::DEFAULT_ID_FIELD => array( '$in' => $_ids ) );
		unset( $record[static::DEFAULT_ID_FIELD] ); // make sure the record has no identifier
		try
		{
			$_result = $_coll->update( $_criteria, $record, array( 'multiple' => true ) );
			$_out = array();
			foreach ( $_ids as $_id )
			{
				$record[static::DEFAULT_ID_FIELD] = $_id;
				$_out[] = static::cleanRecords( $record, $fields );
			}

			return $_out;
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param string $table
	 * @param array  $record
	 * @param        $id
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function updateRecordById( $table, $record, $id, $fields = null, $extras = array() )
	{
		if ( !isset( $record ) || empty( $record ) )
		{
			throw new BadRequestException( 'There are no fields in the record.' );
		}
		if ( empty( $id ) )
		{
			throw new BadRequestException( "No identifier exist in record." );
		}

		$_coll = $this->selectTable( $table );
		$record[static::DEFAULT_ID_FIELD] = static::idToMongoId( $id );
		try
		{
			$result = $_coll->save( $record );

			return static::cleanRecord( $record, $fields );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $records
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function mergeRecords( $table, $records, $fields = null, $extras = array() )
	{
		if ( empty( $records ) || !is_array( $records ) )
		{
			throw new BadRequestException( 'There are no record sets in the request.' );
		}
		if ( !isset( $records[0] ) )
		{
			// single record possibly passed in without wrapper array
			$records = array( $records );
		}

		$_coll = $this->selectTable( $table );
		$_fieldArray = static::buildFieldArray( $fields );
		$_out = array();
		foreach ( $records as $_record )
		{
			try
			{
				$_id = Option::get( $_record, static::DEFAULT_ID_FIELD, null, true );
				if ( empty( $_id ) )
				{
					throw new BadRequestException( "Identifying field '_id' can not be empty for merge record request." );
				}
				$result = $_coll->findAndModify(
					array( static::DEFAULT_ID_FIELD => static::idToMongoId( $_id ) ),
					array( '$set' => $_record ),
					$_fieldArray,
					array( 'new' => true )
				);

				$_out[] = static::mongoIdToId( $result );
			}
			catch ( \Exception $ex )
			{
				throw new InternalServerErrorException( "Failed to update items in '$table' on MongoDb service.\n" . $ex->getMessage() );
			}
		}

		return $_out;
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function mergeRecord( $table, $record, $fields = null, $extras = array() )
	{
		if ( empty( $record ) || !is_array( $record ) )
		{
			throw new BadRequestException( 'There are no record fields in the request.' );
		}

		$_id = Option::get( $record, static::DEFAULT_ID_FIELD, null, true );
		if ( empty( $_id ) )
		{
			throw new BadRequestException( "Identifying field '_id' can not be empty for merge record request." );
		}

		$_coll = $this->selectTable( $table );
		$_criteria = array( static::DEFAULT_ID_FIELD => static::idToMongoId( $_id ) );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			$result = $_coll->findAndModify(
				$_criteria,
				array( '$set' => $record ),
				$_fieldArray,
				array( 'new' => true )
			);

			return static::mongoIdToId( $result );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $filter
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function mergeRecordsByFilter( $table, $record, $filter = null, $fields = null, $extras = array() )
	{
		if ( !is_array( $record ) || empty( $record ) )
		{
			throw new BadRequestException( 'There are no fields in the record.' );
		}

		unset( $record[static::DEFAULT_ID_FIELD] );
		$_coll = $this->selectTable( $table );
		// build criteria from filter parameters
		$_criteria = static::buildFilterArray( $filter );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			$_result = $_coll->update( $_criteria, array( '$set' => $record ), array( 'multiple' => true ) );
			/** @var \MongoCursor $_result */
			$_result = $_coll->find( $_criteria, $_fieldArray );
			$_out = iterator_to_array( $_result );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param string $table
	 * @param array  $record
	 * @param string $id_list
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function mergeRecordsByIds( $table, $record, $id_list, $fields = null, $extras = array() )
	{
		if ( !is_array( $record ) || empty( $record ) )
		{
			throw new BadRequestException( "No record fields were passed in the request." );
		}
		if ( empty( $id_list ) )
		{
			throw new BadRequestException( "Identifying values for '_id' can not be empty for update request." );
		}

		$_coll = $this->selectTable( $table );
		$_ids = static::idsToMongoIds( $id_list );
		$_criteria = array( static::DEFAULT_ID_FIELD => array( '$in' => $_ids ) );
		$_fieldArray = static::buildFieldArray( $fields );
		unset( $record[static::DEFAULT_ID_FIELD] ); // make sure the record has no identifier

		try
		{
			$result = $_coll->update( $_criteria, array( '$set' => $record ), array( 'multiple' => true ) );
			if ( static::_requireMoreFields( $fields ) )
			{
				/** @var \MongoCursor $result */
				$result = $_coll->find( $_criteria, $_fieldArray );
				$_out = iterator_to_array( $result );
			}
			else
			{
				$_out = static::idsAsRecords( $_ids );
			}

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param        $id
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function mergeRecordById( $table, $record, $id, $fields = null, $extras = array() )
	{
		if ( !isset( $record ) || empty( $record ) )
		{
			throw new BadRequestException( 'There are no fields in the record.' );
		}
		if ( empty( $id ) )
		{
			throw new BadRequestException( "No identifier exist in record." );
		}

		$_coll = $this->selectTable( $table );
		$_criteria = array( static::DEFAULT_ID_FIELD => static::idToMongoId( $id ) );
		$_fieldArray = static::buildFieldArray( $fields );
		unset( $record[static::DEFAULT_ID_FIELD] );
		try
		{
			$result = $_coll->findAndModify(
				$_criteria,
				array( '$set' => $record ),
				$_fieldArray,
				array( 'new' => true )
			);

			return static::mongoIdToId( $result );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $records
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array|string
	 */
	public function deleteRecords( $table, $records, $fields = null, $extras = array() )
	{
		if ( !is_array( $records ) || empty( $records ) )
		{
			throw new BadRequestException( 'There are no record sets in the request.' );
		}
		if ( !isset( $records[0] ) )
		{
			// single record
			$records = array( $records );
		}

		$_coll = $this->selectTable( $table );
		$_ids = static::recordsAsIds( $records );
		$_criteria = array( static::DEFAULT_ID_FIELD => array( '$in' => static::idsToMongoIds( $_ids ) ) );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			if ( static::_requireMoreFields( $fields ) )
			{
				/** @var \MongoCursor $result */
				$result = $_coll->find( $_criteria, $_fieldArray );
				$_out = iterator_to_array( $result );
			}
			else
			{
				$_out = static::idsAsRecords( $_ids );
			}

			$result = $_coll->remove( $_criteria );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to delete items from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function deleteRecord( $table, $record, $fields = null, $extras = array() )
	{
		if ( !isset( $record ) || empty( $record ) )
		{
			throw new BadRequestException( 'There are no fields in the record.' );
		}

		$_coll = $this->selectTable( $table );
		$_criteria = static::idToMongoId( $record );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			$result = $_coll->findAndModify( $_criteria, null, $_fieldArray, array( 'remove' => true ) );

			return static::mongoIdToId( $result );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to delete item from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $filter
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function deleteRecordsByFilter( $table, $filter, $fields = null, $extras = array() )
	{
		if ( empty( $filter ) )
		{
			throw new BadRequestException( "Filter for delete request can not be empty." );
		}

		$_coll = $this->selectTable( $table );
		// build criteria from filter parameters
		$_criteria = static::buildFilterArray( $filter );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			/** @var \MongoCursor $result */
			$result = $_coll->find( $_criteria, $_fieldArray );
			$_out = iterator_to_array( $result );
			$result = $_coll->remove( $_criteria );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to update item in '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $id_list
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function deleteRecordsByIds( $table, $id_list, $fields = null, $extras = array() )
	{
		if ( empty( $id_list ) )
		{
			throw new BadRequestException( "Identifying values for '_id' can not be empty for update request." );
		}

		$_coll = $this->selectTable( $table );
		$_ids = static::idsToMongoIds( $id_list );
		$_criteria = array( static::DEFAULT_ID_FIELD => array( '$in' => $_ids ) );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			if ( static::_requireMoreFields( $fields ) )
			{
				/** @var \MongoCursor $result */
				$result = $_coll->find( $_criteria, $_fieldArray );
				$_out = iterator_to_array( $result );
			}
			else
			{
				$_out = static::idsAsRecords( $_ids );
			}

			$_result = $_coll->remove( $_criteria );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to delete items from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $id
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function deleteRecordById( $table, $id, $fields = null, $extras = array() )
	{
		if ( empty( $id ) )
		{
			throw new BadRequestException( "No identifier exist in record." );
		}

		$_coll = $this->selectTable( $table );
		$_criteria = array( static::DEFAULT_ID_FIELD => static::idToMongoId( $id ) );
		$_fieldArray = static::buildFieldArray( $fields );

		try
		{
			$result = $_coll->findAndModify( $_criteria, null, $_fieldArray, array( 'remove' => true ) );

			return static::mongoIdToId( $result );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to delete item from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param string $filter
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function retrieveRecordsByFilter( $table, $filter = null, $fields = null, $extras = array() )
	{
		$_coll = $this->selectTable( $table );
		$_fieldArray = static::buildFieldArray( $fields );
		$_criteria = static::buildFilterArray( $filter );
		$_limit = intval( Option::get( $extras, 'limit', 0 ) );
		$_offset = intval( Option::get( $extras, 'offset', 0 ) );
		$_sort = static::buildSortArray( Option::get( $extras, 'order' ) );
		$_count = Option::get( $extras, 'include_count', false );
		try
		{
			/** @var \MongoCursor $_result */
			$_result = $_coll->find( $_criteria, $_fieldArray );
			if ( $_offset )
			{
				$_result = $_result->skip( $_offset );
			}
			if ( $_sort )
			{
				$_result = $_result->sort( $_sort );
			}
			if ( $_limit )
			{
				$_result = $_result->limit( $_limit );
			}
			$_out = iterator_to_array( $_result );
			$_out =  static::cleanRecords( $_out );
			if ( $_count )
			{
				$_out['meta']['count'] = $_result->count();
			}

			return $_out;
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to filter items from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param string $table
	 * @param array  $records
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function retrieveRecords( $table, $records, $fields = null, $extras = array() )
	{
		if ( empty( $records ) || !is_array( $records ) )
		{
			throw new BadRequestException( 'There are no record sets in the request.' );
		}
		if ( !isset( $records[0] ) )
		{
			// single record
			$records = array( $records );
		}

		$_coll = $this->selectTable( $table );
		$_ids = static::idsToMongoIds( static::recordsAsIds( $records ) );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			/** @var \MongoCursor $result */
			$result = $_coll->find( array( '$in' => $_ids ), $_fieldArray );
			$_out = iterator_to_array( $result );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to get items from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $record
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function retrieveRecord( $table, $record, $fields = null, $extras = array() )
	{
		if ( !is_array( $record ) || empty( $record ) )
		{
			throw new BadRequestException( 'There are no fields in the record.' );
		}

		$_coll = $this->selectTable( $table );
		$_id = Option::get( $record, static::DEFAULT_ID_FIELD );
		if ( empty( $_id ) )
		{
			throw new BadRequestException( "Identifying field '_id' can not be empty for retrieve record request." );
		}

		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			$result = $_coll->findOne( array( static::DEFAULT_ID_FIELD => static::idToMongoId( $_id ) ), $_fieldArray );

			return static::mongoIdToId( $result );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to get item '$table/$_id' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param string $table
	 * @param string $id_list - comma delimited list of ids
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function retrieveRecordsByIds( $table, $id_list, $fields = null, $extras = array() )
	{
		if ( empty( $id_list ) )
		{
			return array();
		}

		$_coll = $this->selectTable( $table );
		$_ids = static::idsToMongoIds( $id_list );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			/** @var \MongoCursor $result */
			$result = $_coll->find( array( static::DEFAULT_ID_FIELD => array( '$in' => $_ids ) ), $_fieldArray );
			$_out = iterator_to_array( $result );

			return static::cleanRecords( $_out );
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to get items from '$table' on MongoDb service.\n" . $ex->getMessage() );
		}
	}

	/**
	 * @param        $table
	 * @param        $id
	 * @param string $fields
	 * @param array  $extras
	 *
	 * @throws \Exception
	 * @return array
	 */
	public function retrieveRecordById( $table, $id, $fields = null, $extras = array() )
	{
		if ( empty( $id ) )
		{
			return array();
		}

		$_coll = $this->selectTable( $table );
		$_fieldArray = static::buildFieldArray( $fields );
		try
		{
			$result = $_coll->findOne( array( static::DEFAULT_ID_FIELD => static::idToMongoId( $id ) ), $_fieldArray );
			if ( empty( $result ) && is_numeric( $id ) )
			{
				// defaults to string ids, could be numeric, try that
				$id = ( $id == strval( intval( $id ) ) ) ? intval( $id ) : floatval( $id );
				$result = $_coll->findOne( array( static::DEFAULT_ID_FIELD => $id ), $_fieldArray );
			}
		}
		catch ( \Exception $ex )
		{
			throw new InternalServerErrorException( "Failed to get item '$table/$id' on MongoDb service.\n" . $ex->getMessage() );
		}

		if ( empty( $result ) )
		{
			throw new NotFoundException( "Record with id '$id' was not found." );
		}

		return static::mongoIdToId( $result );
	}

	/**
	 * @param string|array $include List of keys to include in the output record
	 *
	 * @return array
	 */
	protected static function buildFieldArray( $include = '*' )
	{
		if ( '*' == $include )
		{
			return array();
		}

		if ( empty( $include ) )
		{
			$include = static::DEFAULT_ID_FIELD;
		}
		if ( !is_array( $include ) )
		{
			$include = array_map( 'trim', explode( ',', trim( $include, ',' ) ) );
		}
		$_out = array();
		foreach ( $include as $key )
		{
			$_out[$key] = true;
		}

		return $_out;
	}

	/**
	 * @param string|array $filter Filter for querying records by
	 *
	 * @return array
	 */
	protected static function buildFilterArray( $filter )
	{
		if ( empty( $filter ) )
		{
			return array();
		}

		if ( is_array( $filter ) )
		{
			return $filter; // assume they know what they are doing
		}

		$_search = array( ' or ', ' and ', ' nor ' );
		$_replace = array( ' || ', ' && ', ' NOR ' );
		$filter = trim( str_ireplace( $_search, $_replace, $filter ) );

		// handle logical operators first
		$_ops = array_map( 'trim', explode( ' || ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_parts = array();
			foreach ( $_ops as $_op )
			{
				$_parts[] = static::buildFilterArray( $_op );
			}

			return array( '$or' => $_parts );
		}

		$_ops = array_map( 'trim', explode( ' NOR ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_parts = array();
			foreach ( $_ops as $_op )
			{
				$_parts[] = static::buildFilterArray( $_op );
			}

			return array( '$nor' => $_parts );
		}

		$_ops = array_map( 'trim', explode( ' && ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_parts = array();
			foreach ( $_ops as $_op )
			{
				$_parts[] = static::buildFilterArray( $_op );
			}

			return array( '$and' => $_parts );
		}

		// handle negation operator, i.e. starts with NOT?
		if ( 0 == substr_compare( $filter, 'not ', 0, 4, true ) )
		{
			$_parts = trim( substr( $filter, 4 ) );

			return array( '$not' => $_parts );
		}

		// the rest should be comparison operators
		$_search = array( ' eq ', ' ne ', ' gte ', ' lte ', ' gt ', ' lt ', ' in ', ' nin ', ' all ', ' like ' );
		$_replace = array( '=', '!=', '>=', '<=', '>', '<', ' IN ', ' NIN ', ' ALL ', ' LIKE ' );
		$filter = trim( str_ireplace( $_search, $_replace, $filter ) );

		$_ops = array_map( 'trim', explode( '=', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => $_val );
		}

		$_ops = array_map( 'trim', explode( '!=', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$ne' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( '>=', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$gte' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( '<=', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$lte' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( '>', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$gt' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( '<', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$lt' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( ' IN ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$in' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( ' NIN ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$nin' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( ' ALL ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
			$_val = static::_determineValue( $_ops[1] );

			return array( $_ops[0] => array( '$all' => $_val ) );
		}

		$_ops = array_map( 'trim', explode( ' LIKE ', $filter ) );
		if ( count( $_ops ) > 1 )
		{
//			WHERE name LIKE "%Joe%"	find(array("name" => new MongoRegex("/Joe/")));
//			WHERE name LIKE "Joe%"	find(array("name" => new MongoRegex("/^Joe/")));
//			WHERE name LIKE "%Joe"	find(array("name" => new MongoRegex("/Joe$/")));
			$_val = static::_determineValue( $_ops[1] );
			if ( '%' == $_val[strlen( $_val ) - 1] )
			{
				if ( '%' == $_val[0] )
				{
					$_val = '/' . trim( $_val, '%' ) . '/ ';
				}
				else
				{
					$_val = '/^' . rtrim( $_val, '%' ) . '/ ';
				}
			}
			else
			{
				if ( '%' == $_val[0] )
				{
					$_val = '/' . trim( $_val, '%' ) . '$/ ';
				}
				else
				{
					$_val = '/' . $_val . '/ ';
				}
			}

			return array( $_ops[0] => new \MongoRegex( $_val ) );
		}

		return $filter;
	}

	/**
	 * @param $value
	 *
	 * @return bool|float|int|string
	 */
	private static function _determineValue( $value )
	{
		if ( trim( $value, "'\"" ) !== $value )
		{
			return trim( $value, "'\"" ); // meant to be a string
		}

		if ( is_numeric( $value ) )
		{
			return ( $value == strval( intval( $value ) ) ) ? intval( $value ) : floatval( $value );
		}

		if ( 0 == strcasecmp( $value, 'true' ) )
		{
			return true;
		}

		if ( 0 == strcasecmp( $value, 'false' ) )
		{
			return false;
		}

		return $value;
	}

	/**
	 * @param string|array $sort List of fields to sort the output records by
	 *
	 * @return array
	 */
	protected static function buildSortArray( $sort )
	{
		if ( empty( $sort ) )
		{
			return null;
		}

		if ( !is_array( $sort ) )
		{
			$sort = array_map( 'trim', explode( ',', trim( $sort, ',' ) ) );
		}
		$_out = array();
		foreach ( $sort as $_combo )
		{
			if ( !is_array( $_combo ) )
			{
				$_combo = array_map( 'trim', explode( ' ', trim( $_combo, ' ' ) ) );
			}
			$_dir = 1;
			$_field = '';
			switch ( count( $_combo ) )
			{
				case 1:
					$_field = $_combo[0];
					break;
				case 2:
					$_field = $_combo[0];
					switch ( $_combo[1] )
					{
						case -1:
						case 'desc':
						case 'DESC':
						case 'dsc':
						case 'DSC':
							$_dir = -1;
							break;
					}
			}
			if ( !empty( $_field ) )
			{
				$_out[$_field] = $_dir;
			}
		}

		return $_out;
	}

	/**
	 * @param array        $record
	 * @param string|array $include List of keys to include in the output record
	 * @param string|array $id_field
	 *
	 * @return array
	 */
	protected static function cleanRecord( $record, $include = '*', $id_field = null )
	{
		$_out = parent::cleanRecord( $record, $include, $id_field );

		return static::mongoIdToId( $record );
	}

	/**
	 * @param array  $records
	 * @param string $id_field
	 *
	 * @return mixed
	 */
	protected static function mongoIdsToIds( $records, $id_field = null )
	{
		foreach ( $records as $key => $_record )
		{
			$records[$key] = static::mongoIdToId( $_record, $id_field );
		}

		return $records;
	}

	/**
	 * @param mixed  $record
	 * @param string $id_field
	 *
	 * @return array|string
	 */
	protected static function mongoIdToId( $record, $id_field = null )
	{
		if ( empty( $id_field ) )
		{
			$id_field = static::DEFAULT_ID_FIELD;
		}
		if ( !is_array( $record ) )
		{
			if ( is_object( $record ) )
			{
				/** $record \MongoId */
				$record = (string)$record;
			}
		}
		else
		{
			/** @var \MongoId $_id in record as '_id' or 'id' */
			$_id = Option::get( $record, $id_field, Option::get( $record, 'id', null, true ) );
			if ( is_object( $_id ) )
			{
				/** $_id \MongoId */
				$_id = (string)$_id;
			}
			$record[$id_field] = $_id;
		}

		return $record;
	}

	/**
	 * @param mixed  $record
	 * @param bool   $determine_value
	 * @param string $id_field
	 *
	 * @return array|bool|float|int|\MongoId|string
	 */
	public static function idToMongoId( $record, $determine_value = false, $id_field = null )
	{
		if ( empty( $id_field ) )
		{
			$id_field = static::DEFAULT_ID_FIELD;
		}
		if ( !is_array( $record ) )
		{
			if ( is_string( $record ) )
			{
				$_isMongo = false;
				if ( ( 24 == strlen( $record ) ) )
				{
					// single id
					try
					{
						$record = new \MongoId( $record );
						$_isMongo = true;
					}
					catch ( \Exception $ex )
					{
						// obviously not a Mongo created Id, let it be
					}
				}
				if ( !$_isMongo && $determine_value )
				{
					$record = static::_determineValue( $record );
				}
			}
		}
		else
		{
			// single record with fields
			$_id = Option::get( $record, $id_field );
			if ( is_string( $_id ) )
			{
				$_isMongo = false;
				if ( ( 24 == strlen( $_id ) ) )
				{
					try
					{
						$_id = new \MongoId( $_id );
						$_isMongo = true;
					}
					catch ( \Exception $ex )
					{
						// obviously not a Mongo created Id, let it be
					}
				}
				if ( !$_isMongo && $determine_value )
				{
					$_id = static::_determineValue( $_id );
				}
				$record[$id_field] = $_id;
			}
		}

		return $record;
	}

	/**
	 * @param string|array $records
	 * @param string       $id_field
	 *
	 * @return array
	 */
	public static function idsToMongoIds( $records, $id_field = null )
	{
		$_determineValue = false;
		if ( !is_array( $records ) )
		{
			// comma delimited list of ids
			$records = array_map( 'trim', explode( ',', trim( $records, ',' ) ) );
			$_determineValue = true;
		}

		foreach ( $records as $key => $_record )
		{
			$records[$key] = static::idToMongoId( $_record, $_determineValue, $id_field );
		}

		return $records;
	}
}