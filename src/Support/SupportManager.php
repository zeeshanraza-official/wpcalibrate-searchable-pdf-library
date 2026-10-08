<?php
/**
 * Support and official contact details for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\Support;

namespace WPCalibrate\SearchablePdfLibrary\Support;

/**
 * Class SupportManager
 */
class SupportManager {

	public const EMAIL       = 'support@wpcalibrate.com';
	public const WEBSITE     = 'https://wpcalibrate.com/';
	public const MARKETPLACE = 'https://marketplace.wpcalibrate.com/';
	public const WHATSAPP    = '+447474795976';

	/**
	 * Get formatted support information array.
	 *
	 * @return array<string, string>
	 */
	public static function get_support_info(): array {
		return [
			'email'       => self::EMAIL,
			'website'     => self::WEBSITE,
			'marketplace' => self::MARKETPLACE,
			'whatsapp'    => self::WHATSAPP,
		];
	}
}
