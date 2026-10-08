<?php
/**
 * License provider interface for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Licensing;

/**
 * Interface LicenseProviderInterface
 *
 * Clean contract for future external WPCalibrate licensing integration.
 */
interface LicenseProviderInterface {

	/**
	 * Get current license status ('unconfigured', 'active', 'invalid', 'expired').
	 *
	 * @return string
	 */
	public function get_status(): string;

	/**
	 * Get masked license key for display.
	 *
	 * @return string
	 */
	public function get_masked_key(): string;

	/**
	 * Get license expiration date if applicable.
	 *
	 * @return string
	 */
	public function get_expiry_date(): string;

	/**
	 * Get renewal URL if available.
	 *
	 * @return string
	 */
	public function get_renewal_url(): string;

	/**
	 * Check if provider is configured and operational.
	 *
	 * @return bool
	 */
	public function is_configured(): bool;

	/**
	 * Save license key.
	 *
	 * @param string $key License key.
	 * @return bool|\WP_Error
	 */
	public function save_key( string $key ): bool|\WP_Error;

	/**
	 * Activate license.
	 *
	 * @return bool|\WP_Error
	 */
	public function activate(): bool|\WP_Error;

	/**
	 * Deactivate license.
	 *
	 * @return bool|\WP_Error
	 */
	public function deactivate(): bool|\WP_Error;
}
