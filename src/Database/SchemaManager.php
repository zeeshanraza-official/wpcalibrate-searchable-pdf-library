<?php
/**
 * Database schema and migration manager for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Database;

use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;

/**
 * Class SchemaManager
 *
 * Manages custom index table creation, versioned migrations, and FULLTEXT support detection.
 */
class SchemaManager {

	/**
	 * Target database schema version.
	 */
	public const DB_VERSION = '1.0.0';

	/**
	 * Option key for DB version.
	 */
	public const DB_VERSION_OPTION = 'wpcal_spl_db_version';

	/**
	 * Option key for fulltext capability flag.
	 */
	public const FULLTEXT_CAPABILITY_OPTION = 'wpcal_spl_fulltext_capable';

	/**
	 * Get the index table name.
	 *
	 * @return string
	 */
	public function get_index_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpcal_spl_index';
	}

	/**
	 * Create or update custom database tables using dbDelta.
	 *
	 * @return bool True on success.
	 */
	public function create_or_update_tables(): bool {
		global $wpdb;

		if ( file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$table_name      = $this->get_index_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta requires two spaces after PRIMARY KEY and specific casing.
		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			document_id bigint(20) unsigned NOT NULL,
			attachment_id bigint(20) unsigned DEFAULT NULL,
			page_number int(10) unsigned DEFAULT NULL,
			chunk_number int(10) unsigned NOT NULL DEFAULT 0,
			title_text text DEFAULT NULL,
			metadata_text longtext DEFAULT NULL,
			body_text longtext DEFAULT NULL,
			content_hash char(64) NOT NULL DEFAULT '',
			indexed_at datetime NOT NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY document_id (document_id),
			KEY attachment_id (attachment_id),
			KEY page_number (page_number),
			KEY content_hash (content_hash)
		) {$charset_collate};";

		$result = dbDelta( $sql );
		Logger::info( 'Schema dbDelta executed for table ' . $table_name, [ 'result' => $result ] );

		// Check and attempt FULLTEXT indexes creation.
		$this->ensure_fulltext_indexes();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		return true;
	}

	/**
	 * Check if the database engine supports FULLTEXT indexes and add them if possible.
	 *
	 * @return bool True if FULLTEXT index exists and is operational.
	 */
	public function ensure_fulltext_indexes(): bool {
		global $wpdb;
		$table_name = $this->get_index_table_name();

		// Verify table exists before querying index metadata.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			return false;
		}

		// Check if FULLTEXT indexes already exist.
		$existing_indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		$has_ft_body      = false;
		$has_ft_title     = false;

		if ( is_array( $existing_indexes ) ) {
			foreach ( $existing_indexes as $index ) {
				if ( isset( $index['Index_type'] ) && 'FULLTEXT' === strtoupper( $index['Index_type'] ) ) {
					if ( 'ft_body_text' === ( $index['Key_name'] ?? '' ) ) {
						$has_ft_body = true;
					}
					if ( 'ft_title_meta' === ( $index['Key_name'] ?? '' ) ) {
						$has_ft_title = true;
					}
				}
			}
		}

		if ( $has_ft_body && $has_ft_title ) {
			update_option( self::FULLTEXT_CAPABILITY_OPTION, 'yes' );
			return true;
		}

		// Attempt to add FULLTEXT indexes safely.
		// Suppress errors in case of legacy MySQL / engine limitations.
		$wpdb->suppress_errors( true );

		if ( ! $has_ft_body ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$res1 = $wpdb->query( "ALTER TABLE {$table_name} ADD FULLTEXT INDEX ft_body_text (body_text)" );
			if ( false === $res1 ) {
				Logger::warning( 'Could not create FULLTEXT index ft_body_text: ' . $wpdb->last_error );
			}
		}

		if ( ! $has_ft_title ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
			$res2 = $wpdb->query( "ALTER TABLE {$table_name} ADD FULLTEXT INDEX ft_title_meta (title_text, metadata_text)" );
			if ( false === $res2 ) {
				Logger::warning( 'Could not create FULLTEXT index ft_title_meta: ' . $wpdb->last_error );
			}
		}

		$wpdb->suppress_errors( false );

		// Re-verify after creation attempt.
		$test_indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name}", ARRAY_A );
		$ft_supported = false;
		if ( is_array( $test_indexes ) ) {
			foreach ( $test_indexes as $index ) {
				if ( isset( $index['Index_type'] ) && 'FULLTEXT' === strtoupper( $index['Index_type'] ) ) {
					$ft_supported = true;
					break;
				}
			}
		}

		update_option( self::FULLTEXT_CAPABILITY_OPTION, $ft_supported ? 'yes' : 'no' );
		return $ft_supported;
	}

	/**
	 * Check whether FULLTEXT search is operational.
	 *
	 * @return bool
	 */
	public function is_fulltext_capable(): bool {
		$status = get_option( self::FULLTEXT_CAPABILITY_OPTION, null );
		if ( null === $status ) {
			return $this->ensure_fulltext_indexes();
		}
		return 'yes' === $status;
	}

	/**
	 * Diagnostic check of database and index table health.
	 *
	 * @return array<string, mixed>
	 */
	public function check_health(): array {
		global $wpdb;
		$table_name = $this->get_index_table_name();

		$table_exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		$row_count    = 0;
		if ( $table_exists ) {
			$row_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
		}

		$ft_capable = $this->is_fulltext_capable();
		$version    = get_option( self::DB_VERSION_OPTION, '0.0.0' );

		return [
			'table_name'         => $table_name,
			'table_exists'       => $table_exists,
			'total_indexed_rows' => $row_count,
			'fulltext_supported' => $ft_capable,
			'schema_version'     => $version,
			'is_latest_version'  => version_compare( (string) $version, self::DB_VERSION, '>=' ),
		];
	}
}
