<?php
/**
 * Managed document model for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Documents;

/**
 * Class DocumentModel
 *
 * Encapsulates a managed PDF document entity and its metadata.
 */
class DocumentModel {

	private int $id;
	private string $title = '';
	private int $attachment_id = 0;
	private string $source_type = 'media_library'; // 'media_library' or 'direct_upload'
	private string $description = '';
	private string $author = '';
	private string $publication_date = '';
	private int $publication_year = 0;
	private string $document_number = '';
	private string $access_mode = 'public'; // 'public', 'logged_in', 'roles'
	private array $allowed_roles = [];
	private string $indexing_status = 'not_indexed'; // 'not_indexed', 'queued', 'processing', 'indexed', 'needs_attention', 'failed'
	private string $extraction_status = 'pending';
	private string $text_hash = '';
	private string $file_hash = '';
	private string $indexed_at = '';
	private string $failure_reason = '';
	private int $page_count = 0;
	private bool $scanned_warning = false;
	private string $protected_file_path = '';

	/**
	 * Constructor.
	 *
	 * @param int $post_id Post ID.
	 */
	public function __construct( int $post_id = 0 ) {
		$this->id = $post_id;
		if ( $post_id > 0 ) {
			$this->load();
		}
	}

	/**
	 * Load document data from WordPress post and postmeta.
	 *
	 * @return void
	 */
	public function load(): void {
		$post = get_post( $this->id );
		if ( ! $post || DocumentPostType::POST_TYPE !== $post->post_type ) {
			return;
		}

		$this->title               = (string) $post->post_title;
		$this->attachment_id       = (int) get_post_meta( $this->id, DocumentPostType::META_ATTACHMENT_ID, true );
		$this->source_type         = (string) get_post_meta( $this->id, DocumentPostType::META_SOURCE_TYPE, true ) ?: 'media_library';
		$this->description         = (string) get_post_meta( $this->id, DocumentPostType::META_DESCRIPTION, true );
		$this->author              = (string) get_post_meta( $this->id, DocumentPostType::META_AUTHOR, true );
		$this->publication_date    = (string) get_post_meta( $this->id, DocumentPostType::META_PUB_DATE, true );
		$this->publication_year    = (int) get_post_meta( $this->id, DocumentPostType::META_PUB_YEAR, true );
		$this->document_number     = (string) get_post_meta( $this->id, DocumentPostType::META_DOC_NUMBER, true );
		$this->access_mode         = (string) get_post_meta( $this->id, DocumentPostType::META_ACCESS_MODE, true ) ?: 'public';
		$roles                     = get_post_meta( $this->id, DocumentPostType::META_ALLOWED_ROLES, true );
		$this->allowed_roles       = is_array( $roles ) ? $roles : [];
		$this->indexing_status     = (string) get_post_meta( $this->id, DocumentPostType::META_INDEXING_STATUS, true ) ?: 'not_indexed';
		$this->extraction_status   = (string) get_post_meta( $this->id, DocumentPostType::META_EXTRACTION_STATUS, true ) ?: 'pending';
		$this->text_hash           = (string) get_post_meta( $this->id, DocumentPostType::META_TEXT_HASH, true );
		$this->file_hash           = (string) get_post_meta( $this->id, DocumentPostType::META_FILE_HASH, true );
		$this->indexed_at          = (string) get_post_meta( $this->id, DocumentPostType::META_INDEXED_AT, true );
		$this->failure_reason      = (string) get_post_meta( $this->id, DocumentPostType::META_FAILURE_REASON, true );
		$this->page_count          = (int) get_post_meta( $this->id, DocumentPostType::META_PAGE_COUNT, true );
		$this->scanned_warning     = (bool) get_post_meta( $this->id, DocumentPostType::META_SCANNED_WARNING, true );
		$this->protected_file_path = (string) get_post_meta( $this->id, DocumentPostType::META_PROTECTED_PATH, true );
	}

	/**
	 * Save document data to database.
	 *
	 * When the document exists ($this->id > 0), only the postmeta is updated by default.
	 * To explicitly update the wp_posts row (title, status, etc.), set $update_post to true.
	 *
	 * @param bool $update_post Whether to update the WP_Post record if ID exists. Default false.
	 * @return int Post ID.
	 */
	public function save( bool $update_post = false ): int {
		if ( $this->id <= 0 ) {
			$post_data = [
				'post_title'  => $this->title,
				'post_type'   => DocumentPostType::POST_TYPE,
				'post_status' => 'publish',
			];
			$this->id = wp_insert_post( $post_data );
		} elseif ( $update_post ) {
			$post_data = [
				'ID'         => $this->id,
				'post_title' => $this->title,
			];
			wp_update_post( $post_data );
		}

		$this->save_meta();

		return $this->id;
	}

	/**
	 * Save all document metadata directly to postmeta without updating the wp_posts record.
	 *
	 * This prevents infinite loops inside WordPress save_post hooks.
	 *
	 * @return void
	 */
	public function save_meta(): void {
		if ( $this->id <= 0 ) {
			return;
		}

		update_post_meta( $this->id, DocumentPostType::META_ATTACHMENT_ID, $this->attachment_id );
		update_post_meta( $this->id, DocumentPostType::META_SOURCE_TYPE, $this->source_type );
		update_post_meta( $this->id, DocumentPostType::META_DESCRIPTION, $this->description );
		update_post_meta( $this->id, DocumentPostType::META_AUTHOR, $this->author );
		update_post_meta( $this->id, DocumentPostType::META_PUB_DATE, $this->publication_date );
		update_post_meta( $this->id, DocumentPostType::META_PUB_YEAR, $this->publication_year );
		update_post_meta( $this->id, DocumentPostType::META_DOC_NUMBER, $this->document_number );
		update_post_meta( $this->id, DocumentPostType::META_ACCESS_MODE, $this->access_mode );
		update_post_meta( $this->id, DocumentPostType::META_ALLOWED_ROLES, $this->allowed_roles );
		update_post_meta( $this->id, DocumentPostType::META_INDEXING_STATUS, $this->indexing_status );
		update_post_meta( $this->id, DocumentPostType::META_EXTRACTION_STATUS, $this->extraction_status );
		update_post_meta( $this->id, DocumentPostType::META_TEXT_HASH, $this->text_hash );
		update_post_meta( $this->id, DocumentPostType::META_FILE_HASH, $this->file_hash );
		update_post_meta( $this->id, DocumentPostType::META_INDEXED_AT, $this->indexed_at );
		update_post_meta( $this->id, DocumentPostType::META_FAILURE_REASON, $this->failure_reason );
		update_post_meta( $this->id, DocumentPostType::META_PAGE_COUNT, $this->page_count );
		update_post_meta( $this->id, DocumentPostType::META_SCANNED_WARNING, $this->scanned_warning ? 1 : 0 );
		update_post_meta( $this->id, DocumentPostType::META_PROTECTED_PATH, $this->protected_file_path );
	}

	// Getters and Setters
	public function get_id(): int { return $this->id; }
	public function get_title(): string { return $this->title; }
	public function set_title( string $title ): self { $this->title = $title; return $this; }
	public function get_attachment_id(): int { return $this->attachment_id; }
	public function set_attachment_id( int $id ): self { $this->attachment_id = $id; return $this; }
	public function get_source_type(): string { return $this->source_type; }
	public function set_source_type( string $type ): self { $this->source_type = $type; return $this; }
	public function get_description(): string { return $this->description; }
	public function set_description( string $desc ): self { $this->description = $desc; return $this; }
	public function get_author(): string { return $this->author; }
	public function set_author( string $author ): self { $this->author = $author; return $this; }
	public function get_publication_date(): string { return $this->publication_date; }
	public function set_publication_date( string $date ): self { $this->publication_date = $date; return $this; }
	public function get_publication_year(): int { return $this->publication_year; }
	public function set_publication_year( int $year ): self { $this->publication_year = $year; return $this; }
	public function get_document_number(): string { return $this->document_number; }
	public function set_document_number( string $num ): self { $this->document_number = $num; return $this; }
	public function get_access_mode(): string { return $this->access_mode; }
	public function set_access_mode( string $mode ): self { $this->access_mode = $mode; return $this; }
	public function get_allowed_roles(): array { return $this->allowed_roles; }
	public function set_allowed_roles( array $roles ): self { $this->allowed_roles = $roles; return $this; }
	public function get_indexing_status(): string { return $this->indexing_status; }
	public function set_indexing_status( string $status ): self { $this->indexing_status = $status; return $this; }
	public function get_extraction_status(): string { return $this->extraction_status; }
	public function set_extraction_status( string $status ): self { $this->extraction_status = $status; return $this; }
	public function get_text_hash(): string { return $this->text_hash; }
	public function set_text_hash( string $hash ): self { $this->text_hash = $hash; return $this; }
	public function get_file_hash(): string { return $this->file_hash; }
	public function set_file_hash( string $hash ): self { $this->file_hash = $hash; return $this; }
	public function get_indexed_at(): string { return $this->indexed_at; }
	public function set_indexed_at( string $at ): self { $this->indexed_at = $at; return $this; }
	public function get_failure_reason(): string { return $this->failure_reason; }
	public function set_failure_reason( string $reason ): self { $this->failure_reason = $reason; return $this; }
	public function get_page_count(): int { return $this->page_count; }
	public function set_page_count( int $count ): self { $this->page_count = $count; return $this; }
	public function is_scanned_warning(): bool { return $this->scanned_warning; }
	public function set_scanned_warning( bool $warn ): self { $this->scanned_warning = $warn; return $this; }
	public function get_protected_file_path(): string { return $this->protected_file_path; }
	public function set_protected_file_path( string $path ): self { $this->protected_file_path = $path; return $this; }

	/**
	 * Get custom field value.
	 *
	 * @param string $field_key Custom field key.
	 * @return mixed
	 */
	public function get_custom_field( string $field_key ): mixed {
		if ( ! $this->id ) {
			return null;
		}
		$meta_key = '_wpcal_cf_' . sanitize_key( $field_key );
		return get_post_meta( $this->id, $meta_key, true );
	}

	/**
	 * Set custom field value.
	 *
	 * @param string $field_key Custom field key.
	 * @param mixed  $value Value.
	 * @return self
	 */
	public function set_custom_field( string $field_key, mixed $value ): self {
		if ( $this->id > 0 ) {
			$meta_key = '_wpcal_cf_' . sanitize_key( $field_key );
			update_post_meta( $this->id, $meta_key, $value );
		}
		return $this;
	}

	/**
	 * Get category terms for this document.
	 *
	 * @return array<\WP_Term>
	 */
	public function get_categories(): array {
		if ( ! $this->id ) {
			return [];
		}
		$terms = wp_get_post_terms( $this->id, DocumentTaxonomies::TAXONOMY_CATEGORY );
		return is_array( $terms ) ? $terms : [];
	}

	/**
	 * Get tag terms for this document.
	 *
	 * @return array<\WP_Term>
	 */
	public function get_tags(): array {
		if ( ! $this->id ) {
			return [];
		}
		$terms = wp_get_post_terms( $this->id, DocumentTaxonomies::TAXONOMY_TAG );
		return is_array( $terms ) ? $terms : [];
	}

	/**
	 * Resolve local file path to the PDF.
	 *
	 * @return string|false
	 */
	public function get_file_path(): string|false {
		if ( ! empty( $this->protected_file_path ) ) {
			$upload_dir = wp_upload_dir();
			$protected_file = trailingslashit( $upload_dir['basedir'] ) . 'wpcal_spl_protected/' . basename( $this->protected_file_path );
			if ( file_exists( $protected_file ) ) {
				return $protected_file;
			}
		}

		if ( $this->attachment_id > 0 ) {
			$path = get_attached_file( $this->attachment_id );
			if ( $path && file_exists( $path ) ) {
				return $path;
			}
		}

		return false;
	}

	/**
	 * Get download / access URL for the document.
	 *
	 * @param bool $is_inline Whether to request inline viewing.
	 * @return string
	 */
	public function get_access_url( bool $is_inline = false ): string {
		// If restricted, return authorized delivery endpoint.
		if ( 'public' !== $this->access_mode || ! empty( $this->protected_file_path ) ) {
			return add_query_arg(
				[
					'wpcal_spl_action' => $is_inline ? 'view' : 'download',
					'doc_id'           => $this->id,
				],
				home_url( '/' )
			);
		}

		// For public documents with direct attachment.
		if ( $this->attachment_id > 0 ) {
			$url = wp_get_attachment_url( $this->attachment_id );
			if ( $url ) {
				return $url;
			}
		}

		return '';
	}
}
