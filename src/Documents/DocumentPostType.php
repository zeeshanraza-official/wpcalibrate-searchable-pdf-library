<?php
/**
 * Custom post type registration for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Documents;

use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;

/**
 * Class DocumentPostType
 *
 * Registers the non-public wpcal_pdf_document custom post type.
 */
class DocumentPostType {

	public const POST_TYPE = 'wpcal_pdf_document';

	/**
	 * Meta keys constants.
	 */
	public const META_ATTACHMENT_ID     = '_wpcal_source_attachment_id';
	public const META_SOURCE_TYPE       = '_wpcal_source_type';
	public const META_DESCRIPTION       = '_wpcal_description';
	public const META_AUTHOR            = '_wpcal_author';
	public const META_PUB_DATE          = '_wpcal_publication_date';
	public const META_PUB_YEAR          = '_wpcal_publication_year';
	public const META_DOC_NUMBER        = '_wpcal_document_number';
	public const META_ACCESS_MODE       = '_wpcal_access_mode';
	public const META_ALLOWED_ROLES     = '_wpcal_allowed_roles';
	public const META_INDEXING_STATUS   = '_wpcal_indexing_status';
	public const META_EXTRACTION_STATUS = '_wpcal_extraction_status';
	public const META_TEXT_HASH         = '_wpcal_text_hash';
	public const META_FILE_HASH         = '_wpcal_file_hash';
	public const META_INDEXED_AT        = '_wpcal_indexed_timestamp';
	public const META_FAILURE_REASON    = '_wpcal_failure_reason';
	public const META_PAGE_COUNT        = '_wpcal_page_count';
	public const META_SCANNED_WARNING   = '_wpcal_scanned_warning';
	public const META_PROTECTED_PATH    = '_wpcal_protected_file_path';

	/**
	 * Custom field manager.
	 *
	 * @var CustomFieldManager
	 */
	private CustomFieldManager $custom_field_manager;

	/**
	 * Constructor.
	 *
	 * @param CustomFieldManager $custom_field_manager Custom field manager.
	 */
	public function __construct( CustomFieldManager $custom_field_manager ) {
		$this->custom_field_manager = $custom_field_manager;
	}

	/**
	 * Register the custom post type and its meta fields.
	 *
	 * @return void
	 */
	public function register(): void {
		$labels = [
			'name'                  => _x( 'PDF Documents', 'Post type general name', 'wpcalibrate-searchable-pdf-library' ),
			'singular_name'         => _x( 'PDF Document', 'Post type singular name', 'wpcalibrate-searchable-pdf-library' ),
			'menu_name'             => _x( 'PDF Documents', 'Admin Menu text', 'wpcalibrate-searchable-pdf-library' ),
			'name_admin_bar'        => _x( 'PDF Document', 'Add New on Toolbar', 'wpcalibrate-searchable-pdf-library' ),
			'add_new'               => __( 'Add New', 'wpcalibrate-searchable-pdf-library' ),
			'add_new_item'          => __( 'Add New PDF Document', 'wpcalibrate-searchable-pdf-library' ),
			'new_item'              => __( 'New PDF Document', 'wpcalibrate-searchable-pdf-library' ),
			'edit_item'             => __( 'Edit PDF Document', 'wpcalibrate-searchable-pdf-library' ),
			'view_item'             => __( 'View PDF Document', 'wpcalibrate-searchable-pdf-library' ),
			'all_items'             => __( 'All PDF Documents', 'wpcalibrate-searchable-pdf-library' ),
			'search_items'          => __( 'Search PDF Documents', 'wpcalibrate-searchable-pdf-library' ),
			'parent_item_colon'     => __( 'Parent PDF Documents:', 'wpcalibrate-searchable-pdf-library' ),
			'not_found'             => __( 'No PDF documents found.', 'wpcalibrate-searchable-pdf-library' ),
			'not_found_in_trash'    => __( 'No PDF documents found in Trash.', 'wpcalibrate-searchable-pdf-library' ),
		];

		$args = [
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => false, // Managed via WPCalibrate admin tabs
			'query_var'          => false,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => [ 'title' ],
			'show_in_rest'       => false, // Internal CPT, no unrestricted REST exposure
		];

		register_post_type( self::POST_TYPE, $args );
	}
}
