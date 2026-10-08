<?php
/**
 * Index status constants and labels for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Indexing;

/**
 * Class IndexStatus
 */
class IndexStatus {

	public const NOT_INDEXED      = 'not_indexed';
	public const QUEUED           = 'queued';
	public const PROCESSING       = 'processing';
	public const INDEXED          = 'indexed';
	public const NEEDS_ATTENTION  = 'needs_attention';
	public const FAILED           = 'failed';

	/**
	 * Get human readable status label.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function get_label( string $status ): string {
		return match ( $status ) {
			self::NOT_INDEXED     => __( 'Not Indexed', 'wpcalibrate-searchable-pdf-library' ),
			self::QUEUED          => __( 'Queued', 'wpcalibrate-searchable-pdf-library' ),
			self::PROCESSING      => __( 'Processing', 'wpcalibrate-searchable-pdf-library' ),
			self::INDEXED         => __( 'Indexed', 'wpcalibrate-searchable-pdf-library' ),
			self::NEEDS_ATTENTION => __( 'Indexed', 'wpcalibrate-searchable-pdf-library' ),
			self::FAILED          => __( 'Failed', 'wpcalibrate-searchable-pdf-library' ),
			default               => ucfirst( str_replace( '_', ' ', $status ) ),
		};
	}

	/**
	 * Get CSS badge class for status.
	 *
	 * @param string $status Status key.
	 * @return string
	 */
	public static function get_badge_class( string $status ): string {
		return match ( $status ) {
			self::INDEXED, self::NEEDS_ATTENTION => 'wpcal-badge-success',
			self::QUEUED                         => 'wpcal-badge-info',
			self::PROCESSING                     => 'wpcal-badge-warning',
			self::FAILED                         => 'wpcal-badge-danger',
			default                              => 'wpcal-badge-neutral',
		};
	}
}
