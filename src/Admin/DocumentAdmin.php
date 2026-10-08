<?php
/**
 * Document editor and metaboxes manager for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Admin;

use WPCalibrate\SearchablePdfLibrary\Access\DocumentAccessService;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexingQueue;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexStatus;
use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;

/**
 * Class DocumentAdmin
 */
class DocumentAdmin {

	public const NONCE_ACTION = 'wpcal_spl_save_document_action';
	public const NONCE_FIELD  = 'wpcal_spl_document_nonce';

	private CustomFieldManager $custom_field_manager;
	private IndexingQueue $queue;
	private DocumentAccessService $access_service;

	/**
	 * Guard against recursive save_post re-entrancy.
	 *
	 * @var bool
	 */
	private static bool $is_saving = false;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldManager    $custom_field_manager Custom fields.
	 * @param IndexingQueue         $queue Queue.
	 * @param DocumentAccessService $access_service Access service.
	 */
	public function __construct(
		CustomFieldManager $custom_field_manager,
		IndexingQueue $queue,
		DocumentAccessService $access_service
	) {
		$this->custom_field_manager = $custom_field_manager;
		$this->queue                = $queue;
		$this->access_service       = $access_service;
	}

	/**
	 * Hook admin document events.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'add_meta_boxes', [ $this, 'register_meta_boxes' ] );
		add_action( 'save_post_' . DocumentPostType::POST_TYPE, [ $this, 'save_document_meta' ], 10, 2 );

		// Custom admin columns
		add_filter( 'manage_' . DocumentPostType::POST_TYPE . '_posts_columns', [ $this, 'register_admin_columns' ] );
		add_action( 'manage_' . DocumentPostType::POST_TYPE . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );

		// AJAX reindex action
		add_action( 'wp_ajax_wpcal_spl_reindex_single', [ $this, 'ajax_reindex_single' ] );
	}

	/**
	 * Register metaboxes for document post type.
	 *
	 * @return void
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'wpcal_spl_source_metabox',
			__( 'PDF Source Document', 'wpcalibrate-searchable-pdf-library' ),
			[ $this, 'render_source_metabox' ],
			DocumentPostType::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wpcal_spl_meta_metabox',
			__( 'Document Metadata & Attributes', 'wpcalibrate-searchable-pdf-library' ),
			[ $this, 'render_meta_metabox' ],
			DocumentPostType::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpcal_spl_access_metabox',
			__( 'Access Control & Permissions', 'wpcalibrate-searchable-pdf-library' ),
			[ $this, 'render_access_metabox' ],
			DocumentPostType::POST_TYPE,
			'side',
			'default'
		);

		add_meta_box(
			'wpcal_spl_status_metabox',
			__( 'Indexing & OCR Diagnostics', 'wpcalibrate-searchable-pdf-library' ),
			[ $this, 'render_status_metabox' ],
			DocumentPostType::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render PDF source file selection metabox.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_source_metabox( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$document = new DocumentModel( $post->ID );
		$att_id   = $document->get_attachment_id();
		$filename = '';
		$filesize = '';

		if ( $att_id > 0 ) {
			$file_path = get_attached_file( $att_id );
			if ( $file_path && file_exists( $file_path ) ) {
				$filename = basename( $file_path );
				$filesize = size_format( (int) filesize( $file_path ) );
			}
		}
		?>
		<div class="wpcal-admin-field">
			<label class="wpcal-admin-label"><?php esc_html_e( 'Selected PDF Attachment:', 'wpcalibrate-searchable-pdf-library' ); ?></label>
			<input type="hidden" name="wpcal_attachment_id" id="wpcal_attachment_id" value="<?php echo esc_attr( (string) $att_id ); ?>">
			
			<div id="wpcal-attachment-preview" class="wpcal-attachment-preview" style="<?php echo $att_id ? '' : 'display:none;'; ?>">
				<div class="wpcal-preview-file">
					<span class="dashicons dashicons-pdf" style="font-size:32px;height:32px;width:32px;color:#e11d48;"></span>
					<div>
						<strong id="wpcal-preview-name"><?php echo esc_html( $filename ); ?></strong>
						<div id="wpcal-preview-size" class="description"><?php echo esc_html( $filesize ); ?></div>
					</div>
				</div>
				<button type="button" class="button" id="wpcal-remove-attachment"><?php esc_html_e( 'Change / Remove', 'wpcalibrate-searchable-pdf-library' ); ?></button>
			</div>

			<div id="wpcal-select-controls" style="<?php echo $att_id ? 'display:none;' : ''; ?>">
				<button type="button" class="button button-primary" id="wpcal-open-media-modal">
					<?php esc_html_e( 'Select PDF from Media Library / Upload', 'wpcalibrate-searchable-pdf-library' ); ?>
				</button>
				<p class="description">
					<?php esc_html_e( 'Choose an existing PDF document or upload a new one. Full-text content will be indexed in background.', 'wpcalibrate-searchable-pdf-library' ); ?>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render metadata metabox.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_meta_metabox( \WP_Post $post ): void {
		$document = new DocumentModel( $post->ID );
		$custom_fields = $this->custom_field_manager->get_fields();
		?>
		<div class="wpcal-admin-grid">
			<div class="wpcal-admin-field">
				<label for="wpcal_author"><?php esc_html_e( 'Author / Organization', 'wpcalibrate-searchable-pdf-library' ); ?></label>
				<input type="text" id="wpcal_author" name="wpcal_author" class="widefat" value="<?php echo esc_attr( $document->get_author() ); ?>">
			</div>

			<div class="wpcal-admin-field">
				<label for="wpcal_document_number"><?php esc_html_e( 'Document Number / Code', 'wpcalibrate-searchable-pdf-library' ); ?></label>
				<input type="text" id="wpcal_document_number" name="wpcal_document_number" class="widefat" value="<?php echo esc_attr( $document->get_document_number() ); ?>">
			</div>

			<div class="wpcal-admin-field">
				<label for="wpcal_publication_date"><?php esc_html_e( 'Publication Date', 'wpcalibrate-searchable-pdf-library' ); ?></label>
				<input type="date" id="wpcal_publication_date" name="wpcal_publication_date" class="widefat" value="<?php echo esc_attr( $document->get_publication_date() ); ?>">
			</div>

			<div class="wpcal-admin-field">
				<label for="wpcal_publication_year"><?php esc_html_e( 'Publication Year', 'wpcalibrate-searchable-pdf-library' ); ?></label>
				<input type="number" id="wpcal_publication_year" name="wpcal_publication_year" class="widefat" min="1900" max="2100" value="<?php echo esc_attr( (string) ( $document->get_publication_year() ?: '' ) ); ?>">
			</div>
		</div>

		<div class="wpcal-admin-field" style="margin-top:12px;">
			<label for="wpcal_description"><?php esc_html_e( 'Document Summary / Abstract', 'wpcalibrate-searchable-pdf-library' ); ?></label>
			<textarea id="wpcal_description" name="wpcal_description" class="widefat" rows="3"><?php echo esc_textarea( $document->get_description() ); ?></textarea>
		</div>

		<?php if ( ! empty( $custom_fields ) ) : ?>
			<hr style="margin:16px 0;border-top:1px solid #e2e8f0;">
			<h4><?php esc_html_e( 'Custom Metadata Fields', 'wpcalibrate-searchable-pdf-library' ); ?></h4>
			<div class="wpcal-admin-grid">
				<?php foreach ( $custom_fields as $key => $field ) : ?>
					<?php
					$val = $document->get_custom_field( (string) $key );
					$id  = 'wpcal_cf_' . esc_attr( $key );
					?>
					<div class="wpcal-admin-field">
						<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
						<?php if ( 'select' === $field['type'] ) : ?>
							<select id="<?php echo esc_attr( $id ); ?>" name="wpcal_cf[<?php echo esc_attr( $key ); ?>]" class="widefat">
								<option value=""><?php esc_html_e( '-- Select --', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<?php foreach ( (array) ( $field['options'] ?? [] ) as $opt ) : ?>
									<option value="<?php echo esc_attr( $opt ); ?>" <?php selected( (string) $val, $opt ); ?>><?php echo esc_html( $opt ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php elseif ( 'checkbox' === $field['type'] ) : ?>
							<label>
								<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="wpcal_cf[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( (bool) $val ); ?>>
								<?php echo esc_html( $field['description'] ?: $field['label'] ); ?>
							</label>
						<?php elseif ( 'date' === $field['type'] ) : ?>
							<input type="date" id="<?php echo esc_attr( $id ); ?>" name="wpcal_cf[<?php echo esc_attr( $key ); ?>]" class="widefat" value="<?php echo esc_attr( (string) $val ); ?>">
						<?php elseif ( 'number' === $field['type'] ) : ?>
							<input type="number" id="<?php echo esc_attr( $id ); ?>" name="wpcal_cf[<?php echo esc_attr( $key ); ?>]" class="widefat" value="<?php echo esc_attr( (string) $val ); ?>">
						<?php else : ?>
							<input type="text" id="<?php echo esc_attr( $id ); ?>" name="wpcal_cf[<?php echo esc_attr( $key ); ?>]" class="widefat" value="<?php echo esc_attr( (string) $val ); ?>">
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render access control metabox.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_access_metabox( \WP_Post $post ): void {
		$document      = new DocumentModel( $post->ID );
		$mode          = $document->get_access_mode();
		$allowed_roles = $document->get_allowed_roles();
		$all_roles     = $this->access_service->get_available_roles();
		?>
		<div class="wpcal-admin-field">
			<label>
				<input type="radio" name="wpcal_access_mode" value="public" <?php checked( $mode, 'public' ); ?>>
				<strong><?php esc_html_e( 'Public Access', 'wpcalibrate-searchable-pdf-library' ); ?></strong>
			</label>
			<p class="description" style="margin-left:22px;"><?php esc_html_e( 'Anyone can view and search this document.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
		</div>

		<div class="wpcal-admin-field" style="margin-top:10px;">
			<label>
				<input type="radio" name="wpcal_access_mode" value="logged_in" <?php checked( $mode, 'logged_in' ); ?>>
				<strong><?php esc_html_e( 'Logged-in Users Only', 'wpcalibrate-searchable-pdf-library' ); ?></strong>
			</label>
			<p class="description" style="margin-left:22px;"><?php esc_html_e( 'Only authenticated WordPress users can view.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
		</div>

		<div class="wpcal-admin-field" style="margin-top:10px;">
			<label>
				<input type="radio" name="wpcal_access_mode" value="roles" <?php checked( $mode, 'roles' ); ?>>
				<strong><?php esc_html_e( 'Selected Roles Only', 'wpcalibrate-searchable-pdf-library' ); ?></strong>
			</label>
			<div id="wpcal-roles-list" style="margin-left:22px;margin-top:6px;<?php echo 'roles' === $mode ? '' : 'display:none;'; ?>">
				<?php foreach ( $all_roles as $role_key => $role_label ) : ?>
					<label style="display:block;margin-bottom:4px;">
						<input type="checkbox" name="wpcal_allowed_roles[]" value="<?php echo esc_attr( $role_key ); ?>" <?php checked( in_array( $role_key, $allowed_roles, true ) ); ?>>
						<?php echo esc_html( $role_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render status & diagnostics metabox.
	 *
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function render_status_metabox( \WP_Post $post ): void {
		$document = new DocumentModel( $post->ID );
		$status   = $document->get_indexing_status();
		$badge    = IndexStatus::get_badge_class( $status );
		$label    = IndexStatus::get_label( $status );
		?>
		<div class="wpcal-status-row">
			<strong><?php esc_html_e( 'Indexing Status:', 'wpcalibrate-searchable-pdf-library' ); ?></strong>
			<span class="wpcal-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span>
		</div>



		<?php if ( ! empty( $document->get_failure_reason() ) ) : ?>
			<div class="wpcal-notice-error" style="margin:10px 0;padding:8px;background:#fee2e2;border-left:3px solid #ef4444;font-size:12px;color:#991b1b;">
				<strong><?php esc_html_e( 'Failure Reason:', 'wpcalibrate-searchable-pdf-library' ); ?></strong>
				<?php echo esc_html( $document->get_failure_reason() ); ?>
			</div>
		<?php endif; ?>

		<ul style="margin:10px 0;font-size:12px;color:#64748b;">
			<li><?php printf( esc_html__( 'Pages Detected: %d', 'wpcalibrate-searchable-pdf-library' ), $document->get_page_count() ); ?></li>
			<?php if ( ! empty( $document->get_indexed_at() ) ) : ?>
				<li><?php printf( esc_html__( 'Last Indexed: %s', 'wpcalibrate-searchable-pdf-library' ), esc_html( $document->get_indexed_at() ) ); ?></li>
			<?php endif; ?>
		</ul>

		<div style="margin-top:12px;">
			<button type="button" class="button button-secondary" id="wpcal-reindex-btn" data-doc-id="<?php echo esc_attr( (string) $post->ID ); ?>">
				<?php esc_html_e( 'Re-index Text Now', 'wpcalibrate-searchable-pdf-library' ); ?>
			</button>
			<span id="wpcal-reindex-spinner" class="spinner"></span>
		</div>
		<?php
	}

	/**
	 * Save document metadata callback.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post Post object.
	 * @return void
	 */
	public function save_document_meta( int $post_id, \WP_Post $post ): void {
		if ( self::$is_saving ) {
			return;
		}

		// Verify nonce
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::$is_saving = true;

		try {
			$document = new DocumentModel( $post_id );
			$old_att_id = $document->get_attachment_id();
			$new_att_id = isset( $_POST['wpcal_attachment_id'] ) ? absint( $_POST['wpcal_attachment_id'] ) : 0;

			$document->set_title( sanitize_text_field( $post->post_title ) );
			$document->set_attachment_id( $new_att_id );
			$document->set_author( sanitize_text_field( (string) ( $_POST['wpcal_author'] ?? '' ) ) );
			$document->set_document_number( sanitize_text_field( (string) ( $_POST['wpcal_document_number'] ?? '' ) ) );
			$document->set_publication_date( sanitize_text_field( (string) ( $_POST['wpcal_publication_date'] ?? '' ) ) );
			$document->set_publication_year( absint( $_POST['wpcal_publication_year'] ?? 0 ) );
			$document->set_description( sanitize_textarea_field( (string) ( $_POST['wpcal_description'] ?? '' ) ) );

			// Access
			$access_mode = sanitize_key( (string) ( $_POST['wpcal_access_mode'] ?? 'public' ) );
			$document->set_access_mode( in_array( $access_mode, [ 'public', 'logged_in', 'roles' ], true ) ? $access_mode : 'public' );

			$allowed_roles = isset( $_POST['wpcal_allowed_roles'] ) && is_array( $_POST['wpcal_allowed_roles'] )
				? array_map( 'sanitize_key', $_POST['wpcal_allowed_roles'] )
				: [];
			$document->set_allowed_roles( $allowed_roles );

			// Custom fields
			if ( isset( $_POST['wpcal_cf'] ) && is_array( $_POST['wpcal_cf'] ) ) {
				$fields = $this->custom_field_manager->get_fields();
				foreach ( $fields as $fk => $fdef ) {
					$val = $_POST['wpcal_cf'][ $fk ] ?? null;
					$sanitized = $this->custom_field_manager->sanitize_field_value( $fdef, $val );
					$document->set_custom_field( (string) $fk, $sanitized );
				}
			}

			// Pure metadata update (never re-invokes wp_update_post)
			$document->save_meta();

			// Trigger re-indexing only if a valid PDF attachment is present and (attachment changed or document was not yet indexed)
			if ( $new_att_id > 0 && ( $new_att_id !== $old_att_id || IndexStatus::NOT_INDEXED === $document->get_indexing_status() ) ) {
				$this->queue->queue_document( $post_id );
			} elseif ( 0 === $new_att_id && $old_att_id > 0 ) {
				// Attachment removed, mark status as not indexed
				$document->set_indexing_status( IndexStatus::NOT_INDEXED );
				$document->save_meta();
			}
		} finally {
			self::$is_saving = false;
		}
	}

	/**
	 * Register admin list table columns.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function register_admin_columns( array $columns ): array {
		$new_columns = [];
		foreach ( $columns as $k => $v ) {
			$new_columns[ $k ] = $v;
			if ( 'title' === $k ) {
				$new_columns['wpcal_file']   = __( 'PDF File', 'wpcalibrate-searchable-pdf-library' );
				$new_columns['wpcal_status'] = __( 'Indexing Status', 'wpcalibrate-searchable-pdf-library' );
				$new_columns['wpcal_pages']  = __( 'Pages', 'wpcalibrate-searchable-pdf-library' );
				$new_columns['wpcal_access'] = __( 'Access', 'wpcalibrate-searchable-pdf-library' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render column content in admin list.
	 *
	 * @param string $column Column key.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_admin_column( string $column, int $post_id ): void {
		$doc = new DocumentModel( $post_id );

		switch ( $column ) {
			case 'wpcal_file':
				$att_id = $doc->get_attachment_id();
				if ( $att_id > 0 ) {
					$url = wp_get_attachment_url( $att_id );
					echo '<a href="' . esc_url( (string) $url ) . '" target="_blank">' . esc_html( basename( (string) get_attached_file( $att_id ) ) ) . '</a>';
				} else {
					echo '<span style="color:#94a3b8;">' . esc_html__( 'No file attached', 'wpcalibrate-searchable-pdf-library' ) . '</span>';
				}
				break;

			case 'wpcal_status':
				$status = $doc->get_indexing_status();
				$badge  = IndexStatus::get_badge_class( $status );
				$label  = IndexStatus::get_label( $status );
				echo '<span class="wpcal-badge ' . esc_attr( $badge ) . '">' . esc_html( $label ) . '</span>';
				break;

			case 'wpcal_pages':
				echo esc_html( (string) ( $doc->get_page_count() ?: '-' ) );
				break;

			case 'wpcal_access':
				$mode = $doc->get_access_mode();
				if ( 'public' === $mode ) {
					echo '<span style="color:#16a34a;">' . esc_html__( 'Public', 'wpcalibrate-searchable-pdf-library' ) . '</span>';
				} elseif ( 'logged_in' === $mode ) {
					echo '<span style="color:#0284c7;">' . esc_html__( 'Logged-in Only', 'wpcalibrate-searchable-pdf-library' ) . '</span>';
				} else {
					$roles = $doc->get_allowed_roles();
					echo '<span style="color:#7c3aed;">' . esc_html__( 'Roles:', 'wpcalibrate-searchable-pdf-library' ) . ' ' . esc_html( implode( ', ', $roles ) ) . '</span>';
				}
				break;
		}
	}

	/**
	 * AJAX handler to queue single document re-index.
	 *
	 * @return void
	 */
	public function ajax_reindex_single(): void {
		check_ajax_referer( 'wpcal_spl_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized user.', 'wpcalibrate-searchable-pdf-library' ) ], 403 );
		}

		$doc_id = isset( $_POST['doc_id'] ) ? absint( $_POST['doc_id'] ) : 0;
		if ( $doc_id <= 0 ) {
			wp_send_json_error( [ 'message' => __( 'Invalid document ID.', 'wpcalibrate-searchable-pdf-library' ) ], 400 );
		}

		$success = $this->queue->queue_document( $doc_id );
		if ( $success ) {
			wp_send_json_success( [ 'message' => __( 'Document successfully queued for background indexing.', 'wpcalibrate-searchable-pdf-library' ) ] );
		} else {
			wp_send_json_error( [ 'message' => __( 'Could not queue document.', 'wpcalibrate-searchable-pdf-library' ) ], 500 );
		}
	}
}
