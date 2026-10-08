<?php
/**
 * Maintenance and indexing utility tools for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Tools;

use WPCalibrate\SearchablePdfLibrary\Database\SchemaManager;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexingQueue;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexStatus;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;

/**
 * Class MaintenanceTools
 *
 * Provides administrative tools to purge stale data, verify health, and rebuild search indexes.
 */
class MaintenanceTools {

	private SchemaManager $schema_manager;
	private IndexingQueue $queue;

	/**
	 * Constructor.
	 *
	 * @param SchemaManager $schema_manager Schema manager.
	 * @param IndexingQueue $queue Indexing queue.
	 */
	public function __construct( SchemaManager $schema_manager, IndexingQueue $queue ) {
		$this->schema_manager = $schema_manager;
		$this->queue          = $queue;
	}

	/**
	 * Remove stale rows in the index table whose documents were deleted or trashed in WordPress.
	 *
	 * @return int Number of deleted stale rows.
	 */
	public function purge_stale_index_rows(): int {
		global $wpdb;

		$table_name = $this->schema_manager->get_index_table_name();

		// Stale rows: document_id does not exist in wp_posts or post_status is trash
		$sql = "DELETE i FROM {$table_name} i
				LEFT JOIN {$wpdb->posts} p ON (i.document_id = p.ID AND p.post_type = %s AND p.post_status = 'publish')
				WHERE p.ID IS NULL";

		$deleted = $wpdb->query( $wpdb->prepare( $sql, DocumentPostType::POST_TYPE ) );
		$count   = false !== $deleted ? (int) $deleted : 0;

		Logger::info( "Purged {$count} stale rows from {$table_name}." );
		return $count;
	}

	/**
	 * Completely rebuild the search index by clearing index table and re-queueing all documents.
	 *
	 * @return int Total documents queued for rebuild.
	 */
	public function rebuild_entire_index(): int {
		global $wpdb;

		$table_name = $this->schema_manager->get_index_table_name();

		// Truncate or delete all rows in index table
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		// Reset postmeta status for all managed documents to queued
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON (p.ID = pm.post_id AND p.post_type = %s)
				 SET pm.meta_value = %s
				 WHERE pm.meta_key = %s",
				DocumentPostType::POST_TYPE,
				IndexStatus::QUEUED,
				DocumentPostType::META_INDEXING_STATUS
			)
		);

		// Queue all
		$queued_count = $this->queue->queue_all( false );
		Logger::info( "Search index rebuilt initiated. Queued {$queued_count} documents." );

		return $queued_count;
	}
}
