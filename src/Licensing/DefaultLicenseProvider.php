<?php
/**
 * Default licensing provider implementation for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Licensing;

/**
 * Class DefaultLicenseProvider
 *
 * Provides a clean unconfigured licensing state without simulated validation.
 */
class DefaultLicenseProvider implements LicenseProviderInterface {

	public const OPTION_KEY = 'wpcal_spl_license_data';

	/**
	 * Get license status.
	 *
	 * @return string
	 */
	public function get_status(): string {
		return 'unconfigured';
	}

	/**
	 * Get masked license key.
	 *
	 * @return string
	 */
	public function get_masked_key(): string {
		$data = get_option( self::OPTION_KEY, [] );
		$key  = (string) ( $data['key'] ?? '' );
		if ( empty( $key ) ) {
			return '';
		}

		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '•', $len );
		}

		return substr( $key, 0, 4 ) . str_repeat( '•', $len - 8 ) . substr( $key, -4 );
	}

	/**
	 * Expiry date.
	 *
	 * @return string
	 */
	public function get_expiry_date(): string {
		return '';
	}

	/**
	 * Renewal URL.
	 *
	 * @return string
	 */
	public function get_renewal_url(): string {
		return 'https://marketplace.wpcalibrate.com/';
	}

	/**
	 * Check if configured.
	 *
	 * @return bool
	 */
	public function is_configured(): bool {
		return false;
	}

	/**
	 * Save license key.
	 *
	 * @param string $key License key.
	 * @return bool|\WP_Error
	 */
	public function save_key( string $key ): bool|\WP_Error {
		$sanitized_key = sanitize_text_field( trim( $key ) );
		update_option(
			self::OPTION_KEY,
			[
				'key'        => $sanitized_key,
				'status'     => 'unconfigured',
				'updated_at' => current_time( 'mysql' ),
			]
		);
		return true;
	}

	/**
	 * Attempt activation.
	 *
	 * @return bool|\WP_Error
	 */
	public function activate(): bool|\WP_Error {
		return new \WP_Error(
			'licensing_unconfigured',
			__( 'Licensing server integration is not yet configured for this installation. Please contact WPCalibrate support.', 'wpcalibrate-searchable-pdf-library' )
		);
	}

	/**
	 * Attempt deactivation.
	 *
	 * @return bool|\WP_Error
	 */
	public function deactivate(): bool|\WP_Error {
		delete_option( self::OPTION_KEY );
		return true;
	}
}
