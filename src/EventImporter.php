<?php

use \Platform\Core\DB\Query;

class importer_event
extends importer {

	/** @var string last absolute start date processed */
	private string $last_absolute_start_date = '';

	/**
	 * Constructor
	 *
	 */
	public function __construct( \Platform\Core\Root $root ) {
		parent::__construct( $root );
		$this->type = \importer::TYPE_EVENT;
	}

	/**
	 * Event implementation for method to convert imported xml to data array
	 *
	 * @param string $xml xml data
	 * @return array associative data array
	 *
	 * @throws \Detail
	 */
	public function get_data_from_xml( string $xml ): array {
		$xml_array = \convertXmlToArrayRecursive( $xml );
		// handle empty or incorrect xml
		if ( empty( $xml_array ) || !isset( $xml_array[ 'event' ] ) ) {
			throw new \Detail( 'Supplied XML does not have a valid event XML structure', 13 );
		}
		// return event data
		return [
			'eventName' => $xml_array[ 'event' ][ 'name' ] ?? null,
			'eventDescription' => $xml_array[ 'event' ][ 'description' ] ?? null,
			'eventType' => $xml_array[ 'event' ][ 'type' ] ?? null,
			'eventStart' => \strtotime( $xml_array[ 'event' ][ 'start' ] ) ?? false,
			'eventEnd' => \strtotime( $xml_array[ 'event' ][ 'end' ] ) ?? false,
			'eventData' => $xml_array[ 'event' ][ 'data' ] ?? [],
			'eventDefaultData' => $xml_array[ 'event' ][ 'default' ] ?? [],
			'eventID' => 0,
		];
	}

	/**
	 * Event implementation for method to validate item within realm
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param array $validation_data
	 * @return array [ result code, result detail, index of xml file that passed validation ]
	 */
	public function validate_item(
		string $item_name,
		array $item_data,
		array $validation_data
	): array {
		$result_code = $validated_index = null;
		$result_detail_list = [];
		// XML file(s) availability check
		if ( empty( $item_data[ 'content' ] ) ) {
			return [ 10, 'XML file missing', '', ];
		}
		// check empty start parameter from YAML file
		if ( empty( $item_data[ 'params' ][ 'start' ] ) ) {
			return [ 10, 'YAML without start parameter provided', '', ];
		}
		// check empty end parameter from YAML file
		if ( !$item_data[ 'params' ][ 'chained' ] && empty( $item_data[ 'params' ][ 'end' ] ) ) {
			return [ 10, 'YAML without end parameter provided', '', ];
		}
		// check validity of end parameter from YAML file
		if ( !$item_data[ 'params' ][ 'chained' ] && $item_data[ 'params' ][ 'end' ] <= $item_data[ 'params' ][ 'start' ] ) {
			return [
				10,
				'YAML parameter end is not greater than start:'
					. '<br>'
					. date( 'd.m.Y H:i', $item_data[ 'params' ][ 'end' ] )
					. ' <= '
					. date( 'd.m.Y H:i', $item_data[ 'params' ][ 'start' ] ),
				'',
			];
		}
		// check for multiple event type
		if ( 1 < count( $validation_data[ 'type' ][ $item_name ] ) ) {
			// report issue together with all extracted types
			return [
				10,
				'Multiple event types provided in XML files:<br>'
				. \implode( '<br>', $validation_data[ 'type' ][ $item_name ] ),
				'',
			];
		}

		$event_type = $item_data[ 'eventType' ] = $validation_data[ 'type' ][ $item_name ][ 0 ];

		try {
			require_once \APP_PATH . 'admin/event/' . $event_type . '.php';
			/** @var \event $event */
			$event_instance = new $event_type( Query::connection(), $this->math );
		} catch ( \Exception $exception ) {
			return [ 10, 'Unknown event type ' . $event_type . ' used', '', ];
		}

		// get whether event can have multiple instances
		$multiple_flag = $event_instance->allows_multiple_instance();
		// get names of imported events of this type
		$event_list = \array_keys( $validation_data[ 'chain' ][ $event_type ][ 0 ] );
		// get number of imported events of this type
		$event_count = count( $event_list );
		// if single only and count is > 1 -> error
		if ( !$multiple_flag && $event_count > 1 ) {
			return [
				10,
				'Multiple events of type ' . $event_type . ' that doesn\'t allow it:<br>'
				. \implode( '<br>', \array_keys( $validation_data[ 'chain' ][ $event_type ][ 0 ] ) ),
				'',
			];
		}
		// check for conflicted events within all pairs of this event type
		for ( $i = 0; $i < $event_count - 1; $i++ ) {
			for ( $j = $i + 1; $j < $event_count; $j++ ) {
				if (
					$this->check_event_conflict(
						$validation_data[ 'chain' ][ $event_type ][ 0 ][ $event_list[ $i ] ],
						$validation_data[ 'chain' ][ $event_type ][ 0 ][ $event_list[ $j ] ],
					)
				) {
					return [
						10,
						'Events in conflict:<br>'
						. \implode( '<br>', [ $event_list[ $i ], $event_list[ $j ], ] ),
						'',
					];
				}
			}
		}
		$chain_validation_data = $validation_data[ 'chain' ][ $event_type ];

		// cycle through XML for validated event
		foreach ( $item_data[ 'content' ] as $index => $event_xml_instance ) {
			if ( '' == $event_xml_instance ) {
				continue;
			}
			try {
				// transform XML to array
				$xml_data = $this->get_data_from_xml( $event_xml_instance );

				// validation function
				$validation_result = $this->validate_instance(
					$item_name,
					$item_data,
					$xml_data,
					$validation_data,
					$event_instance
				);
				if ( 1 === $validation_result ) {
					// validation is interrupted with first validated XML
					$validated_index = $index;
					$result_code = true;
					$result_detail_list = [ 'valid_file' => $item_data[ 'file' ][ $index ], ];
					break;
				} elseif ( 0 === $validation_result ) {
					// event detected as already existing will be marked with special
					// code and won't be interpreted as import showstopper
					$result_code = 5;
					$validated_index = $index;
					$result_detail_list = [];
				}
			} catch ( \Exception $exception ) {
				// saving under name of file in report list
				$result_detail_list[ $item_data[ 'file' ][ $index ] ] = [
					$exception->getCode(),
					$exception->getMessage(),
					$exception instanceof \Detail ? $exception->getData() : null
				];
			}
		}
		return [ $result_code, $result_detail_list, $validated_index, ];
	}

	/**
	 * Event implementation for abstract method to validate instance
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param array $xml_data
	 * @param array $validation_data
	 * @param object $instance_object
	 * @return int 0 when validation passes and event already exists,
	 *             1 when validation passes and event does not exist yet
	 *
	 * @throws \Detail at any validation fail
	 */
	public function validate_instance(
		string $item_name,
		array $item_data,
		array $xml_data,
		array $validation_data,
		object $instance_object = null
	): int {
		// check missing event type
		if ( empty( $xml_data[ 'eventType' ] ) ) {
			throw new \Detail(
				'XML with missing event type provided',
				1
			);
		}
		// check inconsistent event type
		if ( $xml_data[ 'eventType' ] !== $item_data[ 'eventType' ] ) {
			throw new \Detail(
				'Inconsistent event type between XML ('
					. $xml_data[ 'eventType' ]
					. ') and YAML ('
					. $item_data[ 'eventType' ]
					. ')',
				1
			);
		}

		$chain_validation_data = $validation_data[ 'chain' ][ $xml_data[ 'eventType' ] ];

		// check validation data for correct setup of un/chained events
		if (
			0 == count ( $chain_validation_data[ 0 ] )
			&& 0 < count( $chain_validation_data[ 1 ] )
		) {
			throw new \Detail(
				'Provided chained event(s) require 1 unchained event',
				10,
				null, [
					'unchained' => null,
					'chained' => $chain_validation_data[ 1 ],
				]
			);
		}
		$unchained_event = $chain_validation_data[ 0 ][ key( $chain_validation_data[ 0 ] ) ];
		foreach( $chain_validation_data[ 1 ] as $chained_event ) {
			if (
				$chained_event[ 'start' ] <= $unchained_event[ 'start' ]
				|| $chained_event[ 'start' ] >= $unchained_event[ 'end' ]
			) {
				throw new \Detail(
					'Chained event must start within period of master event',
					10,
					null, [
						'unchained' => $unchained_event,
						'chained' => $chained_event,
					]
				);
			}
		}

		if ( isset( $chain_validation_data[ 1 ][ $item_name ] ) && false == $item_data[ 'params' ][ 'end' ] ) {
			// take unchained event's end in case of chained event without set end
			$item_data[ 'params' ][ 'end' ] = $unchained_event[ 'end' ];
		}

		// check for id saved within validation data for current event
		// to mark it as already existing
		if (
				// if imported event is a main event with existing id
				(
					!empty( $chain_validation_data[ 0 ] )
					&& !empty( $chain_validation_data[ 0 ][ $item_name ] )
					&& isset( $chain_validation_data[ 0 ][ $item_name ][ 'id' ] )
				)
				||
				// or if imported event is a chained event with existing id
				(
					!empty( $chain_validation_data[ 1 ] )
					&& !empty( $chain_validation_data[ 1 ][ $item_name ] )
					&& isset( $chain_validation_data[ 1 ][ $item_name ][ 'id' ] )
				)
		) {
			// return as for existing event
			return 0;
		}

		// overwrite event start+end from xml by passed values from yaml file
		$xml_data[ 'eventStart' ] = $item_data[ 'params' ][ 'start' ];
		$xml_data[ 'eventEnd' ] = $item_data[ 'params' ][ 'end' ];

		$ignored_chained_id_list = [];
		if (
			isset( $validation_data[ 'chain' ] )
			&& isset( $validation_data[ 'chain' ][ $xml_data[ 'eventType' ] ] )
		) {
			$event_validation_data = $validation_data[ 'chain' ][ $xml_data[ 'eventType' ] ];
			$main_event = key( $event_validation_data[ 0 ] );
			if (
				$main_event
				&& $main_event != $item_name
				&& isset( $event_validation_data[ 0 ][ $main_event ] )
				&& isset( $event_validation_data[ 0 ][ $main_event ][ 'id' ] )
			) {
				$ignored_chained_id_list[] = $event_validation_data[ 0 ][ $main_event ][ 'id' ];
			}
			foreach( $event_validation_data[ 1 ] as $chained_event_detail ) {
				if ( isset( $chained_event_detail[ 'id' ] ) ) {
					$ignored_chained_id_list[] = $chained_event_detail[ 'id' ];
				}
			}
		}

		// general event validation
		if ( \check_blocking_event(
			$instance_object,
			$xml_data[ 'eventStart' ],
			$xml_data[ 'eventEnd' ],
			$ignored_chained_id_list,
			$xml_data,
			$item_name
		) ) {
			return 0;
		}

		// check unrewarded events
		if (
			\method_exists( $instance_object, 'get_unrewarded_event' )
			&& !empty( $instance_object->get_unrewarded_event() )
		) {
			throw new \Detail(
				'unrewarded event found',
				12,
				null,
				$instance_object->get_unrewarded_event()
			);
		}
		// event specific validation
		if ( !$instance_object->specific_validation( $xml_data ) ) {
			throw new \Detail(
				'specific event validation failed',
				3
			);
		}
		// fetch default values for comparison
		$realm_default = $instance_object->get_values();
		$xml_default = $instance_object->get_values( [], $xml_data[ 'eventDefaultData' ] );
		// evaluate difference of default values
		$difference = \array_merge(
			\array_diff_recursive( $xml_default, $realm_default ),
			\array_diff_recursive( $realm_default, $xml_default )
		);
		// handle differences
		if ( !empty( $difference ) ) {
			throw new \Detail(
				'difference in default values',
				4,
				null,
				$difference
			);
		}
		// evaluate missing data and default data values in import
		$this->check_missing_data(
			$xml_data[ 'eventType' ],
			$xml_data[ 'eventData' ],
			false
		);
		$this->check_missing_data(
			$xml_data[ 'eventType' ],
			$xml_data[ 'eventDefaultData' ],
			true
		);
		// return
		return 1;
	}

	/**
	 * Event implementation of abstract method to import item within realm
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param int $validated_index
	 * @param string $item_key
	 * @param string $log_key
	 * @param int $import_start
	 * @param bool $import_success
	 * @param array $import_report
	 * @param array $import_log
	 * @param array $validation_data
	 * @global array $userdata
	 * @throws \Platform\Core\Exception\Data_Fault
	 */
	public function import_item(
		string $item_name,
		array $item_data,
		int $validated_index,
		string $item_key,
		string $log_key,
		int $import_start,
		bool &$import_success,
		array &$import_report,
		array &$import_log,
		array &$validation_data
	): void {
		global $userdata, $admin_raw_post;

		$xml_string = $item_data[ 'content' ][ $validated_index ];

		// replacing generic parameters
		if (
			isset( $item_data[ 'params' ][ 'generic' ] )
			&& !empty( $item_data[ 'params' ][ 'generic' ] )
		) {
			foreach ( $item_data[ 'params' ][ 'generic' ] as $generic_key => $generic_value ) {
				// replace if generic parameter was found
				if ( false !== \strpos( $xml_string, '#' .  $generic_key . '#' ) ) {
					$xml_string = \str_replace( '#' .  $generic_key . '#', $generic_value, $xml_string );
				}
			}
		}
		// load data from XML
		$imported_data = $this->get_data_from_xml( $xml_string );
		$event_type = $imported_data[ 'eventType' ];

		$chained_id = null;
		if (
			!empty( $validation_data[ 'chain' ][ $event_type ] )
			&& !empty( $validation_data[ 'chain' ][ $event_type ][ 1 ] )
			&& isset( $validation_data[ 'chain' ][ $event_type ][ 1 ][ $item_name ] )
		) {
			$main_event = key( $validation_data[ 'chain' ][ $event_type ][ 0 ] );
			$chained_id = $validation_data[ 'chain' ][ $event_type ][ 0 ][ $main_event ][ 'id' ];

			// take unchained main event's end in case of chained child event
			$item_data[ 'params' ][ 'end' ] =
				$validation_data[ 'chain' ][ $event_type ][ 0 ][ $main_event ][ 'end' ];
		}

		// Instantiate event class every time to gather event data
		require_once \APP_PATH . 'admin/event/' . $event_type . '.php';

		$event_object = new $event_type( Query::connection(), $this->math );
		// extracting data from display data filled with imported data
		// and deploying them as emulated post
		$admin_raw_post = $event_object->get_values(
			$imported_data[ 'eventData' ],
			$imported_data[ 'eventDefaultData' ]
		);
		$imported_data[ 'eventName' ] = $item_name;
		$imported_data[ 'eventStart' ] = $item_data[ 'params' ][ 'start' ];
		$imported_data[ 'eventEnd' ] = $item_data[ 'params' ][ 'end' ];

		// adding common event properties into emulated post array
		$admin_raw_post[ 'eventName' ] = $imported_data[ 'eventName' ];
		$admin_raw_post[ 'eventDescription' ]
			= $imported_data[ 'eventDescription' ];
		$admin_raw_post[ 'eventStart' ] = $imported_data[ 'eventStart' ];
		$admin_raw_post[ 'eventEnd' ] = $imported_data[ 'eventEnd' ];
		$admin_raw_post[ 'eventType' ] = $imported_data[ 'eventType' ];

		// generating saved data via preparing saved data/default data
		$imported_data[ 'eventData' ] = $event_object->set_save_data();
		$imported_data[ 'eventDefaultData' ]
			= $event_object->set_save_default_data();
		$imported_data[ 'eventAuthor' ] = $userdata[ 'user' ];
		$imported_data[ 'event_chained_id' ] = $chained_id ?? 'NULL';

		// store data generated saved data
		$import_id = $event_object->store_data( $imported_data );
		$import_result = ( $import_id > 0 );

		// saving ids of imported chained events
		if ( !empty( $validation_data[ 'chain' ][ $event_type ] ) ) {
			if (
				!empty( $validation_data[ 'chain' ][ $event_type ][ 0 ] )
				&& isset( $validation_data[ 'chain' ][ $event_type ][ 0 ][ $item_name ] )
			) {
				$validation_data[ 'chain' ][ $event_type ][ 0 ][ $item_name ][ 'id' ] = $import_id;
			} elseif (
				!empty( $validation_data[ 'chain' ][ $event_type ][ 1 ] )
				&& isset( $validation_data[ 'chain' ][ $event_type ][ 1 ][ $item_name ] )
			) {
				$validation_data[ 'chain' ][ $event_type ][ 1 ][ $item_name ][ 'id' ] = $import_id;
			}
		}

		$import_report[ $item_key ] = [
			$import_result, [
				'imported_file' => $item_data[ 'file' ][ $validated_index ],
				'import_duration' => \sprintf(
					'%.2f',
					( \getmicrotime() - $import_start ) / 1000
				),
				'import_date' => \time(),
			],
		];
		$import_log[ $log_key ][ $item_name ] = $import_result
			? $item_data[ 'file' ][ $validated_index ] . ' imported on ' . \time()
			: '';
		$import_success = $import_success && $import_result;
	}

	/**
	 * Event implementation for method to extract extra item data from yaml structure
	 *
	 * @param array $yaml_data data parsed from yaml
	 * @param array $realm realm data
	 * @return mixed false on error | associative data array
	 * @throws \Detail
	 */
	public function get_specific_data_from_yaml( array $yaml_data, array $realm ) {

		// get start and end
		$start = $yaml_data[ 'start' ] ?? '';
		$end = $yaml_data[ 'end' ] ?? false;

		// handle empty
		if ( empty( $start ) || ( empty( $end ) && !$yaml_data[ 'chained' ] ) ) {
			return false;
		}

		// transfer utc date time string to timestamp
		if ( \substr( $start, 0, 1 ) != '+' ) {
			$this->last_absolute_start_date = $start;
			// consistency check for start date
			if ( !\check_day_name_consistency( $start ) ) {
				throw new \Detail( 'Inconsistent start date ' . $start . ' in YAML provided', 1 );
			}
			$start = (
				new \DateTime( $start, new \DateTimeZone( $realm[ 'timezone' ] ) )
			)->getTimestamp();
		} else {
			// if start is defined by offset -> combine it with last absolute start date
			$start = (
				new \DateTime( $this->last_absolute_start_date . $start, new \DateTimeZone( $realm[ 'timezone' ] ) )
			)->getTimestamp();
		}

		// transfer utc date time string to timestamp
		if ( \substr( $end, 0, 1 ) == '+' ) {
			// if end is defined by offset -> combine it with last absolute start date and subtract last second
			$end = (
				new \DateTime( $this->last_absolute_start_date . $end, new \DateTimeZone( $realm[ 'timezone' ] ) )
			)->getTimestamp() - 1;
		} else if ( !empty( $end ) ) {
			// consistency check for end date
			if ( !\check_day_name_consistency( $end ) ) {
				throw new \Detail( 'Inconsistent end date ' . $end . ' in YAML provided', 1 );
			}
			// end is ignored for some events (chained)
			$end = (
				new \DateTime( $end, new \DateTimeZone( $realm[ 'timezone' ] ) )
			)->getTimestamp() - 1;
		}

		$param = [];
		if ( isset( $yaml_data[ 'param' ] ) && !empty( $yaml_data[ 'param' ] ) ) {
			$tmp = \explode( ";", $yaml_data[ 'param' ] );
			for ( $i = 0; $i < count( $tmp ); $i++ ) {
				$tmp[ $i ] = \explode( ":", $tmp[ $i ] );
				if ( isset( $tmp[ $i ][ 1 ] ) ) {
					$param[ $tmp[ $i ][ 0 ] ] = $tmp[ $i ][ 1 ];
				}
			}
		}

		return [
			'start' => $start,
			'end' => $end,
			'chained' => $yaml_data[ 'chained' ] ?? false,
			'generic' => $param,
		];
	}

	/**
	 * Event implementation of method to gather data before validation
	 *
	 * @param string $event_name
	 * @param array $event_data
	 * @param array $validation_data
	 */
	public function get_validation_data(
		string $event_name,
		array $event_data,
		array &$validation_data
	) {
		$validation_data[ 'type' ] ??= [];
		$validation_data[ 'chain' ] ??= [];

		$chained_key = $event_data[ 'params' ][ 'chained' ] ? 1 : 0;

		$instances_content = \implode( ' ', $event_data[ 'content' ] );
		$event_property_instance_list = [];
		\preg_match_all(
			'/<type><!\[CDATA\[([a-zA-Z]+)\]\]><\/type>/',
			$instances_content,
			$event_property_instance_list
		);
		// store unique event types into result array
		$validation_data[ 'type' ][ $event_name ] = array_keys( array_flip( $event_property_instance_list[ 1 ] ) );

		if ( 1 != count( $validation_data[ 'type' ][ $event_name ] ) ) {
			// interrupt data gathering if multiple XML instances
			// have more than one event type -> invalid XML
			return;
		}
		$event_type = $validation_data[ 'type' ][ $event_name ][ 0 ];

		// prepare result array structure for found type
		$validation_data[ 'chain' ][ $event_type ] ??= [];
		$validation_data[ 'chain' ][ $event_type ][ 0 ] ??= [];
		$validation_data[ 'chain' ][ $event_type ][ 1 ] ??= [];

		$event_detail = [
			'start' => $event_data[ 'params' ][ 'start' ],
			'end' => $event_data[ 'params' ][ 'end' ],
		];

		// Check if event already exists in DB
		$exists_query = "
				SELECT
					`eventID`
				FROM
					`game_event`
				WHERE
					`eventName` = '" . $event_name . "'
				AND `eventType` = '" . $event_type . "'
				AND `eventStart` = " . $event_detail[ 'start' ] . "
				AND " . ( $chained_key
						? "`event_chained_id` > 0"
						: "`eventEnd` = " . $event_detail[ 'end' ]
					);

		try {
			$existing_id = Query::select( $exists_query )->populate_column;

			if ( $existing_id ) {
				$event_detail[ 'id' ] = $existing_id;
			}
		} catch ( \Exception $exception ) {}

		// if there was any chained event before master event
		if (
			0 == $chained_key
			&& empty( $validation_data[ 'chain' ][ $event_type ][ 0 ] )
			&& !empty( $validation_data[ 'chain' ][ $event_type ][ 1 ] )
		) {
			// it will be marked, to alter the order before imports
			$event_detail[ 'switch_position' ] = true;
		}
		// assign current event by it's type and chained flag into result array
		$validation_data[ 'chain' ][ $event_type ][ $chained_key ][ $event_name ] = $event_detail;
	}

	/**
	 * Method checks event import data for missing data
	 *
	 * @param string $event_type
	 * @param array $data
	 * @param bool $default is the data to be checked default data or not
	 * @throws \Detail
	 * @global array $admin_raw_post
	 */
	private function check_missing_data(
		string $event_type,
		array $data,
		bool $default
	): void {
		global $admin_raw_post;
		/** @var \event $event */
		$event = new $event_type( Query::connection(), $this->math );
		$admin_raw_post = $event->get_values( $data, $data );
		$save_data = $event->set_save_data();
		if ( $default ) {
			$save_data = $event->set_save_default_data();
		}
		$missing_data = \array_diff_key_recursive( $save_data, $data );
		if ( !empty( $missing_data ) ) {
			throw new \Detail(
				'missing event' . ( $default ? ' default' : '' ) . ' data in xml',
				$default ? 9 : 8,
				null,
				$missing_data
			);
		}
	}

	/**
	 * Event implementation of method to manipulate imported events based on validation data
	 *
	 * @param array $validation_data
	 * @param array $item_list
	 */
	public function process_before_import( array $validation_data, &$item_list ): void {
		// for chained events we need to make sure main event is imported
		// before any chained event that points to it
		if ( !empty( $validation_data[ 'chain' ] ) ) {
			// if there are chained events pointing to main event
			foreach( $validation_data[ 'chain' ] as $chained_event_list ) {
				if( isset( $chained_event_list[ 0 ] ) && is_array( $chained_event_list[ 0 ] ) ) {
					$main_event = key( $chained_event_list[ 0 ] );
					// if main event is flagged to switch position
					if (
						!empty( $chained_event_list[ 0 ][ $main_event ] )
						&& isset( $chained_event_list[ 0 ][ $main_event ][ 'switch_position' ] )
						&& $chained_event_list[ 0 ][ $main_event ][ 'switch_position' ]
					) {
						// position will be switched with first chained event
						$switch = [ key( $chained_event_list[ 1 ] ), $main_event ];

						// position switch within item_list saved as new_item_list
						$new_item_list = [];
						foreach ( $item_list as $key => $value ) {
							if ( $key == $switch[ 0 ] ) {
								$new_item_list[ $switch[ 1 ] ] = $item_list[ $switch[ 1 ] ];
								$new_item_list[ $switch[ 0 ] ] = $item_list[ $switch[ 0 ] ];
							} else if ( $key !== $switch[ 1 ] && $key !== $switch[ 0 ] ) {
								$new_item_list[ $key ] = $value;
							}
						}
						// update of $item_list
						$item_list = $new_item_list;
					}
				}
			}
		}
	}

	/**
	 * Helper to check conflict (intersect) of start and end dates of two events
	 *
	 * @param array $event_a
	 * @param array $event_b
	 * @return bool true on conflict | false on no conflict
	 */
	private function check_event_conflict( array $event_a, array $event_b ) : bool {
		// if event_a starts in event_b interval
		if ( $event_a[ 'start' ] >= $event_b[ 'start' ] && $event_a[ 'start' ] <= $event_b[ 'end' ] ) {
			return true;
		}
		// if event_a ends in event_b interval
		if ( $event_a[ 'end' ] >= $event_b[ 'start' ] && $event_a[ 'end' ] <= $event_b[ 'end' ] ) {
			return true;
		}
		// if event_a encloses event_b interval
		if ( $event_a[ 'start' ] < $event_b[ 'start' ] && $event_a[ 'end' ] > $event_b[ 'end' ] ) {
			return true;
		}
		// if event_b encloses event_a interval
		if ( $event_b[ 'start' ] < $event_a[ 'start' ] && $event_b[ 'end' ] > $event_a[ 'end' ] ) {
			return true;
		}
		return false;
	}
}
