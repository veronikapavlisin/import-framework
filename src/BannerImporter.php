<?php

class importer_banner
extends importer {

	/**
	 * Constructor
	 *
	 */
	public function __construct( \Platform\Core\Root $root ) {
		parent::__construct( $root );
		$this->type = importer::TYPE_BANNER;
	}

	/**
	 * Banner implementation for method to convert xml to data array
	 *
	 * @param string $xml xml data
	 * @return array associative data array
	 *
	 * @throws \Detail
	 */
	public function get_data_from_xml( string $xml ): array {
		$xml_array = \convertXmlToArrayRecursive( $xml );
		// handle empty or incorrect xml
		if ( empty( $xml_array ) || !isset( $xml_array[ 'banner' ] ) ) {
			throw new \Detail(
				'Supplied XML does not have a valid banner XML structure', 13
			);
		}
		// return banner data
		return [
			'name' => $xml_array[ 'banner' ][ 'name' ] ?? '',
			'image_url' => $xml_array[ 'banner' ][ 'imageUrl' ] ?? '',
			'alt' => $xml_array[ 'banner' ][ 'alt' ] ?? '',
			'tooltip' => isset( $xml_array[ 'banner' ][ 'tooltip' ] )
				? \htmlentities( $xml_array[ 'banner' ][ 'tooltip' ], \ENT_COMPAT ) : '',
			'url' => $xml_array[ 'banner' ][ 'linkUrl' ] ?? '',
			'date_start' => $xml_array[ 'banner' ][ 'dateStart' ] ?? '',
			'date_end' => $xml_array[ 'banner' ][ 'dateEnd' ] ?? '',
			'order' => $xml_array[ 'banner' ][ 'order' ] ?? '',
			'width' => $xml_array[ 'banner' ][ 'width' ] ?? '',
			'height' => $xml_array[ 'banner' ][ 'height' ] ?? '',
			'active' => $xml_array[ 'banner' ][ 'active' ] ?? '',
			'position' => $xml_array[ 'banner' ][ 'position' ] ?? '',
			'target' => $xml_array[ 'banner' ][ 'target' ] ?? '',
			'start_message_subject' => $xml_array[ 'banner' ][ 'newsSubjectStart' ] ?? '',
			'start_message_body' => $xml_array[ 'banner' ][ 'newsMessageStart' ] ?? '',
			'start_message_geo' => $xml_array[ 'banner' ][ 'newsGeoStart' ] ?? '[]',
			'end_message_subject' => $xml_array[ 'banner' ][ 'newsSubjectEnd' ] ?? '',
			'end_message_body' => $xml_array[ 'banner' ][ 'newsMessageEnd' ] ?? '',
			'end_message_geo' => $xml_array[ 'banner' ][ 'newsGeoEnd' ] ?? '[]',
		];
	}

	/**
	 * Banner implementation for method to validate item within realm
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
		// cycle through XML for validated banner
		foreach ( $item_data[ 'content' ] as $index => $banner_xml_instance ) {
			if ( '' == $banner_xml_instance ) {
				continue;
			}
			try {
				// transform XML to array
				$xml_data = $this->get_data_from_xml( $banner_xml_instance );

				// validation function
				$validation_result = $this->validate_instance(
					$item_name,
					$item_data,
					$xml_data,
					$validation_data
				);
				if ( 1 === $validation_result ) {
					// validation is interrupted with first validated XML
					$validated_index = $index;
					$result_code = true;
					$result_detail_list = [ 'valid_file' => $item_data[ 'file' ][ $index ], ];
					break;
				} elseif ( 0 === $validation_result ) {
					// banner detected as already existing will be marked with special
					// code and won't be interpreted as import showstopper
					$result_code = 6;
					$validated_index = $index;
					$result_detail_list = [];
				}
			} catch ( \Exception $exception ) {
				// saving under name of file in report list
				$result_detail_list[ $item_data[ 'file' ][ $index ] ] = [
					$exception->getCode(),
					$exception->getMessage(),
					null,
				];
			}
		}
		return [ $result_code, $result_detail_list, $validated_index, ];
	}

	/**
	 * Banner implementation for abstract method to validate instance
	 *
	 * @param string $item_name
	 * @param array $item_data
	 * @param array $xml_data
	 * @param array $validation_data
	 * @param object $instance_object
	 * @return int 0 when validation passes and banner exists,
	 *             1 when validation passes and banner does not exist yet
	 *
	 * @throws \Platform\Core\Exception\Data_Fault at any validation fail
	 */
	public function validate_instance(
		string $item_name,
		array $item_data,
		array $xml_data,
		array $validation_data,
		object $instance_object = null
	): int {
		// to make sure request is not cached
		$this->root->model_manager->cleanup();

		// execute validation
		$result = $this->root->output_manager->fetch(
			'banner/admin/validate_save',
			new \Platform\Core\Output\Http_Data_Request( $this->root, $xml_data ),
			false
		);

		// validation passed
		return isset( $result[ 'exists' ] ) && $result[ 'exists' ] ? 0 : 1;
	}

	/**
	 * Banner implementation of abstract method to import item within realm
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
		// load data from XML
		$data = $this->get_data_from_xml( $item_data[ 'content' ][ $validated_index ] );

		// to make sure request is not cached
		$this->root->model_manager->cleanup();

		// execute save
		$result = $this->root->output_manager->fetch(
			'banner/admin/save',
			new \Platform\Core\Output\Http_Data_Request( $this->root, $data ),
			false
		);

		$import_result = ( !empty( $result ) && !empty( $result[ 'id' ] ) );

		$import_report[ $item_key ] = [
			$import_result, [
				'imported_file' => $item_data[ 'file' ][ $validated_index ],
				'import_duration' => \sprintf(
					'%.2f',
					( \getmicrotime() - $import_start ) / 1000
				),
			],
		];
		if (
			!empty( $result )
			&& isset( $result[ 'updated' ] )
			&& null !== $result[ 'updated' ]
		) {
			$import_report[ $item_key ][ 0 ] = $result[ 'updated' ] ? true : 0;
			$import_report[ $item_key ][ 1 ][ 'updated' ] = $result[ 'updated' ];
		}
		$import_log[ $log_key ][ $item_name ] = $import_result
			? $item_data[ 'file' ][ $validated_index ] . ' imported on ' . \time()
			: '';
		$import_success = $import_success && $import_result;
	}
}
