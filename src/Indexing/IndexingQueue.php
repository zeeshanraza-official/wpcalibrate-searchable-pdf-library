<?php
/**
 * Background indexing queue manager for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Indexing;

use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Extraction\PdfExtractorInterface;
use WPCalibrate\SearchablePdfLibrary\Extraction\ExtractionResult;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;
use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;

/**
 * Class IndexingQueue
 *
 * Handles asynchronous background extraction and indexing using Action Scheduler
 * or WP-Cron with bounded batches and expiration locks.
 */
class IndexingQueue {

	public const CRON_HOOK         = 'wpcal_spl_process_indexing_queue';
	public const LOCK_TRANSIENT    = 'wpcal_spl_indexing_lock';
	public const LOCK_TIMEOUT_SECS = 600; // 10 minutes lock expiration

	private PdfExtractorInterface $extractor;
	private IndexWriter $writer;

	/**
	 * Constructor.
	 *
	 * @param PdfExtractorInterface $extractor PDF extractor.
	 * @param IndexWriter           $writer Index writer.
	 */
	public function __construct( PdfExtractorInterface $extractor, IndexWriter $writer ) {
		$this->extractor = $extractor;
		$this->writer    = $writer;
	}

	/**
	 * Initialize queue hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( self::CRON_HOOK, [ $this, 'handle_cron_batch' ] );
		add_action( 'wpcal_spl_index_single_document', [ $this, 'process_single_document' ] );

		// Custom cron schedule interval (every 2 minutes) for responsive background processing
		add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );
	}

	/**
	 * Add 2-minute interval to cron schedules.
	 *
	 * @param array<string, mixed> $schedules Existing schedules.
	 * @return array<string, mixed>
	 */
	public function add_cron_interval( array $schedules ): array {
		$schedules['wpcal_spl_two_minutes'] = [
			'interval' => 120,
			'display'  => esc_html__( 'Every 2 Minutes (PDF Queue)', 'wpcalibrate-searchable-pdf-library' ),
		];
		return $schedules;
	}

	/**
	 * Schedule background queue.
	 *
	 * @return void
	 */
	public function schedule_queue(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'wpcal_spl_two_minutes', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule background queue.
	 *
	 * @return void
	 */
	public function unschedule_queue(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Queue a single document for background indexing.
	 *
	 * @param int $document_id Document ID.
	 * @return bool
	 */
	public function queue_document( int $document_id ): bool {
		$document = new DocumentModel( $document_id );
		if ( $document->get_id() <= 0 ) {
			return false;
		}

		$document->set_indexing_status( IndexStatus::QUEUED );
		$document->save_meta();

		// If Action Scheduler is present, use it for instant asynchronous dispatch
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wpcal_spl_index_single_document', [ 'document_id' => $document_id ], 'wpcal_spl' );
		} else {
			// Trigger immediate non-blocking spawn of WP-Cron if not locked
			$this->spawn_cron_if_needed();
		}

		return true;
	}

	/**
	 * Queue all managed documents for re-indexing.
	 *
	 * @param bool $only_failed_or_unindexed Only queue non-indexed or failed documents.
	 * @return int Number of documents queued.
	 */
	public function queue_all( bool $only_failed_or_unindexed = false ): int {
		global $wpdb;

		$meta_clause = '';
		if ( $only_failed_or_unindexed ) {
			$meta_clause = $wpdb->prepare(
				"AND (pm.meta_value IS NULL OR pm.meta_value IN (%s, %s, %s))",
				IndexStatus::NOT_INDEXED,
				IndexStatus::FAILED,
				IndexStatus::NEEDS_ATTENTION
			);
		}

		$sql = "SELECT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s)
				WHERE p.post_type = %s AND p.post_status = 'publish' {$meta_clause}";

		$ids = $wpdb->get_col( $wpdb->prepare( $sql, DocumentPostType::META_INDEXING_STATUS, DocumentPostType::POST_TYPE ) );
		$count = 0;

		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$this->queue_document( (int) $id );
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Retry failed documents.
	 *
	 * @return int
	 */
	public function retry_failed(): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON (p.ID = pm.post_id AND p.post_status = 'publish')
				 WHERE pm.meta_key = %s AND pm.meta_value = %s",
				DocumentPostType::META_INDEXING_STATUS,
				IndexStatus::FAILED
			)
		);

		$count = 0;
		if ( ! empty( $ids ) ) {
			foreach ( $ids as $id ) {
				$this->queue_document( (int) $id );
				$count++;
			}
		}

		return $count;
	}

	/**
	 * WP-Cron handler for batch processing.
	 *
	 * @return void
	 */
	public function handle_cron_batch(): void {
		$settings   = get_option( 'wpcal_spl_settings', [] );
		$batch_size = max( 1, min( 20, (int) ( $settings['batch_size'] ?? 5 ) ) );
		$this->process_batch( $batch_size );
	}

	/**
	 * Process a batch of queued documents with concurrency locking.
	 *
	 * @param int $limit Maximum number of documents to process in this run.
	 * @return int Number of processed documents.
	 */
	public function process_batch( int $limit = 5 ): int {
		// Acquire transient lock
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			Logger::info( 'Indexing batch skipped: Lock active.' );
			return 0;
		}

		set_transient( self::LOCK_TRANSIENT, time(), self::LOCK_TIMEOUT_SECS );

		$processed = 0;

		try {
			global $wpdb;

			// Fetch queued documents
			$doc_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON (p.ID = pm.post_id AND pm.meta_key = %s AND pm.meta_value = %s)
					 WHERE p.post_type = %s AND p.post_status = 'publish'
					 ORDER BY p.ID ASC
					 LIMIT %d",
					DocumentPostType::META_INDEXING_STATUS,
					IndexStatus::QUEUED,
					DocumentPostType::POST_TYPE,
					$limit
				)
			);

			if ( empty( $doc_ids ) ) {
				return 0;
			}

			$custom_field_manager = \WPCalibrate\SearchablePdfLibrary\Plugin::get_instance()
				->get_container()
				->get( CustomFieldManager::class );
			$custom_fields = $custom_field_manager->get_fields();

			foreach ( $doc_ids as $doc_id ) {
				$this->execute_document_indexing( (int) $doc_id, $custom_fields );
				$processed++;
			}

		} catch ( \Throwable $t ) {
			Logger::error( 'Fatal exception in process_batch: ' . $t->getMessage() );
		} finally {
			// Always release lock
			delete_transient( self::LOCK_TRANSIENT );
		}

		return $processed;
	}

	/**
	 * Process a single document callback (Action Scheduler / WP-Cron).
	 *
	 * Accepts int ID or associative array from Action Scheduler payloads.
	 *
	 * @param mixed $document_id Document ID or action args array.
	 * @return void
	 */
	public function process_single_document( mixed $document_id ): void {
		if ( is_array( $document_id ) ) {
			$document_id = $document_id['document_id'] ?? ( reset( $document_id ) ?: 0 );
		}

		$id = absint( $document_id );
		if ( $id <= 0 ) {
			return;
		}

		$custom_field_manager = \WPCalibrate\SearchablePdfLibrary\Plugin::get_instance()
			->get_container()
			->get( CustomFieldManager::class );
		$this->execute_document_indexing( $id, $custom_field_manager->get_fields() );
	}

	/**
	 * Perform the actual extraction and indexing for a single document.
	 *
	 * @param int $document_id Document ID.
	 * @param array<string, mixed> $custom_fields Custom fields.
	 * @return bool
	 */
	private function execute_document_indexing( int $document_id, array $custom_fields ): bool {
		$document = new DocumentModel( $document_id );
		if ( $document->get_id() <= 0 ) {
			return false;
		}

		$document->set_indexing_status( IndexStatus::PROCESSING );
		$document->save_meta();

		$file_path = $document->get_file_path();
		if ( false === $file_path || ! file_exists( $file_path ) ) {
			$fail_result = ExtractionResult::failure( __( 'Referenced PDF file could not be found on server disk.', 'wpcalibrate-searchable-pdf-library' ) );
			$this->writer->write_index( $document, $fail_result, $custom_fields );
			return false;
		}

		// Perform text extraction
		$extraction_result = $this->extractor->extract( $file_path );

		// Write to database index
		return $this->writer->write_index( $document, $extraction_result, $custom_fields );
	}

	/**
	 * Non-blocking spawn of WP-Cron.
	 *
	 * @return void
	 */
	private function spawn_cron_if_needed(): void {
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}
}
