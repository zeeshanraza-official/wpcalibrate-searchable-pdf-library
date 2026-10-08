<?php
/**
 * Plugin Name:       WPCalibrate Searchable PDF Library
 * Plugin URI:        https://wpcalibrate.com
 * Description:       Full-text searchable PDF document library for WordPress with local text extraction and access controls.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Tested up to:      7.1.3
 * Requires PHP:      8.2
 * Author:            WPCalibrate
 * Author URI:        https://wpcalibrate.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpcalibrate-searchable-pdf-library
 * Domain Path:       /languages
 * Update URI:        https://github.com/zeeshanraza-official/wpcalibrate-searchable-pdf-library/
 * GitHub Plugin URI: zeeshanraza-official/wpcalibrate-searchable-pdf-library
 * Primary Branch:    main
 * Release Asset:     true
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

// Prevent direct script execution.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'WPCAL_SPL_VERSION', '1.0.0' );
define( 'WPCAL_SPL_FILE', __FILE__ );
define( 'WPCAL_SPL_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCAL_SPL_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCAL_SPL_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Check PHP compatibility.
 */
if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'WPCalibrate Searchable PDF Library:', 'wpcalibrate-searchable-pdf-library' ),
				sprintf(
					/* translators: %s: Minimum PHP version required */
					esc_html__( 'requires PHP %s or higher. Your server is currently running PHP %s.', 'wpcalibrate-searchable-pdf-library' ),
					'8.2.0',
					esc_html( PHP_VERSION )
				)
			);
		}
	);
	return;
}

// Load Composer autoloader.
$wpcal_spl_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $wpcal_spl_autoloader ) ) {
	require_once $wpcal_spl_autoloader;
} else {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'WPCalibrate Searchable PDF Library:', 'wpcalibrate-searchable-pdf-library' ),
				esc_html__( 'Composer dependencies are missing. Please run "composer install".', 'wpcalibrate-searchable-pdf-library' )
			);
		}
	);
	return;
}

// Register activation and deactivation hooks.
register_activation_hook( __FILE__, [ \WPCalibrate\SearchablePdfLibrary\Plugin::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \WPCalibrate\SearchablePdfLibrary\Plugin::class, 'deactivate' ] );

// Initialize plugin.
add_action(
	'plugins_loaded',
	static function (): void {
		\WPCalibrate\SearchablePdfLibrary\Plugin::get_instance()->boot();
	}
);
