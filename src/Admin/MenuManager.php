<?php
/**
 * Admin menu manager for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Admin;

/**
 * Class MenuManager
 *
 * Handles creation or reuse of the shared WPCalibrate top-level menu
 * and registers the Searchable PDF Library submenu.
 */
class MenuManager {

	/**
	 * Top-level menu slug for WPCalibrate umbrella menu.
	 */
	public const PARENT_MENU_SLUG = 'wpcalibrate-menu';

	/**
	 * Plugin submenu page slug.
	 */
	public const SUBMENU_PAGE_SLUG = 'wpcal-pdf-library';

	/**
	 * Hook admin menu.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		// Register menu with priority 10 to ensure parent detection works smoothly.
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ], 10 );
		add_action( 'admin_head', [ $this, 'output_menu_icon_styles' ] );
	}

	/**
	 * Output global admin head styles ensuring WPCalibrate admin menu icon matches standard WP 20px size.
	 *
	 * @return void
	 */
	public function output_menu_icon_styles(): void {
		?>
		<style id="wpcal-spl-menu-icon-css">
			#adminmenu #toplevel_page_wpcalibrate-menu .wp-menu-image,
			#adminmenu .toplevel_page_wpcalibrate-menu .wp-menu-image,
			#adminmenu #toplevel_page_wpcalibrate .wp-menu-image,
			#adminmenu .toplevel_page_wpcalibrate .wp-menu-image {
				background-size: 20px 20px !important;
				background-repeat: no-repeat !important;
				background-position: center 7px !important;
			}
			#adminmenu #toplevel_page_wpcalibrate-menu .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate-menu .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate .wp-menu-image img {
				width: 20px !important;
				height: 20px !important;
				max-width: 20px !important;
				max-height: 20px !important;
				padding: 7px 0 0 !important;
				box-sizing: content-box !important;
				opacity: 0.7 !important;
				transition: opacity 0.15s ease-in-out !important;
			}
			#adminmenu #toplevel_page_wpcalibrate-menu:hover .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate-menu:hover .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate:hover .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate:hover .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate-menu.wp-has-current-submenu .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate-menu.wp-has-current-submenu .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate-menu.current .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate-menu.current .wp-menu-image img,
			#adminmenu #toplevel_page_wpcalibrate.wp-has-current-submenu .wp-menu-image img,
			#adminmenu .toplevel_page_wpcalibrate.current .wp-menu-image img {
				opacity: 1 !important;
			}
		</style>
		<?php
	}

	/**
	 * Get the brand icon URL for admin menu / UI.
	 *
	 * @param string $variant 'menu', 'white', or 'dark'.
	 * @return string URL or fallback dashicon.
	 */
	public static function get_brand_icon_url( string $variant = 'white' ): string {
		if ( 'menu' === $variant ) {
			$svg_file = WPCAL_SPL_PATH . 'branding/icon-menu.svg';
			if ( file_exists( $svg_file ) ) {
				$svg_content = (string) file_get_contents( $svg_file );
				if ( ! empty( $svg_content ) ) {
					return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
				}
			}

			$menu_file = WPCAL_SPL_PATH . 'branding/icon-menu.png';
			if ( file_exists( $menu_file ) ) {
				return WPCAL_SPL_URL . 'branding/icon-menu.png';
			}
		}

		$file_name = ( 'dark' === $variant ) ? 'icon-dark.png' : 'icon-white.png';
		$file_path = WPCAL_SPL_PATH . 'branding/' . $file_name;

		if ( file_exists( $file_path ) ) {
			return WPCAL_SPL_URL . 'branding/' . $file_name;
		}

		return 'dashicons-media-document';
	}

	/**
	 * Register WPCalibrate parent menu (if not existing) and this plugin's submenu.
	 *
	 * @return void
	 */
	public function register_admin_menu(): void {
		global $menu;

		$parent_slug    = self::PARENT_MENU_SLUG;
		$parent_exists  = false;
		$icon_url       = self::get_brand_icon_url( 'menu' );

		// Check if any WPCalibrate top-level menu has already been created.
		if ( is_array( $menu ) ) {
			foreach ( $menu as $item ) {
				if ( isset( $item[2] ) && ( $item[2] === $parent_slug || $item[2] === 'wpcalibrate' ) ) {
					$parent_slug   = $item[2];
					$parent_exists = true;
					break;
				}
			}
		}

		if ( ! $parent_exists ) {
			add_menu_page(
				'WPCalibrate',
				'WPCalibrate',
				'manage_options',
				$parent_slug,
				[ $this, 'render_main_redirect' ],
				$icon_url,
				58.6
			);

			// Submenu for parent itself to point to Searchable PDF Library.
			add_submenu_page(
				$parent_slug,
				__( 'Searchable PDF Library', 'wpcalibrate-searchable-pdf-library' ),
				__( 'Searchable PDF Library', 'wpcalibrate-searchable-pdf-library' ),
				'manage_options',
				self::SUBMENU_PAGE_SLUG,
				[ $this, 'render_plugin_page' ]
			);

			// Remove duplicate top-level submenu entry if needed.
			remove_submenu_page( $parent_slug, $parent_slug );
		} else {
			// Parent exists, register our isolated submenu item.
			add_submenu_page(
				$parent_slug,
				__( 'Searchable PDF Library', 'wpcalibrate-searchable-pdf-library' ),
				__( 'Searchable PDF Library', 'wpcalibrate-searchable-pdf-library' ),
				'manage_options',
				self::SUBMENU_PAGE_SLUG,
				[ $this, 'render_plugin_page' ]
			);
		}
	}

	/**
	 * Redirect parent slug directly to this plugin's submenu.
	 *
	 * @return void
	 */
	public function render_main_redirect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized user.', 'wpcalibrate-searchable-pdf-library' ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SUBMENU_PAGE_SLUG ) );
		exit;
	}

	/**
	 * Render plugin administration page.
	 *
	 * @return void
	 */
	public function render_plugin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		// Delegate rendering to SettingsPage service.
		\WPCalibrate\SearchablePdfLibrary\Plugin::get_instance()
			->get_container()
			->get( SettingsPage::class )
			->render();
	}
}
