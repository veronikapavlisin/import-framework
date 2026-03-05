<?php

require \APP_PATH . 'admin/lib/fkt_global_import.php';

use \Platform\Core\DB\Query;

class import
{
	/** @var string import group */
	private string $group = '';

	/** @var string execution group */
	private string $execution_group = '';

	/** @var array import data */
	private ?array $data;

	/** @var array log */
	private array $log;

	/** @var array cached realm data */
	private static array $realm_data = [];

	/** @var array cached importers */
	private static array $importer_pool = [];

	/** @var \Platform\Core\Root */
	protected \Platform\Core\Root $root;

	/** @var \cBigMath */
	protected \cBigMath $math;

	const IMPORT_SUCCESS_COLOR = '#E5FFE5';
	const IMPORT_FAILURE_COLOR = '#FFE5E5';

	/**
	 * Constructor
	 *
	 */
	public function __construct(
		string $import_group,
		string $execution_group,
		\Platform\Core\Root $root
	) {
		$this->group = $import_group;
		$this->execution_group = $execution_group;

		$this->data = null;
		$this->log = [];

		// cached root and math class
		$this->root = $root;
		$this->math = $this->root->math;
	}

	/**
	 * Method to check validity of import type
	 *
	 * @param string $import_type
	 * @return bool result of check
	 */
	private function check_import_type( string $import_type ): bool {
		return \in_array( $import_type, [ 'banner', 'event', 'happy_hour', 'message_news', ] );
	}

	/**
	 * Method to get realm data
	 *
	 * @return array
	 *
	 * @throws \Platform\Core\Exception\Data_Fault
	 */
	public function get_realm_data(): array {

		$group = $this->execution_group;
		// use group name as cache key
		$cache_key = $group;

		// todo: GLOBAL_CONFIG_PATH should be renamed to more general GLOBAL_HELM_PATH,
		// since both events and banners use it
		$global_helm_path = \global_fetch_constant_value( 'GLOBAL_CONFIG_PATH' );

		// some exit checks
		if (
			// check for matching environment
			! \global_check_env()
			// check for valid helm config
			|| ! $global_helm_path
			|| ! \is_dir( $global_helm_path )
		) {
			return [];
		}
		// use cache if set
		if ( ! empty( self::$realm_data[ $cache_key ] ) ) {
			return self::$realm_data[ $cache_key ];
		}
		// build a valid geo filter array
		$filter_data = [];
		// determine group
		list( $game, $geo ) = \explode( '_', \mb_strtolower( $group ) );
		$filter_data[ $game ] = GLOBAL_GAME_GROUP[ $game ][ $geo ] ?? [];
		// no filter? => stop here
		if ( empty( $filter_data ) ) {
			return [];
		}
		// save current game geo
		$current_geo = $_SERVER[ 'GAME_GEO' ];
		// list of games, geo and realms
		$game_info = [];
		// load configs fo GCP cluster
		foreach( GLOBAL_GAME_LIST as $game => $geo_list ) {
			// check for skip
			if ( ! isset( $filter_data[ $game ] ) ) {
				continue;
			}
			// prepare game array if not set
			$game_info[ $game ] ??= [];
			// loop through geo list and load configs
			foreach( $geo_list as $geo_name ) {
				// check for skip
				if ( ! \in_array( $geo_name, $filter_data[ $game ] ) ) {
					continue;
				}
				// prefill further game_info if necessary
				$game_info[ $game ][ $geo_name ] ??= [];
				// only normal worlds should query settings
				if ( 'local' !== $geo_name ) {
					// set server variables
					$_SERVER[ 'GAME_GEO' ] = $geo_name;
					$game_path = \GLOBAL_CONFIG_PATH . \DS . 'www' . \DS
						. 'files' . \DS . $game . \DS . 'config' . \DS . 'www';
					// temporarily disable error reporting for loading realm selection
					// data
					$level = \error_reporting();
					\error_reporting( E_ALL & ~E_NOTICE );
					require $game_path . \DS . 'general' . \DS . 'settings.php';
					\error_reporting( $level );
					// handle invalid config
					if( empty( $aGameWorlds ) ) {
						continue;
					}
					// get existing realm paths independently from the realm selection
					$realm_path_list = \array_combine(
						\array_keys( $aGameWorlds ),
						\array_column( $aGameWorlds, 'path' )
					);
					// sort path list
					\natsort( $realm_path_list );
					// loop through realms
					foreach( $realm_path_list as $realm_index => $realm ) {
						// only normal worlds should query settings
						$_SERVER[ 'SCRIPT_NAME' ] = $game_path . \DS . $realm . \DS
							. 'index.php';
						// temporarily disable error reporting for loading realm details
						$level = \error_reporting();
						\error_reporting( E_ALL & ~E_NOTICE );
						require $game_path . \DS . 'world1' . \DS . 'general'
							. \DS . 'settings.php';
						// load database config of the realm
						require $game_path . \DS . 'world1' . \DS . 'general'
							. \DS . 'DB_info.php';
						\error_reporting( $level );
						// cache realm type
						$realm_type = $aGameWorlds[ $realm_index ][ 'type' ];
						// check if we skip the realm
						if(
							// check for complete db config to skip realms without database
							(
								empty( $database_host->server_ip )
								|| empty( $database_host->database )
							)
							// skip tutorial worlds
							|| \Platform\Module\Realm\Item::TYPE_TUTORIAL === $realm_type
							// skip child realms
							|| ( $aGameWorlds[ $realm_index ][ 'parent' ] ?? null )
						) {
							continue;
						}
						// push back database host as array
						$game_info[ $game ][ $geo_name ][ $realm_index ] = [
							'database' => [
								'server_ip' => $database_host->server_ip,
								'database' => $database_host->database,
							],
							'type' => $realm_type,
							'timezone' => $timezone,
						];
						// unset
						unset( $database_host );
					}
					// unset
					unset( $aGameWorlds );
				} else {
					// set server variables
					$_SERVER[ 'GAME_GEO' ] = '';
					$game_path = \ROOT_PATH . 'config' . \DS . 'www';
					$_SERVER[ 'SCRIPT_NAME' ] = $game_path . \DS . 'world1' . \DS
						. 'index.php';
					// temporarily disable error reporting for loading realm details
					$level = \error_reporting();
					\error_reporting( E_ALL & ~E_NOTICE );
					require $game_path . \DS . 'world1' . \DS . 'general'
						. \DS . 'settings.php';
					// load database config of the realm
					require $game_path . \DS . 'world1' . \DS . 'general'
						. \DS . 'DB_info.php';
					\error_reporting( $level );
					// setup empty realm path list array
					$realm_path_list = [];
					// check for complete db config to skip if not database
					if(
						empty( $database_host->server_ip )
						|| empty( $database_host->user )
						|| empty( $database_host->password )
						|| empty( $database_host->database )
					) {
						continue;
					}
					// get possible databases
					$result = Query::select( 'SHOW DATABASES' );
					$local_index = 0;
					// loop databases
					while ( $row = $result->row ) {
						// cache array value
						$database = $row[ 'Database' ];
						// skip invalid stuff
						if( \in_array(
							$database, [
								'information_schema', 'performance_schema',
								'mysql', 'sys',
							]
						) ) {
							continue;
						}
						// explode name by underscore
						$split = \explode( '_', $database );
						// check for correct prefix related to game
						if ( $split[ 0 ] !== $game || $split[ 1 ] !== $geo_name ) {
							continue;
						}
						// determine realm type
						$realm_type = false !== \mb_stripos( $database, '_tutorial' )
							? \Platform\Module\Realm\Item::TYPE_TUTORIAL
							: \Platform\Module\Realm\Item::TYPE_ENDLESS;
						// skip tutorial worlds
						if ( \Platform\Module\Realm\Item::TYPE_TUTORIAL === $realm_type ) {
							continue;
						}
						// push back game info stuff
						$game_info[ $game ][ $geo_name ][ ++$local_index ] = [
							'database' => [
								'server_ip' => $database_host->server_ip,
								'user' => $database_host->user,
								'password' => $database_host->password,
								'database' => $database,
							],
							'type' => $realm_type,
							'timezone' => $timezone,
						];
					}
				}
			}
			// sort geo list
			\ksort( $game_info[ $game ] );
		}
		// restore current geo
		$_SERVER[ 'GAME_GEO' ] = $current_geo;
		// add to cache if called again with same parameters
		self::$realm_data[ $cache_key ] = $game_info;
		// finally return game info array
		return self::$realm_data[ $cache_key ];
	}

	/**
	 * Method to fetch import xml files
	 *
	 * @return array
	 * @throws \Exception
	 */
	private function fetch_data(): array {

		$global_data_path = \global_fetch_constant_value( 'GLOBAL_DATA_PATH' );

		// build file path
		$data_file_path = $global_data_path
			. \DS . 'configuration'
			. \DS . $this->group
			. '.yaml';
		// some checks
		if (
			// check for existing global data path
			! $global_data_path
			|| ! \is_dir( $global_data_path )
			// check for existing group folder
			|| empty( $data_file_path )
			|| ! \file_exists( $data_file_path )
		) {
			return [];
		}
		// acquire realm data
		$realm_data = $this->get_realm_data();
		if ( empty( $realm_data ) ) {
			return [];
		}
		// parse configuration groups
		$configuration_data = \yaml_parse_file( $data_file_path );
		if ( false === $configuration_data ) {
			throw new \OutOfBoundsException;
		}
		// extract possible necessary fields
		$realm_type_restriction = $configuration_data[ 'restrict' ] ?? '';
		$include = $configuration_data[ 'include' ] ?? [];
		$exclude = $configuration_data[ 'exclude' ] ?? [];
		$import = $configuration_data[ 'import' ] ?? [];
		// array for return data
		$return_stuff = [];
		// loop through realms and build stuff
		foreach ( $realm_data as $game => $game_data ) {
			foreach ( $game_data as $geo => $realm_list ) {
				foreach ( $realm_list as $idx => $realm ) {
					// skip if restriction was set for realms
					if (
						! empty( $realm_type_restriction )
						&& $realm[ 'type' ] !== $realm_type_restriction
					) {
						continue;
					}

					// handle exclude
					$is_excluded = (
							! empty( $exclude[ $game ] )
							&& '*' === ( $exclude[ $game ] ?? '' )
						) || (
							! empty( $exclude[ $game ] )
							&& \is_array( $exclude[ $game ] )
							&& ! empty( $exclude[ $game ][ $geo ] )
							&& (
								(
									\is_array( $exclude[ $game ][ $geo ] )
									&& \in_array( $idx, $exclude[ $game ][ $geo ] )
								) || '*' === ( $exclude[ $game ][ $geo ] ?? '' )
							)
						) || (
							! empty( $exclude[ $game ] )
							&& \is_array( $exclude[ $game ] )
							&& \in_array( $geo, $exclude[ $game ] )
						);
					// handle include
					$is_included = (
							! empty( $include[ $game ] )
							&& '*' === ( $include[ $game ] ?? '' )
						) || (
							! empty( $include[ $game ] )
							&& \is_array( $include[ $game ] )
							&& ! empty( $include[ $game ][ $geo ] )
							&& (
								(
									\is_array( $include[ $game ][ $geo ] )
									&& \in_array( $idx, $include[ $game ][ $geo ] )
								) || '*' === ( $include[ $game ][ $geo ] ?? '' )
							)
						) || (
							! empty( $include[ $game ] )
							&& \is_array( $include[ $game ] )
							&& \in_array( $geo, $include[ $game ] )
						);
					// skip if excluded and not included
					if ( ( ! $is_included && ! $is_excluded ) || $is_excluded ) {
						continue;
					}
					// skip ended challenge realms
					if( \Platform\Module\Realm\Item::TYPE_CHALLENGE === $realm[ 'type' ] ) {
						if ( ! \global_connect_to_realm( $realm[ 'database' ] ) ) {
							throw new \OutOfBoundsException(
								'connect to DB '
								. $realm[ 'database' ] . ' failed'
							);
						}

						$challenge_start = Query::select( '
							SELECT
								`start` + (
									SELECT `value` FROM `config` WHERE `name`="CHALLENGE_WORLD_DURATION"
								)
							FROM
								`game_challenge_timer`
							ORDER BY
								`start` DESC
							LIMIT 1
						' )->column;
						if(
							// new challenge, no round yet
							empty( $challenge_start )
							// current round already over
							|| $challenge_start < \time()
						) {
							continue;
						}
					}
					// get import data
					$import_data = \merge_info(
						$import,
						$game,
						$geo,
						$idx
					);

					// handle empty
					if ( empty( $import_data ) ) {
						continue;
					}
					// general stuff to be set if not overwritten
					$general = [];
					if ( ! empty( $import_data[ '*' ] ) ) {
						$general = $import_data[ '*' ];
						unset( $import_data[ '*' ] );
					}
					// loop through remaining entries
					foreach ( $import_data as $name => $data ) {
						// create a copy
						$general_copy = $general;
						// "merge" copy and data with correct overwrite
						$merged = \array_replace_recursive(
							$general_copy,
							$data
						);

						// handle xml not existing
						if ( ! \is_dir( $global_data_path . \DS . $merged[ 'xml' ] ) ) {
							throw new \OutOfBoundsException(
								$name . ' import file missing: '
								. var_export( $merged, true )
							);
						}

						// handle no xml set ( skip )
						if ( empty( $merged[ 'xml' ] ) ) {
							continue;
						}

						$tmp = [
							'name' => $name,
							'content' => [],
							'file' => [],
							'type' => $merged[ 'type' ] ?? 'event', // to ensure back compatibility
						];

						if ( !$this->check_import_type( $tmp[ 'type' ] ) ) {
							throw new \Detail(
								'Unknown import type ' . $tmp[ 'type' ] . ' used'
							);
						}
						if ( false === ( $importer = $this->get_importer( $tmp[ 'type' ] ) ) ) {
							throw new \Detail(
								'Class importer_' . $tmp[ 'type' ] . ' does not exist'
							);
						}
						$tmp[ 'params' ] = $importer->get_specific_data_from_yaml( $merged, $realm );

						if ( false === $tmp[ 'params' ] ) {
							throw new \Detail(
								'Incorrect data in YAML file', 10
							);
						}

						// glob import files
						$glob_result = \glob(
							$global_data_path
							. \DS . $merged[ 'xml' ]
							. \DS . '*.xml'
						);
						// gather imports
						foreach ( $glob_result as $xml_entry ) {
							// load file content
							$import_file_content = \file_get_contents( $xml_entry );
							if ( false === $import_file_content ) {
								throw new \OutOfBoundsException;
							}
							$tmp[ 'content' ][] = $import_file_content;
							$tmp[ 'file' ][] = \basename( $xml_entry );
						}
						// prepare array and push back stuff
						$return_stuff[ $game ][ $geo ][ $idx ] ??= [];
						// push back import content
						$return_stuff[ $game ][ $geo ][ $idx ][ $name ] = $tmp;
					}
				}
			}
		}

		return $return_stuff;
	}

	/**
	 * Helper to get data (cached if available)
	 *
	 * @return array
	 *
	 * @throws \Exception
	 */
	public function get_data(): array {
		if ( null === $this->data ) {
			$this->data = $this->fetch_data();
		}
		return $this->data;
	}

	/**
	 * Method to generate hash checksum for data structure used for global import
	 *
	 * @param array $import_data
	 * @return string
	 * @throws /Detail
	 */
	public function get_control_hash() : string {

		$data = [
			'import' => $this->group,
			'execution' => $this->execution_group,
			'data' => $this->get_data(),
		];
		$salt = '!Qut^XpC69QDm{&!)JyyZmB>i:"]X3';

		return \hash( 'sha256', \json_encode( $data ) . $salt );
	}

	/**
	 * Method returns importer object for specific import type
	 *
	 * @param string $import_type
	 * @return mixed false on error|importer object
	 */
	public function get_importer( string $import_type ) {

		// cache new importer if not created yet
		if ( !isset( import::$importer_pool[ $import_type ] ) ) {
			$importer_class = 'importer_'.$import_type;
			if ( ! class_exists( $importer_class ) ) {
				return false;
			}
			import::$importer_pool[ $import_type ] = new $importer_class( $this->root );
		}

		// return cached importer
		return import::$importer_pool[ $import_type ];
	}

	/**
	 * Method to gather data before validation
	 *
	 * @param array $item_list
	 * @return array validation data
	 */
	public function gather_validation_data( array $item_list ): array {

		$validation_data = [];

		// cycle through items and gather validation data for each item via its importer
		foreach( $item_list as $item_name => $item_data ) {

			if ( false === ( $importer = $this->get_importer( $item_data[ 'type' ] ) ) ) {
				continue;
			}
			$importer->get_validation_data( $item_name, $item_data, $validation_data );
		}

		return $validation_data;
	}

	/**
	 * Method to validate imported data within the realm
	 *
	 * @param string $game
	 * @param string $language
	 * @param string $world
	 *
	 * @return array validation status
	 */
	public function validate_realm( string $game, string $language, string $world ): array {

		// read realms access data
		$access_data = $this->get_realm_data();
		// one realm specifically
		$database_data = $access_data[ $game ][ $language ][ $world ][ 'database' ];

		// preparing data
		$data = $this->get_data();
		$item_list = $data[ $game ][ $language ][ $world ];

		// there is nothing to import by default
		$validation_result = 0;
		$validation_report = [];

		$key = $game . '#' . $language . '#' . $database_data[ 'database' ];

		// connect to realm's database
		if ( ! \global_connect_to_realm( $database_data ) ) {
			$validation_report[ $key ] = [
				-1,
				[ '', 'connect to DB ' . $database_data[ 'database' ] . ' failed', ],
			];
			return [ -1, $validation_report ];
		}

		$index = 0;
		$existing_item_found = false;

		// gathering extra data for validation
		$validation_data = $this->gather_validation_data( $item_list );

		// cycle through all instances
		// validating and gathering results
		foreach ( $item_list as $item_name => $item_data ) {

			$item_key = $key . '#' . $item_name . '#' . $item_data[ 'type' ];

			if ( !$this->check_import_type( $item_data[ 'type' ] ) ) {
				$result_code = 10;
				$result_detail = 'Invalid configuration: invalid type of import item ' . $item_name;
				// create default event importer to process validation output
				$importer = $this->get_importer( 'event' );
			} else {
				$importer = $this->get_importer( $item_data[ 'type' ] );

				// validate specific item
				list( $result_code, $result_detail, $validated_index )
					= $importer->validate_item( $item_name, $item_data, $validation_data );
			}

			if ( 5 === $result_code ) {
				// skipping already imported items
				$validation_report[ $item_key ] = [ $result_code, ];
				// taking over info about imported file from log
				if (
					isset( $import_log[ $key ] )
					&& isset( $import_log[ $key ][ $item_name ] )
				) {
					// into report
					$validation_report[ $item_key ][ 1 ] = [
						'imported_file' => $import_log[ $key ][ $item_name ],
					];
				}
			} elseif ( 6 === $result_code ) {
				// marking existing items
				$validation_report[ $item_key ] = [
					$result_code,
					$importer->get_validation_output(
						[ $result_code, $result_detail, ],
						$game . '_' . $language . '_' . $world . '_' . $index++
					),
				];
				$existing_item_found = true;
			} elseif ( true === $result_code ) {

				if ( -1 !== $validation_result ) {
					$validation_result = 1;
				}
				$validation_report[ $item_key ] = [ $result_code, $result_detail, ];
			} else {
				$validation_result = -1;

				$validation_report[ $item_key ] = [
					$validation_result,
					$importer->get_validation_output(
						[ $result_code, $result_detail, ],
						$game . '_' . $language . '_' . $world . '_' . $index++
					),
				];

			}
		}

		if ( 0 === $validation_result && $existing_item_found ) {
			// if there was existing item ( one that can be updated) found
			// change nothing to import to update maybe needed
			$validation_result = 2;
		}

		return [ $validation_result, $validation_report, ];
	}

	/**
	 * Method to import data within the realm
	 *
	 * @param bool $continue_flag
	 * @param int $log_index
	 * @param string $game
	 * @param string $language
	 * @param string $world
	 *
	 * @return array import status
	 */
	public function import_realm(
		bool $continue_flag,
		int $log_index,
		string $game,
		string $language,
		string $world
	): array {

		// read realms access data
		$access_data = $this->get_realm_data();
		// connect to realm's database
		$database_data = $access_data[ $game ][ $language ][ $world ][ 'database' ];

		if ( ! \global_connect_to_realm( $database_data ) ) {
			return [
				false,
				'connect to DB ' . $database_data[ 'database' ] . ' failed',
			];
		}

		// importing, gathering results and logging
		$log_key = $game . '#' . $language . '#' . $database_data[ 'database' ];

		// preparing data
		$data = $this->get_data();
		$item_list = $data[ $game ][ $language ][ $world ];

		$import_success = 'true' == $continue_flag;
		$anything_to_import = false;

		$import_report = [];

		// loading import log
		$log_item = $this->get_log_item( $log_index );
		$import_log = $log_item[ 'Report' ] ?? [];
		$import_log[ $log_key ] ??= [];

		// gathering extra data for validation (if needed)
		$validation_data = $this->gather_validation_data( $item_list );

		// apply validation data to item list (if needed)
		$this->process_before_import( $validation_data, $item_list );

		try {
			$transaction = Query::begin( false );

			$index = 0;

			// cycle through XML files loaded for item from repository
			foreach ( $item_list as $item_name => $item_data ) {
				$item_key = $log_key . '#' . $item_name . '#' . $item_data[ 'type' ];

				// in case import is cancelled because of previous failure
				if ( ! $import_success ) {
					$import_report[ $item_key ] = [ -2, ];
					continue;
				}

				// start measure import duration
				$import_start = \getmicrotime();

				$importer = $this->get_importer( $item_data[ 'type' ] );

				// validation run again before import
				list( $result_code, $result_detail, $validated_index )
					= $importer->validate_item( $item_name, $item_data, $validation_data );

				if ( 5 === $result_code ) {
					// skipping already imported items
					$import_report[ $item_key ] = [ 0, ];
					// taking over info about imported file from log
					if ( isset( $import_log[ $log_key ][ $item_name ] ) ) {
						// into report
						$import_report[ $item_key ][ 1 ] = [
							'imported_file' => $import_log[ $log_key ][ $item_name ],
						];
					} else {
						$import_log[ $log_key ][ $item_name ] = 'Imported';
					}
				} elseif (
					// importing valid and existing items
					// or existing files that can be updated (code 6)
					(
						true === $result_code
						|| 6 === $result_code
					)
					&& null !== $validated_index
					&& isset( $item_data[ 'content' ][ $validated_index ] )
				) {
					// importing in case of successful validation
					$anything_to_import = true;
					// import
					$importer->import_item(
						$item_name,
						$item_data,
						$validated_index,
						$item_key,
						$log_key,
						$import_start,
						$import_success,
						$import_report,
						$import_log,
						$validation_data
					);
				} else {
					// no import in case of failed validation
					$import_report[ $item_key ] = [
						false,
						$importer->get_validation_output(
							[ false, $result_detail, ],
							$game . '_' . $language . '_' . $world . '_' . $index++
						)
					];

					$import_log[ $log_key ][ $item_name ] = '';
					$import_success = false;
				}
			}

			if ( $import_success ) {
				// commit if all items were validated and imported without issue
				$transaction->commit();
			} else {
				foreach ( $item_list as $item_name => $item_data ) {
					if (
						isset( $import_report[ $log_key . '#' . $item_name ] )
						&& true === $import_report[ $log_key . '#' . $item_name ][ 0 ]
					) {
						$import_report[ $log_key . '#' . $item_name ][ 0 ] = false;
					}
				}
				// rollback
				Query::transaction_started() && Query::rollback(
					null,
					false
				);
			}
		} catch ( \Exception $exception ) {
			foreach ( $import_report as $report_key => $report_value ) {
				if (
					0 === \strpos( $report_key, $log_key )
					&& true === $report_value[ 0 ]
				) {
					$import_report[ $report_key ][ 0 ] = false;
				}
			}
			$error_detail = $importer->get_validation_output(
				[
					false,
					[
						$item_data[ 'file' ][ 0 ] => [
							$exception->getCode(),
							$exception->getMessage(),
						],
					],
				],
				$game . '_' . $language . '_' . $world . '_' . $index
			);
			$import_report[ $item_key ?? $log_key ] = [
				false,
				$error_detail
			];

			if ( isset( $item_name ) ) {
				$import_log[ $log_key ][ $item_name ] = '';
			}
			$import_success = false;

			// rollback
			Query::transaction_started() && Query::rollback(
				null,
				false
			);
		}

		// saving updated report into log item
		$log_item[ 'Report' ] = $import_log;
		// updating time if there was any change or even try for change
		if ( $anything_to_import ) {
			$log_item[ 'Date' ] =  \date( 'd-M-y H_i', \time() );
		}
		// updating log
		$this->set_log( $log_index, $log_item );
		// storing updated log
		$this->store_log();

		return [ $import_success, $import_report, ];
	}

	/**
	 * Method to manipulate imported data based on validation data
	 *
	 * @param array $validation_data
	 * @param array $item_list
	 */
	private function process_before_import( array $validation_data, &$item_list ): void {
		// cycle through all used importers and let validation data be processed
		foreach( import::$importer_pool as $importer ) {
			$importer->process_before_import( $validation_data, $item_list );
		}
	}

	/**
	 * Method to load contents of global import log file
	 *
	 * @param int $index indx of specific log item (optional)
	 * @return array whole log or only specific log item in case index was sent
	 */
	private function get_log( int $index = -1 ): array {

		$file_path = GLOBAL_DATA_PATH . \DS . 'global_import.log';

		if ( ! file_exists( $file_path ) ) {
			return [];
		}

		$file = \file( $file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );

		$import_log = $log_item = [];

		foreach ( $file as $line ) {
			// each line consist of pair title:value
			$tmp = \explode( ':', $line, 2 );

			// Import: at beginning of line means 1st line of new log item
			if ( 0 === \strpos( $line, 'Import:' ) && !empty( $log_item ) ) {
				$import_log[] = $log_item;
				$log_item = [];
			}

			if ( 'Report' === $tmp[ 0 ] ) {
				// value after title Report has to be json decoded before being assigned
				$log_item[ $tmp[ 0 ] ] = \json_decode( $tmp[ 1 ], true );
			} else {
				// all other lines contain value that will be simply assigned
				$log_item[ $tmp[ 0 ] ] = $tmp[ 1 ];
			}
		}
		if ( !empty( $log_item ) ) {
			$import_log[] = $log_item;
		}

		$this->log = $import_log;

		return $this->log;
	}

	/**
	 * Method to load contents of specific log item
	 *
	 * @param int $index index of specific log item
	 * @return array whole log or only specific log item in case index was sent
	 */
	public function get_log_item( int $index ): array {
		$log = $this->get_log();

		if ( isset( $log[ $index ] ) ) {
			return $log[ $index ];
		}

		return [];
	}

	/**
	 * Method to store contents of specific log item in internal log array
	 *
	 * @param int $index index of specific log item
	 * @param array $log_item contents of log item to be stored
	 * @return int index in internal log array under which log item was stored
	 */
	public function set_log( int $index, array $log_item ): int {
		if ( -1 === $index ) {
			// adding at beginning
			\array_unshift( $this->log, $log_item );
			return 0;
		} else if ( isset( $this->log[ $index ] ) ) {
			$this->log[ $index ] = $log_item;
			return $index;
		}
		return -1;
	}

	/**
	 * Method to save content of global import log file
	 *
	 * @return bool success of storing log to file
	 */
	public function store_log(): bool {
		$file_path = GLOBAL_DATA_PATH . \DS . 'global_import.log';

		$output = '';

		// organise associative array into lines where : divides key from value
		foreach ( $this->log as $import_log ) {
			foreach ( $import_log as $key => $value ) {
				if ( 'Report' === $key ) {
					$output .= $key . ':' . \json_encode( $value ) . "\n";
				} else {
					$output .= $key . ':' . $value . "\n";
				}
			}
			$output .= "\n";
		}

		// write text file
		if ( $file_dic = \fopen( $file_path , 'w' ) ) {
			\fwrite( $file_dic, $output );
			\fclose( $file_dic );
		} else {
			echo 'Could not write log file. Please check its rights.';
			return false;
		}

		return true;
	}

	/**
	 * Method to find log item of specific import (based on hash)
	 *
	 * @param string $import_hash hash of import
	 * @return array [ finished state of import, index of log item, contents of log item ]
	 */
	public function check_unfinished_import( string $import_hash ): array {

		// checking whether current import isn't a continuation
		// (searching through newest log items first)
		foreach ( $this->get_log() as $index => $log_item ) {
			if ( $import_hash == $log_item[ 'Checksum' ] ) {
				// latest import with same checksum found
				if ( 'OK' == $log_item[ 'Status' ] ) {
					// if it was finished
					return [ 1, -1, $log_item ];
				}
				// if it wasn't finished
				return [ 0, $index, $log_item ];
			}
		}
		// if import wasn't found
		return [ -1, -1, null, ];
	}

	/**
	 * Method to find log item of specific completed import (based on hash)
	 *
	 * @param string $import_hash hash of import
	 * @return array contents of found import|empty if import was not found
	 */
	public function check_completed_import( string $import_hash ): array {

		// check for last completed import with same checksum in logs
		foreach ( $this->get_log() as $index => $import_log_item ) {
			if ( 'OK' == $import_log_item[ 'Status' ]
				&& $import_hash == $import_log_item[ 'Checksum' ]
			) {
				return $import_log_item;
			}
		}
		return [];
	}
}
