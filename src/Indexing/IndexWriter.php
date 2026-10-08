<?php
/**
 * Search index writer for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Indexing;

use WPCalibrate\SearchablePdfLibrary\Database\SchemaManager;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Extraction\ExtractionResult;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;

/**
 * Class IndexWriter
 *
 * Persists extracted PDF text into bounded database chunk rows with page references.
 */
class IndexWriter {

	/**
	 * Max characters per text chunk row.
	 */
	public const MAX_CHUNK_LENGTH = 2500;

	/**
	 * Schema manager.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Constructor.
	 *
	 * @param SchemaManager $schema_manager Schema manager.
	 */
	public function __construct( SchemaManager $schema_manager ) {
		$this->schema_manager = $schema_manager;
	}

	/**
	 * Write extraction output and document metadata into the search index.
	 *
	 * @param DocumentModel    $document Document model.
	 * @param ExtractionResult $result Extraction result.
	 * @param array<string, mixed> $custom_field_definitions Active custom field definitions.
	 * @return bool True on success.
	 */
	public function write_index(
		DocumentModel $document,
		ExtractionResult $result,
		array $custom_field_definitions = []
	): bool {
		global $wpdb;

		$table_name = $this->schema_manager->get_index_table_name();
		$doc_id     = $document->get_id();

		if ( $doc_id <= 0 ) {
			return false;
		}

		$now = current_time( 'mysql' );

		// If extraction failed, record failure on document and clear old index rows.
		if ( ! $result->is_success() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->delete( $table_name, [ 'document_id' => $doc_id ], [ '%d' ] );

			$document->set_indexing_status( IndexStatus::FAILED );
			$document->set_failure_reason( $result->get_error_message() );
			$document->set_indexed_at( $now );
			$document->save_meta();

			Logger::warning( "Index write failed for document #{$doc_id}: " . $result->get_error_message() );
			return false;
		}

		// Compile searchable metadata text.
		$metadata_parts = [];
		if ( ! empty( $document->get_author() ) ) {
			$metadata_parts[] = 'Author: ' . $document->get_author();
		}
		if ( ! empty( $document->get_document_number() ) ) {
			$metadata_parts[] = 'Doc#: ' . $document->get_document_number();
		}
		if ( ! empty( $document->get_publication_year() ) ) {
			$metadata_parts[] = 'Year: ' . $document->get_publication_year();
		}
		if ( ! empty( $document->get_description() ) ) {
			$metadata_parts[] = 'Description: ' . $document->get_description();
		}

		// Categories & Tags
		$cats = wp_list_pluck( $document->get_categories(), 'name' );
		if ( ! empty( $cats ) ) {
			$metadata_parts[] = 'Categories: ' . implode( ', ', $cats );
		}
		$tags = wp_list_pluck( $document->get_tags(), 'name' );
		if ( ! empty( $tags ) ) {
			$metadata_parts[] = 'Tags: ' . implode( ', ', $tags );
		}

		// Searchable custom fields
		foreach ( $custom_field_definitions as $field_key => $field_def ) {
			if ( ! empty( $field_def['searchable'] ) ) {
				$val = $document->get_custom_field( (string) $field_key );
				if ( ! empty( $val ) ) {
					$label = $field_def['label'] ?? $field_key;
					$metadata_parts[] = "{$label}: " . ( is_array( $val ) ? implode( ', ', $val ) : (string) $val );
				}
			}
		}

		$compiled_metadata = implode( "\n", $metadata_parts );
		$title             = $document->get_title();
		$attachment_id     = $document->get_attachment_id();

		// Clean up existing index rows for this document.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table_name, [ 'document_id' => $doc_id ], [ '%d' ] );

		$pages_text = $result->get_pages_text();
		$chunk_idx  = 0;

		// If no pages were extracted (e.g. scanned/image-only), create at least one row containing title & metadata.
		if ( empty( $pages_text ) || $result->is_scanned_or_no_text() ) {
			$first_content = ! empty( $pages_text ) ? reset( $pages_text ) : '';
			$content_hash  = hash( 'sha256', $title . $compiled_metadata . $first_content );

			$wpdb->insert(
				$table_name,
				[
					'document_id'   => $doc_id,
					'attachment_id' => $attachment_id ?: null,
					'page_number'   => 1,
					'chunk_number'  => 0,
					'title_text'    => $title,
					'metadata_text' => $compiled_metadata,
					'body_text'     => $first_content,
					'content_hash'  => $content_hash,
					'indexed_at'    => $now,
					'created_at'    => $now,
					'updated_at'    => $now,
				],
				[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
			);
		} else {
			// Iterate through pages and bounded chunks
			foreach ( $pages_text as $page_num => $page_content ) {
				$chunks = self::split_into_chunks( (string) $page_content, self::MAX_CHUNK_LENGTH );
				foreach ( $chunks as $chunk_body ) {
					$content_hash = hash( 'sha256', $title . $chunk_body . ( 0 === $chunk_idx ? $compiled_metadata : '' ) );

					$wpdb->insert(
						$table_name,
						[
							'document_id'   => $doc_id,
							'attachment_id' => $attachment_id ?: null,
							'page_number'   => (int) $page_num,
							'chunk_number'  => $chunk_idx,
							'title_text'    => $title,
							'metadata_text' => ( 0 === $chunk_idx ) ? $compiled_metadata : null,
							'body_text'     => $chunk_body,
							'content_hash'  => $content_hash,
							'indexed_at'    => $now,
							'created_at'    => $now,
							'updated_at'    => $now,
						],
						[ '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
					);

					$chunk_idx++;
				}
			}
		}

		// Update document entity with final indexing state
		$document->set_indexing_status( IndexStatus::INDEXED );
		$document->set_scanned_warning( false );
		$document->set_page_count( $result->get_page_count() );
		$document->set_text_hash( $result->get_text_hash() );
		$document->set_file_hash( $result->get_file_hash() );
		$document->set_indexed_at( $now );
		$document->set_failure_reason( '' );
		$document->save_meta();

		Logger::info( "Document #{$doc_id} successfully indexed with {$chunk_idx} chunk rows." );
		return true;
	}

	/**
	 * Remove a document completely from the index.
	 *
	 * @param int $document_id Document ID.
	 * @return bool
	 */
	public function delete_index( int $document_id ): bool {
		global $wpdb;
		$table_name = $this->schema_manager->get_index_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->delete( $table_name, [ 'document_id' => $document_id ], [ '%d' ] );
		return true;
	}

	/**
	 * Split long page text into bounded chunks without breaking words mid-string.
	 *
	 * @param string $text Full page text.
	 * @param int    $max_length Max chunk character count.
	 * @return array<string>
	 */
	public static function split_into_chunks( string $text, int $max_length ): array {
		$text = trim( $text );
		if ( empty( $text ) ) {
			return [ '' ];
		}

		if ( mb_strlen( $text ) <= $max_length ) {
			return [ $text ];
		}

		$chunks = [];
		while ( mb_strlen( $text ) > $max_length ) {
			// Find suitable breakpoint (paragraph or space) before max_length
			$breakpoint = mb_strrpos( mb_substr( $text, 0, $max_length ), "\n\n" );
			if ( false === $breakpoint || $breakpoint < ( $max_length / 2 ) ) {
				$breakpoint = mb_strrpos( mb_substr( $text, 0, $max_length ), ' ' );
			}
			if ( false === $breakpoint || 0 === $breakpoint ) {
				$breakpoint = $max_length;
			}

			$chunk    = trim( mb_substr( $text, 0, $breakpoint ) );
			$chunks[] = $chunk;
			$text     = trim( mb_substr( $text, $breakpoint ) );
		}

		if ( ! empty( $text ) ) {
			$chunks[] = $text;
		}

		return $chunks;
	}
}
