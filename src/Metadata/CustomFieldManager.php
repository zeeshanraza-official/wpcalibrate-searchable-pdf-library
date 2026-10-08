<?php
/**
 * Custom field manager for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Metadata;

/**
 * Class CustomFieldManager
 *
 * Manages administrator-defined custom metadata fields and validation.
 */
class CustomFieldManager {

	/**
	 * Option key where custom field definitions are saved.
	 */
	public const OPTION_KEY = 'wpcal_spl_custom_fields';

	/**
	 * Reserved keys that cannot be used as custom field keys.
	 */
	public const RESERVED_KEYS = [
		'id',
		'title',
		'content',
		'excerpt',
		'author',
		'publication_date',
		'publication_year',
		'document_number',
		'description',
		'category',
		'tag',
		'access_mode',
		'allowed_roles',
		'status',
		'file',
		'page',
		'page_number',
		'search',
		's',
		'sort',
		'order',
	];

	/**
	 * Supported custom field types.
	 */
	public const SUPPORTED_TYPES = [
		'text',
		'number',
		'date',
		'select',
		'checkbox',
	];

	/**
	 * Get all registered custom field definitions.
	 *
	 * @return array<string, array<string, mixed>> Keyed by sanitized field key.
	 */
	public function get_fields(): array {
		$fields = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $fields ) ) {
			return [];
		}
		return $fields;
	}

	/**
	 * Get only searchable custom fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_searchable_fields(): array {
		return array_filter(
			$this->get_fields(),
			static fn( array $field ): bool => ! empty( $field['searchable'] )
		);
	}

	/**
	 * Get only filterable custom fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_filterable_fields(): array {
		return array_filter(
			$this->get_fields(),
			static fn( array $field ): bool => ! empty( $field['filterable'] )
		);
	}

	/**
	 * Get fields configured to display in search result cards/rows.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_display_fields(): array {
		return array_filter(
			$this->get_fields(),
			static fn( array $field ): bool => ! empty( $field['display_in_results'] )
		);
	}

	/**
	 * Add or update a custom field definition.
	 *
	 * @param array<string, mixed> $field_data Raw field definition.
	 * @return true|\WP_Error
	 */
	public function save_field( array $field_data ): true|\WP_Error {
		$key   = sanitize_key( $field_data['key'] ?? '' );
		$label = sanitize_text_field( $field_data['label'] ?? '' );
		$type  = sanitize_key( $field_data['type'] ?? 'text' );

		if ( empty( $label ) ) {
			return new \WP_Error( 'missing_label', __( 'Field label is required.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		if ( empty( $key ) ) {
			$key = sanitize_key( str_replace( ' ', '_', strtolower( $label ) ) );
		}

		// Ensure prefix if not present to avoid conflicts.
		if ( ! str_starts_with( $key, 'cf_' ) ) {
			$key = 'cf_' . $key;
		}

		if ( in_array( str_replace( 'cf_', '', $key ), self::RESERVED_KEYS, true ) || in_array( $key, self::RESERVED_KEYS, true ) ) {
			return new \WP_Error( 'reserved_key', __( 'The field key is reserved. Please choose a different key.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		if ( ! in_array( $type, self::SUPPORTED_TYPES, true ) ) {
			$type = 'text';
		}

		$options = [];
		if ( 'select' === $type && ! empty( $field_data['options'] ) ) {
			if ( is_string( $field_data['options'] ) ) {
				$lines   = explode( "\n", str_replace( "\r", '', $field_data['options'] ) );
				$options = array_values( array_filter( array_map( 'trim', $lines ) ) );
			} elseif ( is_array( $field_data['options'] ) ) {
				$options = array_values( array_map( 'sanitize_text_field', $field_data['options'] ) );
			}
		}

		$cleaned_field = [
			'key'                => $key,
			'label'              => $label,
			'type'               => $type,
			'description'        => sanitize_text_field( $field_data['description'] ?? '' ),
			'options'            => $options,
			'required'           => ! empty( $field_data['required'] ),
			'searchable'         => ! empty( $field_data['searchable'] ),
			'filterable'         => ! empty( $field_data['filterable'] ),
			'display_in_results' => ! empty( $field_data['display_in_results'] ),
		];

		$all_fields         = $this->get_fields();
		$all_fields[ $key ] = $cleaned_field;
		update_option( self::OPTION_KEY, $all_fields );

		return true;
	}

	/**
	 * Delete a custom field definition.
	 *
	 * @param string $field_key Field key to delete.
	 * @return bool
	 */
	public function delete_field( string $field_key ): bool {
		$sanitized_key = sanitize_key( $field_key );
		$all_fields    = $this->get_fields();

		if ( ! isset( $all_fields[ $sanitized_key ] ) ) {
			return false;
		}

		unset( $all_fields[ $sanitized_key ] );
		update_option( self::OPTION_KEY, $all_fields );
		return true;
	}

	/**
	 * Validate and sanitize a value according to field definition.
	 *
	 * @param array<string, mixed> $field Field definition.
	 * @param mixed                $value Input value.
	 * @return mixed Sanitized value.
	 */
	public function sanitize_field_value( array $field, mixed $value ): mixed {
		$type = $field['type'] ?? 'text';

		switch ( $type ) {
			case 'number':
				if ( '' === $value || null === $value ) {
					return null;
				}
				return is_numeric( $value ) ? (float) $value : 0.0;

			case 'date':
				if ( empty( $value ) ) {
					return '';
				}
				$date_str = sanitize_text_field( (string) $value );
				$timestamp = strtotime( $date_str );
				return false !== $timestamp ? gmdate( 'Y-m-d', $timestamp ) : '';

			case 'checkbox':
				return ! empty( $value ) ? 1 : 0;

			case 'select':
				$str_val = sanitize_text_field( (string) $value );
				$options = $field['options'] ?? [];
				if ( ! empty( $options ) && ! in_array( $str_val, $options, true ) ) {
					return '';
				}
				return $str_val;

			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
