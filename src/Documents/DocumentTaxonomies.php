<?php
/**
 * Taxonomy registration for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Documents;

/**
 * Class DocumentTaxonomies
 *
 * Registers wpcal_pdf_category and wpcal_pdf_tag taxonomies.
 */
class DocumentTaxonomies {

	public const TAXONOMY_CATEGORY = 'wpcal_pdf_category';
	public const TAXONOMY_TAG      = 'wpcal_pdf_tag';

	/**
	 * Register taxonomies.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->register_categories();
		$this->register_tags();
	}

	/**
	 * Register category taxonomy.
	 *
	 * @return void
	 */
	private function register_categories(): void {
		$labels = [
			'name'              => _x( 'PDF Categories', 'taxonomy general name', 'wpcalibrate-searchable-pdf-library' ),
			'singular_name'     => _x( 'PDF Category', 'taxonomy singular name', 'wpcalibrate-searchable-pdf-library' ),
			'search_items'      => __( 'Search PDF Categories', 'wpcalibrate-searchable-pdf-library' ),
			'all_items'         => __( 'All PDF Categories', 'wpcalibrate-searchable-pdf-library' ),
			'parent_item'       => __( 'Parent PDF Category', 'wpcalibrate-searchable-pdf-library' ),
			'parent_item_colon' => __( 'Parent PDF Category:', 'wpcalibrate-searchable-pdf-library' ),
			'edit_item'         => __( 'Edit PDF Category', 'wpcalibrate-searchable-pdf-library' ),
			'update_item'       => __( 'Update PDF Category', 'wpcalibrate-searchable-pdf-library' ),
			'add_new_item'      => __( 'Add New PDF Category', 'wpcalibrate-searchable-pdf-library' ),
			'new_item_name'     => __( 'New PDF Category Name', 'wpcalibrate-searchable-pdf-library' ),
			'menu_name'         => __( 'Categories', 'wpcalibrate-searchable-pdf-library' ),
		];

		$args = [
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => false,
			'publicly_queryable'=> false,
			'public'            => false,
			'show_in_nav_menus' => false,
			'show_in_rest'      => true,
			'rewrite'           => false,
		];

		register_taxonomy( self::TAXONOMY_CATEGORY, [ DocumentPostType::POST_TYPE ], $args );
	}

	/**
	 * Register tag taxonomy.
	 *
	 * @return void
	 */
	private function register_tags(): void {
		$labels = [
			'name'          => _x( 'PDF Tags', 'taxonomy general name', 'wpcalibrate-searchable-pdf-library' ),
			'singular_name' => _x( 'PDF Tag', 'taxonomy singular name', 'wpcalibrate-searchable-pdf-library' ),
			'search_items'  => __( 'Search PDF Tags', 'wpcalibrate-searchable-pdf-library' ),
			'all_items'     => __( 'All PDF Tags', 'wpcalibrate-searchable-pdf-library' ),
			'edit_item'     => __( 'Edit PDF Tag', 'wpcalibrate-searchable-pdf-library' ),
			'update_item'   => __( 'Update PDF Tag', 'wpcalibrate-searchable-pdf-library' ),
			'add_new_item'  => __( 'Add New PDF Tag', 'wpcalibrate-searchable-pdf-library' ),
			'new_item_name' => __( 'New PDF Tag Name', 'wpcalibrate-searchable-pdf-library' ),
			'menu_name'     => __( 'Tags', 'wpcalibrate-searchable-pdf-library' ),
		];

		$args = [
			'hierarchical'      => false,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => false,
			'publicly_queryable'=> false,
			'public'            => false,
			'show_in_nav_menus' => false,
			'show_in_rest'      => true,
			'rewrite'           => false,
		];

		register_taxonomy( self::TAXONOMY_TAG, [ DocumentPostType::POST_TYPE ], $args );
	}
}
