<?php
/**
 * Gutenberg block registration for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Frontend;

/**
 * Class BlockHandler
 *
 * Registers the dynamic server-rendered Gutenberg block.
 */
class BlockHandler {

	public const BLOCK_NAME = 'wpcalibrate/searchable-pdf-library';

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
	 * Register Gutenberg block.
	 *
	 * @return void
	 */
	public function register(): void {
		$block_dir = WPCAL_SPL_PATH . 'blocks/searchable-pdf-library';

		if ( file_exists( $block_dir . '/block.json' ) ) {
			register_block_type_from_metadata(
				$block_dir,
				[
					'render_callback' => [ $this, 'render_block' ],
				]
			);
		}
	}

	/**
	 * Render callback for server-side block rendering.
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @param string               $content Block content.
	 * @return string HTML output.
	 */
	public function render_block( array $attributes = [], string $content = '' ): string {
		$cleaned = [
			'category'     => sanitize_text_field( (string) ( $attributes['category'] ?? '' ) ),
			'tag'          => sanitize_text_field( (string) ( $attributes['tag'] ?? '' ) ),
			'layout'       => in_array( $attributes['layout'] ?? '', [ 'grid', 'list' ], true ) ? $attributes['layout'] : 'grid',
			'per_page'     => max( 1, min( 100, (int) ( $attributes['per_page'] ?? 12 ) ) ),
			'show_filters' => isset( $attributes['show_filters'] ) ? (bool) $attributes['show_filters'] : true,
			'show_search'  => isset( $attributes['show_search'] ) ? (bool) $attributes['show_search'] : true,
		];

		return $this->renderer->render( $cleaned );
	}
}
