<?php
/**
 * Unified frontend document library renderer for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Frontend;

use WPCalibrate\SearchablePdfLibrary\Access\DocumentAccessService;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentTaxonomies;
use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;
use WPCalibrate\SearchablePdfLibrary\Search\SearchEngineInterface;
use WPCalibrate\SearchablePdfLibrary\Search\SearchResult;

/**
 * Class FrontendRenderer
 *
 * Shared renderer for Shortcode, Gutenberg Block, and templates.
 */
class FrontendRenderer {

	private SearchEngineInterface $search_engine;
	private CustomFieldManager $custom_field_manager;
	private DocumentAccessService $access_service;

	/**
	 * Constructor.
	 *
	 * @param SearchEngineInterface $search_engine Search engine.
	 * @param CustomFieldManager    $custom_field_manager Custom fields.
	 * @param DocumentAccessService $access_service Access service.
	 */
	public function __construct(
		SearchEngineInterface $search_engine,
		CustomFieldManager $custom_field_manager,
		DocumentAccessService $access_service
	) {
		$this->search_engine        = $search_engine;
		$this->custom_field_manager = $custom_field_manager;
		$this->access_service       = $access_service;
	}

	/**
	 * Register frontend scripts and styles.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style(
			'wpcal-spl-frontend',
			WPCAL_SPL_URL . 'assets/public/css/frontend.css',
			[],
			WPCAL_SPL_VERSION
		);

		wp_register_script(
			'wpcal-spl-frontend',
			WPCAL_SPL_URL . 'assets/public/js/frontend.js',
			[],
			WPCAL_SPL_VERSION,
			true
		);

		wp_localize_script(
			'wpcal-spl-frontend',
			'wpcalSplData',
			[
				'restUrl' => esc_url_raw( rest_url( 'wpcalibrate-pdf-library/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => [
					'loading'    => esc_html__( 'Searching document library...', 'wpcalibrate-searchable-pdf-library' ),
					'resultsMsg' => esc_html__( 'documents found', 'wpcalibrate-searchable-pdf-library' ),
					'noResults'  => esc_html__( 'No documents found matching your search criteria.', 'wpcalibrate-searchable-pdf-library' ),
					'closeModal' => esc_html__( 'Close PDF viewer', 'wpcalibrate-searchable-pdf-library' ),
				],
			]
		);
	}

	/**
	 * Render the document library interface.
	 *
	 * @param array<string, mixed> $attributes Attributes from shortcode or block.
	 * @return string HTML output.
	 */
	public function render( array $attributes = [] ): string {
		wp_enqueue_style( 'wpcal-spl-frontend' );
		wp_enqueue_script( 'wpcal-spl-frontend' );

		$settings = get_option( 'wpcal_spl_settings', [] );

		// Merge attributes with default settings
		$layout       = in_array( $attributes['layout'] ?? '', [ 'grid', 'list' ], true ) ? $attributes['layout'] : ( $settings['default_layout'] ?? 'grid' );
		$per_page     = ! empty( $attributes['per_page'] ) ? (int) $attributes['per_page'] : (int) ( $settings['per_page'] ?? 12 );
		$show_search  = isset( $attributes['show_search'] ) ? (bool) $attributes['show_search'] : (bool) ( $settings['show_search'] ?? true );
		$show_filters = isset( $attributes['show_filters'] ) ? (bool) $attributes['show_filters'] : (bool) ( $settings['show_filters'] ?? true );
		$init_cat     = sanitize_text_field( (string) ( $attributes['category'] ?? '' ) );
		$init_tag     = sanitize_text_field( (string) ( $attributes['tag'] ?? '' ) );

		// Get current request params for progressive non-JS form support
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$query_str = isset( $_GET['wpcal_q'] ) ? sanitize_text_field( (string) $_GET['wpcal_q'] ) : '';
		$cat_slug  = isset( $_GET['wpcal_cat'] ) ? sanitize_text_field( (string) $_GET['wpcal_cat'] ) : $init_cat;
		$tag_slug  = isset( $_GET['wpcal_tag'] ) ? sanitize_text_field( (string) $_GET['wpcal_tag'] ) : $init_tag;
		$sort_by   = isset( $_GET['wpcal_sort'] ) ? sanitize_key( (string) $_GET['wpcal_sort'] ) : ( $settings['default_sort'] ?? 'relevance' );
		$page_num  = isset( $_GET['wpcal_paged'] ) ? max( 1, (int) $_GET['wpcal_paged'] ) : 1;
		// phpcs:enable

		// Query search engine
		$search_params = [
			'query'    => $query_str,
			'category' => $cat_slug,
			'tag'      => $tag_slug,
			'sort'     => $sort_by,
			'page'     => $page_num,
			'per_page' => $per_page,
		];

		$search_result = $this->search_engine->search( $search_params );

		ob_start();
		?>
		<div class="wpcal-spl-container" data-layout="<?php echo esc_attr( $layout ); ?>" data-per-page="<?php echo esc_attr( (string) $per_page ); ?>">
			<!-- Filter & Search Controls Header -->
			<form method="GET" class="wpcal-spl-controls" role="search" aria-label="<?php esc_attr_e( 'PDF Document Search', 'wpcalibrate-searchable-pdf-library' ); ?>">
				<?php if ( $show_search ) : ?>
					<div class="wpcal-spl-search-bar">
						<label for="wpcal-search-input" class="screen-reader-text"><?php esc_html_e( 'Search documents by title, content, or metadata', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						<div class="wpcal-spl-input-wrap">
							<span class="wpcal-search-icon" aria-hidden="true">
								<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
							</span>
							<input
								type="search"
								id="wpcal-search-input"
								name="wpcal_q"
								class="wpcal-spl-search-input"
								placeholder="<?php esc_attr_e( 'Search PDF text, title, author, topics...', 'wpcalibrate-searchable-pdf-library' ); ?>"
								value="<?php echo esc_attr( $query_str ); ?>"
								autocomplete="off"
							/>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( $show_filters ) : ?>
					<div class="wpcal-spl-filter-row">
						<!-- Category Filter -->
						<?php
						$categories = get_terms( [ 'taxonomy' => DocumentTaxonomies::TAXONOMY_CATEGORY, 'hide_empty' => true ] );
						if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) :
							?>
							<div class="wpcal-filter-group">
								<label for="wpcal-filter-cat" class="screen-reader-text"><?php esc_html_e( 'Filter by Category', 'wpcalibrate-searchable-pdf-library' ); ?></label>
								<select id="wpcal-filter-cat" name="wpcal_cat" class="wpcal-spl-select">
									<option value=""><?php esc_html_e( 'All Categories', 'wpcalibrate-searchable-pdf-library' ); ?></option>
									<?php foreach ( $categories as $cat ) : ?>
										<option value="<?php echo esc_attr( $cat->slug ); ?>" <?php selected( $cat_slug, $cat->slug ); ?>>
											<?php echo esc_html( $cat->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endif; ?>

						<!-- Tag Filter -->
						<?php
						$tags = get_terms( [ 'taxonomy' => DocumentTaxonomies::TAXONOMY_TAG, 'hide_empty' => true ] );
						if ( ! empty( $tags ) && ! is_wp_error( $tags ) ) :
							?>
							<div class="wpcal-filter-group">
								<label for="wpcal-filter-tag" class="screen-reader-text"><?php esc_html_e( 'Filter by Tag', 'wpcalibrate-searchable-pdf-library' ); ?></label>
								<select id="wpcal-filter-tag" name="wpcal_tag" class="wpcal-spl-select">
									<option value=""><?php esc_html_e( 'All Tags', 'wpcalibrate-searchable-pdf-library' ); ?></option>
									<?php foreach ( $tags as $t ) : ?>
										<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $tag_slug, $t->slug ); ?>>
											<?php echo esc_html( $t->name ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</div>
						<?php endif; ?>

						<!-- Sort selector -->
						<div class="wpcal-filter-group">
							<label for="wpcal-sort-select" class="screen-reader-text"><?php esc_html_e( 'Sort documents', 'wpcalibrate-searchable-pdf-library' ); ?></label>
							<select id="wpcal-sort-select" name="wpcal_sort" class="wpcal-spl-select">
								<option value="relevance" <?php selected( $sort_by, 'relevance' ); ?>><?php esc_html_e( 'Relevance', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="title_asc" <?php selected( $sort_by, 'title_asc' ); ?>><?php esc_html_e( 'Title: A-Z', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="title_desc" <?php selected( $sort_by, 'title_desc' ); ?>><?php esc_html_e( 'Title: Z-A', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="newest_pub" <?php selected( $sort_by, 'newest_pub' ); ?>><?php esc_html_e( 'Newest Publication', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="oldest_pub" <?php selected( $sort_by, 'oldest_pub' ); ?>><?php esc_html_e( 'Oldest Publication', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="recent" <?php selected( $sort_by, 'recent' ); ?>><?php esc_html_e( 'Recently Added', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							</select>
						</div>

						<!-- Layout switch buttons -->
						<div class="wpcal-layout-switch" role="group" aria-label="<?php esc_attr_e( 'View Layout', 'wpcalibrate-searchable-pdf-library' ); ?>">
							<button type="button" class="wpcal-layout-btn <?php echo 'grid' === $layout ? 'is-active' : ''; ?>" data-layout="grid" aria-label="<?php esc_attr_e( 'Grid view', 'wpcalibrate-searchable-pdf-library' ); ?>">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
							</button>
							<button type="button" class="wpcal-layout-btn <?php echo 'list' === $layout ? 'is-active' : ''; ?>" data-layout="list" aria-label="<?php esc_attr_e( 'List view', 'wpcalibrate-searchable-pdf-library' ); ?>">
								<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
							</button>
						</div>

						<!-- Reset button -->
						<button type="button" class="wpcal-spl-reset-btn" id="wpcal-reset-filters" style="<?php echo ( ! empty( $query_str ) || ! empty( $cat_slug ) || ! empty( $tag_slug ) ) ? '' : 'display:none;'; ?>">
							<?php esc_html_e( 'Clear Filters', 'wpcalibrate-searchable-pdf-library' ); ?>
						</button>
					</div>
				<?php endif; ?>
			</form>

			<!-- Live Status / ARIA announcer -->
			<div class="wpcal-spl-status-bar" role="status" aria-live="polite">
				<span class="wpcal-spl-count">
					<?php
					printf(
						/* translators: %d: Total found documents */
						esc_html__( '%d documents found', 'wpcalibrate-searchable-pdf-library' ),
						$search_result->total
					);
					?>
				</span>
				<span class="wpcal-spl-spinner" aria-hidden="true" style="display:none;"></span>
			</div>

			<!-- Search Results Container -->
			<div class="wpcal-spl-results-wrapper wpcal-layout-<?php echo esc_attr( $layout ); ?>" id="wpcal-results-grid">
				<?php if ( empty( $search_result->items ) ) : ?>
					<div class="wpcal-spl-empty-state">
						<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
						<p><?php esc_html_e( 'No documents found matching your search criteria.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
				<?php else : ?>
					<?php foreach ( $search_result->items as $item ) : ?>
						<?php echo $this->render_item_card( $item, $settings ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<!-- Pagination Controls -->
			<?php if ( $search_result->total_pages > 1 ) : ?>
				<nav class="wpcal-spl-pagination" aria-label="<?php esc_attr_e( 'Document library pages', 'wpcalibrate-searchable-pdf-library' ); ?>">
					<?php if ( $search_result->page > 1 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'wpcal_paged', $search_result->page - 1 ) ); ?>" class="wpcal-page-link wpcal-page-prev" data-page="<?php echo esc_attr( (string) ( $search_result->page - 1 ) ); ?>">
							&laquo; <?php esc_html_e( 'Previous', 'wpcalibrate-searchable-pdf-library' ); ?>
						</a>
					<?php endif; ?>

					<span class="wpcal-page-current">
						<?php
						printf(
							/* translators: 1: Current page, 2: Total pages */
							esc_html__( 'Page %1$d of %2$d', 'wpcalibrate-searchable-pdf-library' ),
							$search_result->page,
							$search_result->total_pages
						);
						?>
					</span>

					<?php if ( $search_result->page < $search_result->total_pages ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'wpcal_paged', $search_result->page + 1 ) ); ?>" class="wpcal-page-link wpcal-page-next" data-page="<?php echo esc_attr( (string) ( $search_result->page + 1 ) ); ?>">
							<?php esc_html_e( 'Next', 'wpcalibrate-searchable-pdf-library' ); ?> &raquo;
						</a>
					<?php endif; ?>
				</nav>
			<?php endif; ?>

			<!-- PDF Embedded Viewer Modal -->
			<div id="wpcal-pdf-modal" class="wpcal-modal" role="dialog" aria-modal="true" aria-labelledby="wpcal-modal-title" style="display:none;">
				<div class="wpcal-modal-backdrop" data-close="modal"></div>
				<div class="wpcal-modal-dialog">
					<header class="wpcal-modal-header">
						<h3 id="wpcal-modal-title" class="wpcal-modal-title"></h3>
						<button type="button" class="wpcal-modal-close" data-close="modal" aria-label="<?php esc_attr_e( 'Close PDF viewer', 'wpcalibrate-searchable-pdf-library' ); ?>">&times;</button>
					</header>
					<div class="wpcal-modal-body">
						<iframe id="wpcal-modal-iframe" src="" title="<?php esc_attr_e( 'PDF Document Preview', 'wpcalibrate-searchable-pdf-library' ); ?>" class="wpcal-modal-iframe"></iframe>
					</div>
					<footer class="wpcal-modal-footer">
						<a id="wpcal-modal-download" href="" class="wpcal-btn wpcal-btn-secondary" download>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
							<?php esc_html_e( 'Download PDF', 'wpcalibrate-searchable-pdf-library' ); ?>
						</a>
						<button type="button" class="wpcal-btn wpcal-btn-outline" data-close="modal"><?php esc_html_e( 'Close', 'wpcalibrate-searchable-pdf-library' ); ?></button>
					</footer>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a single document item card/row.
	 *
	 * @param \WPCalibrate\SearchablePdfLibrary\Search\SearchItem $item Search item.
	 * @param array<string, mixed> $settings Settings array.
	 * @return string HTML card.
	 */
	public function render_item_card( \WPCalibrate\SearchablePdfLibrary\Search\SearchItem $item, array $settings ): string {
		$show_view     = $settings['show_view_button'] ?? true;
		$show_download = $settings['show_download_button'] ?? true;
		$view_behavior = $settings['viewer_behavior'] ?? 'modal'; // 'modal', 'new_tab', 'direct'

		ob_start();
		?>
		<article class="wpcal-doc-card" data-doc-id="<?php echo esc_attr( (string) $item->document_id ); ?>">
			<div class="wpcal-card-icon" aria-hidden="true">
				<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="1.8">
					<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
					<polyline points="14 2 14 8 20 8"/>
					<line x1="9" y1="13" x2="15" y2="13"/>
					<line x1="9" y1="17" x2="13" y2="17"/>
				</svg>
				<span class="wpcal-card-badge">PDF</span>
			</div>

			<div class="wpcal-card-content">
				<h4 class="wpcal-doc-title">
					<?php if ( ! empty( $item->view_url ) ) : ?>
						<a href="<?php echo esc_url( $item->view_url ); ?>" class="wpcal-view-trigger" data-title="<?php echo esc_attr( $item->title ); ?>" data-behavior="<?php echo esc_attr( $view_behavior ); ?>" <?php echo 'new_tab' === $view_behavior ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
							<?php echo esc_html( $item->title ); ?>
						</a>
					<?php else : ?>
						<?php echo esc_html( $item->title ); ?>
					<?php endif; ?>
				</h4>

				<!-- Meta details -->
				<div class="wpcal-doc-meta">
					<?php if ( ! empty( $item->author ) ) : ?>
						<span class="wpcal-meta-item wpcal-meta-author">
							<span class="wpcal-meta-label"><?php esc_html_e( 'Author:', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php echo esc_html( $item->author ); ?>
						</span>
					<?php endif; ?>

					<?php if ( ! empty( $item->publication_year ) || ! empty( $item->publication_date ) ) : ?>
						<span class="wpcal-meta-item wpcal-meta-date">
							<span class="wpcal-meta-label"><?php esc_html_e( 'Published:', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php echo esc_html( (string) ( $item->publication_year ?: $item->publication_date ) ); ?>
						</span>
					<?php endif; ?>

					<?php if ( ! empty( $item->document_number ) ) : ?>
						<span class="wpcal-meta-item wpcal-meta-docnum">
							<span class="wpcal-meta-label"><?php esc_html_e( 'Doc #:', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php echo esc_html( $item->document_number ); ?>
						</span>
					<?php endif; ?>
				</div>

				<!-- Categories & Tags -->
				<?php if ( ! empty( $item->categories ) || ! empty( $item->tags ) ) : ?>
					<div class="wpcal-doc-terms">
						<?php foreach ( $item->categories as $c ) : ?>
							<span class="wpcal-term-pill wpcal-pill-category"><?php echo esc_html( $c['name'] ); ?></span>
						<?php endforeach; ?>
						<?php foreach ( $item->tags as $t ) : ?>
							<span class="wpcal-term-pill wpcal-pill-tag">#<?php echo esc_html( $t['name'] ); ?></span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<!-- Matched text snippet with safe mark highlighting -->
				<?php if ( ! empty( $item->highlighted_snippet ) ) : ?>
					<div class="wpcal-doc-snippet">
						<blockquote class="wpcal-snippet-text">
							&ldquo;<?php echo $item->highlighted_snippet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>&rdquo;
						</blockquote>
						<?php if ( $item->matched_page > 0 ) : ?>
							<span class="wpcal-page-badge">
								<?php
								printf(
									/* translators: %d: Page number where match was found */
									esc_html__( 'Page %d', 'wpcalibrate-searchable-pdf-library' ),
									$item->matched_page
								);
								?>
							</span>
						<?php endif; ?>
					</div>
				<?php elseif ( ! empty( $item->description ) ) : ?>
					<p class="wpcal-doc-description"><?php echo esc_html( $item->description ); ?></p>
				<?php endif; ?>

				<!-- Custom fields configured for results -->
				<?php if ( ! empty( $item->custom_fields ) ) : ?>
					<div class="wpcal-doc-custom-fields">
						<?php foreach ( $item->custom_fields as $cf ) : ?>
							<span class="wpcal-cf-item">
								<strong><?php echo esc_html( $cf['label'] ); ?>:</strong>
								<?php echo esc_html( is_array( $cf['value'] ) ? implode( ', ', $cf['value'] ) : (string) $cf['value'] ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Action buttons -->
			<div class="wpcal-card-actions">
				<?php if ( $show_view && ! empty( $item->view_url ) ) : ?>
					<a
						href="<?php echo esc_url( $item->view_url ); ?>"
						class="wpcal-btn wpcal-btn-primary wpcal-view-trigger"
						data-title="<?php echo esc_attr( $item->title ); ?>"
						data-behavior="<?php echo esc_attr( $view_behavior ); ?>"
						<?php echo 'new_tab' === $view_behavior ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>
					>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
						<?php esc_html_e( 'View', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
				<?php endif; ?>

				<?php if ( $show_download && ! empty( $item->download_url ) ) : ?>
					<a href="<?php echo esc_url( $item->download_url ); ?>" class="wpcal-btn wpcal-btn-secondary" download>
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
						<?php esc_html_e( 'Download', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return (string) ob_get_clean();
	}
}
