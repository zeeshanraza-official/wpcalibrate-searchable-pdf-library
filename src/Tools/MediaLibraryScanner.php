<?php
/**
 * Media Library PDF scanner and importer for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Tools;

use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexingQueue;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexStatus;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;

/**
 * Class MediaLibraryScanner
 *
 * Scans existing WordPress Media Library for unmanaged PDFs and imports them in batches.
 */
class MediaLibraryScanner {

	private IndexingQueue $queue;

	/**
	 * Constructor.
	 *
	 * @param IndexingQueue $queue Indexing queue.
	 */
	public function __construct( IndexingQueue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Count unmanaged PDF attachments in WordPress Media Library.
	 *
	 * @return int
	 */
	public function count_unimported_pdfs(): int {
		global $wpdb;

		$sql = "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type = 'application/pdf'
				AND p.post_status != 'trash'
				AND p.ID NOT IN (
					SELECT meta_value FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					AND meta_value != ''
				)";

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, DocumentPostType::META_ATTACHMENT_ID ) );
	}

	/**
	 * Scan and import a batch of Media Library PDF attachments into managed document records.
	 *
	 * @param int  $batch_size Number of PDFs to import in this run.
	 * @param bool $queue_for_indexing Whether to immediately queue imported documents.
	 * @return array{imported_count: int, remaining_count: int}
	 */
	public function import_batch( int $batch_size = 20, bool $queue_for_indexing = true ): array {
		global $wpdb;

		$sql = "SELECT p.ID, p.post_title, p.post_excerpt, p.post_content, p.post_date
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type = 'application/pdf'
				AND p.post_status != 'trash'
				AND p.ID NOT IN (
					SELECT meta_value FROM {$wpdb->postmeta}
					WHERE meta_key = %s
					AND meta_value != ''
				)
				ORDER BY p.ID DESC
				LIMIT %d";

		$attachments = $wpdb->get_results(
			$wpdb->prepare( $sql, DocumentPostType::META_ATTACHMENT_ID, $batch_size ),
			ARRAY_A
		);

		$imported_count = 0;

		if ( ! empty( $attachments ) ) {
			foreach ( $attachments as $attachment ) {
				$att_id = (int) $attachment['ID'];
				$title  = (string) $attachment['post_title'];
				$desc   = (string) ( $attachment['post_content'] ?: $attachment['post_excerpt'] );
				$date   = (string) $attachment['post_date'];
				$year   = (int) gmdate( 'Y', strtotime( $date ) ?: time() );

				$doc = new DocumentModel();
				$doc->set_title( $title ?: basename( (string) get_attached_file( $att_id ) ) );
				$doc->set_attachment_id( $att_id );
				$doc->set_source_type( 'media_library' );
				$doc->set_description( $desc );
				$doc->set_publication_date( gmdate( 'Y-m-d', strtotime( $date ) ?: time() ) );
				$doc->set_publication_year( $year );
				$doc->set_access_mode( 'public' );
				$doc->set_indexing_status( IndexStatus::NOT_INDEXED );

				$new_id = $doc->save();

				if ( $new_id > 0 ) {
					$imported_count++;
					if ( $queue_for_indexing ) {
						$this->queue->queue_document( $new_id );
					}
				}
			}
		}

		$remaining_count = $this->count_unimported_pdfs();
		Logger::info( "Imported {$imported_count} PDFs from Media Library. Remaining: {$remaining_count}" );

		return [
			'imported_count'  => $imported_count,
			'remaining_count' => $remaining_count,
		];
	}
}
