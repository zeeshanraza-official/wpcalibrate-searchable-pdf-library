<?php
/**
 * Admin assets manager for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Admin;

use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;

/**
 * Class Assets
 *
 * Enqueues CSS and JS selectively only on this plugin's admin screens.
 */
class Assets {

	/**
	 * Hook admin asset enqueues.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
	}

	/**
	 * Enqueue assets on plugin screens.
	 *
	 * @param string $hook_suffix Admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		global $post_type;

		$is_plugin_page = str_contains( $hook_suffix, MenuManager::SUBMENU_PAGE_SLUG )
			|| ( DocumentPostType::POST_TYPE === $post_type );

		if ( ! $is_plugin_page ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'wpcal-spl-admin',
			WPCAL_SPL_URL . 'assets/admin/css/admin.css',
			[],
			WPCAL_SPL_VERSION
		);

		wp_enqueue_script(
			'wpcal-spl-admin',
			WPCAL_SPL_URL . 'assets/admin/js/admin.js',
			[ 'jquery' ],
			WPCAL_SPL_VERSION,
			true
		);

		wp_localize_script(
			'wpcal-spl-admin',
			'wpcalSplAdmin',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wpcal_spl_admin_nonce' ),
				'i18n'    => [
					'selectPdf'   => esc_html__( 'Select PDF Document', 'wpcalibrate-searchable-pdf-library' ),
					'choosePdf'   => esc_html__( 'Use Selected PDF', 'wpcalibrate-searchable-pdf-library' ),
					'confirmPurge'=> esc_html__( 'Are you sure you want to purge stale index rows?', 'wpcalibrate-searchable-pdf-library' ),
					'confirmRebuild' => esc_html__( 'Are you sure you want to rebuild the entire search index? This operation will re-queue all documents.', 'wpcalibrate-searchable-pdf-library' ),
				],
			]
		);
	}
}
