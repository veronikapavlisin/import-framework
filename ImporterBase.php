<?php

require \APP_PATH . 'admin/lib/fkt_makeXml.php';

abstract class importer
{
	/** @var string import type */
	protected string $type = '';

	/** @var \Platform\Core\Root */
	protected \Platform\Core\Root $root;

	/** @var \cBigMath */
	protected \cBigMath $math;

	/** @var string item type event */
	CONST TYPE_EVENT = 'event';

	/** @var string item type banner */
	CONST TYPE_BANNER = 'banner';

	/** @var string item type DO-news */
	CONST TYPE_MESSAGE_NEWS = 'message_news';

	/** @var string item type blackmarket happy-hour */
	CONST TYPE_HAPPY_HOUR = 'happy_hour';

	/**
	 * Constructor
	 *
	 */
	public function __construct( \Platform\Core\Root $root ) {
		$this->root = $root;
		$this->math = $this->root->math;
	}

	/**
	 * Abstract method to convert imported xml to data array
	 *
	 * @param string $xml xml data
	 * @return array associative data array
	 */
	abstract public function get_data_from_xml( string $xml ): array;

	/**
	 * Abstract method to validate item within realm
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param array $validation_sata
	 * @return array validation status
	 */
	abstract public function validate_item(
		string $item_name,
		array $item_data,
		array $validation_sata
	): array;

	/**
	 * Abstract method to validate instance within realm
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param array $xml_data
	 * @param array $validation_data
	 * @param object $instance_object
	 * @return int validation status
	 */
	abstract public function validate_instance(
		string $item_name,
		array $item_data,
		array $xml_data,
		array $validation_data,
		object $instance_object = null
	): int;

	/**
	 * Abstract method to import item within realm
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
	 */
	abstract public function import_item(
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
	): void;

	/**
	 * Method to extract extra item data from yaml structure
	 *
	 * @param array $yaml_data data parsed from yaml
	 * @param array $realm realm data
	 * @return mixed false on error | associative data array
	 */
	public function get_specific_data_from_yaml( array $yaml_data, array $realm ) {
		// by default no new data is extracted
		// method is overridden for different types of import
		return [];
	}

	/**
	 * Method to gather data before validation
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param array $validation_data
	 */
	public function get_validation_data(
		string $item_name,
		array $item_data,
		array &$validation_data
	) {
		// by default nothing is changed
		// method is overridden for different types of import
	}

	/**
	 * Prepares validation status and details output for validation/import report
	 *
	 * @param array $result
	 * @param string $index_prefix
	 * @return array
	 */
	public function get_validation_output ( array $result, string $index_prefix ): array {
		$control_index = 0;
		$validation_details_html = '';
		// show different status based on result_code (true|exception code)
		if ( true === $result[ 0 ] ) {
			$validation_status = 'VALID';
		} elseif ( 5 === $result[ 0 ] ) {
			// validation identified item already been imported
			// via type and name match
			$validation_status = 'ALREADY IMPORTED';
		} elseif ( 6 === $result[ 0 ] ) {
			// validation identified item already existing
			// and possible to update
			$validation_status = 'EXISTING';
		} elseif ( -1 === $result[ 0 ] ) {
			$validation_status = 'CONNECT FAILURE';
			$validation_details_html = $result[ 1 ];
		} elseif ( 10 === $result[ 0 ] ) {
			$validation_status = 'INVALID';
			$validation_details_html = $result[ 1 ];
		} else {
			$validation_status = 'INVALID';

			$validation_details_html = '<table cellpadding="0" cellspacing="0"><tr>';

			foreach ( $result[ 1 ] as $file_name => $instance_result_data ) {

				switch ( $instance_result_data[ 0 ] ) {
					case 1:
						// validation fount out that same parameter was missing
						$invalidation_status = 'PARAMETER MISSING';
						break;
					case 2:
						// validation found existing parameter to be invalid
						$invalidation_status = 'INVALID PARAMETER';
						break;
					case 3:
						// specific validation found an issue
						$invalidation_status = 'SPECIFIC INVALID';
						break;
					case 4:
						// validation found mismatch of default values
						$invalidation_status = 'DEFAULT VALUES INVALID';
						break;
					case 6:
						// conflict within generated display data detected
						// by event::prepareDisplayData
						$invalidation_status = 'CONFLICT DETECTED';
						break;
					case 7:
						// validation found more than one type of event in XMLs
						// suggested for one imported event
						$invalidation_status = 'XML EVENT TYPE MISMATCH';
						break;
					case 8:
						// validation fount out that less data keys were sent in XML
						$invalidation_status = 'XML DATA MISSING';
						break;
					case 9:
						// validation fount out that less default data keys were sent in XML
						$invalidation_status = 'XML DEFAULT DATA MISSING';
						break;
					case 10:
						// validation fount issue in configuration (either in XML or YAML)
						$invalidation_status = 'CONFIGURATION ERROR';
						break;
					case 11:
						// validation found blocking event
						$invalidation_status = 'BLOCKING EVENT';
						break;
					case 12:
						// validation found existing unfinished event of imported type
						$invalidation_status = 'UNREWARDED EVENT';
						break;
					case 13:
						// validation found unexpected XML structure based on import type
						$invalidation_status = 'UNRECOGNIZED XML';
						break;
					case -1:
						// validation was unable to connect to realm
						$invalidation_status = 'CONNECTION ERROR';
						break;
					default:
						$invalidation_status = 'UNSPECIFIC EXCEPTION';
						break;
				}

				$result_message = $instance_result_data[ 1 ];
				// add contents of provided result_data in readable form to a message
				// that will be shown as title of report's cell with validation status
				if ( !empty( $instance_result_data[ 2 ] ) ) {
					// replace timestamps with readable date format
					if ( isset( $instance_result_data[ 2 ][ 0 ] ) ) {
						for ( $i = 0; $i < count( $instance_result_data[ 2 ] ); $i++ ) {
							if ( isset( $instance_result_data[ 2 ][ $i ][ 'eventStart' ] ) ) {
								$instance_result_data[ 2 ][ $i ][ 'eventStart' ] = date(
									'd.m.Y H:i',
									$instance_result_data[ 2 ][ $i ][ 'eventStart' ]
								);
							}
							if ( isset( $instance_result_data[ 2 ][ $i ][ 'eventEnd' ] ) ) {
								$instance_result_data[ 2 ][ $i ][ 'eventEnd' ] = date(
									'd.m.Y H:i',
									$instance_result_data[ 2 ][ $i ][ 'eventEnd' ]
								);
							}
						}
					}
					$result_message .= "\r\n" . \var_export( $instance_result_data[ 2 ], true );
				}

				$control_id = 'details_' . $index_prefix . '_' . $control_index++;

				$validation_details_html .= '<td style="padding-right: 10px;">'
					. '<span>' . $invalidation_status . '</span>' . '<br/>'
					. '<input type="button" class="invalidation_detail" id="' . $control_id .'_btn" '
					. 'title="Click to show details" style="cursor:s-resize;" '
					. ' value="' . $file_name . '">'
					. '<br/>'
					. '<span id="' . $control_id . '" style="display:none;"><PRE>'
					. $result_message . '</PRE></span></td>';
			}
			$validation_details_html .= '</tr></table>';
		}

		return [ $validation_status, $validation_details_html, ];
	}

	/**
	 * Method to manipulate imported data based on validation data
	 *
	 * @param array $validation_data
	 * @param array $item_list
	 */
	public function process_before_import( array $validation_data, array &$item_list ): void {
		// by default nothing is changed
		// method is overridden for different types of import
	}
}
