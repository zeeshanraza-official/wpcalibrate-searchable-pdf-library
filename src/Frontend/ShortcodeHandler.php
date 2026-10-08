<?php
/**
 * Shortcode handler for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Frontend;

/**
 * Class ShortcodeHandler
 *
 * Registers and handles [wpcalibrate_pdf_library] shortcode.
 */
class ShortcodeHandler {

	public const SHORTCODE_TAG        = 'wpcal_pdf_library';
	public const SHORTCODE_TAG_LEGACY = 'wpcalibrate_pdf_library';

	private FrontendRenderer $renderer;

	/**
	 * Constructor.
	 *
	 * @param FrontendRenderer $renderer Frontend renderer.
	 */
	public function __construct( FrontendRenderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Register shortcode tags with WordPress.
	 *
	 * Supports both [wpcal_pdf_library] (standard) and [wpcalibrate_pdf_library] (legacy alias).
	 *
	 * @return void
	 */
	public function register(): void {
		add_shortcode( self::SHORTCODE_TAG, [ $this, 'render_shortcode' ] );
		add_shortcode( self::SHORTCODE_TAG_LEGACY, [ $this, 'render_shortcode' ] );
	}

	/**
	 * Handle shortcode execution.
	 *
	 * @param array<string, mixed>|string $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_shortcode( array|string $atts = [] ): string {
		$defaults = [
			'category'     => '',
			'tag'          => '',
			'layout'       => 'grid',
			'per_page'     => 12,
			'show_filters' => 'true',
			'show_search'  => 'true',
		];

		$raw_atts = is_array( $atts ) ? $atts : [];
		$parsed_atts = shortcode_atts( $defaults, $raw_atts, self::SHORTCODE_TAG );

		// Strict sanitization and boolean normalization
		$cleaned = [
			'category'     => sanitize_text_field( (string) $parsed_atts['category'] ),
			'tag'          => sanitize_text_field( (string) $parsed_atts['tag'] ),
			'layout'       => in_array( $parsed_atts['layout'], [ 'grid', 'list' ], true ) ? $parsed_atts['layout'] : 'grid',
			'per_page'     => max( 1, min( 100, (int) $parsed_atts['per_page'] ) ),
			'show_filters' => filter_var( $parsed_atts['show_filters'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
			'show_search'  => filter_var( $parsed_atts['show_search'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? true,
		];

		return $this->renderer->render( $cleaned );
	}
}
