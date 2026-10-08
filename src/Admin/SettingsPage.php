<?php
/**
 * Settings and administration interface for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Admin;

use WPCalibrate\SearchablePdfLibrary\Database\SchemaManager;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentRepository;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentTaxonomies;
use WPCalibrate\SearchablePdfLibrary\Extraction\LocalPdfParser;
use WPCalibrate\SearchablePdfLibrary\Files\ProtectedFileDelivery;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexingQueue;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexStatus;
use WPCalibrate\SearchablePdfLibrary\Licensing\LicenseProviderInterface;
use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;
use WPCalibrate\SearchablePdfLibrary\Support\SupportManager;
use WPCalibrate\SearchablePdfLibrary\Tools\MaintenanceTools;
use WPCalibrate\SearchablePdfLibrary\Tools\MediaLibraryScanner;

/**
 * Class SettingsPage
 */
class SettingsPage {

	public const OPTION_SETTINGS = 'wpcal_spl_settings';
	public const NONCE_ACTION    = 'wpcal_spl_admin_settings_action';
	public const NONCE_FIELD     = 'wpcal_spl_settings_nonce';

	private CustomFieldManager $custom_field_manager;
	private SchemaManager $schema_manager;
	private IndexingQueue $queue;
	private LicenseProviderInterface $license_provider;
	private MaintenanceTools $tools;
	private MediaLibraryScanner $scanner;
	private DocumentRepository $repository;

	/**
	 * Constructor.
	 */
	public function __construct(
		CustomFieldManager $custom_field_manager,
		SchemaManager $schema_manager,
		IndexingQueue $queue,
		LicenseProviderInterface $license_provider,
		MaintenanceTools $tools,
		MediaLibraryScanner $scanner
	) {
		$this->custom_field_manager = $custom_field_manager;
		$this->schema_manager       = $schema_manager;
		$this->queue                = $queue;
		$this->license_provider     = $license_provider;
		$this->tools                = $tools;
		$this->scanner              = $scanner;
		$this->repository           = new DocumentRepository();
	}

	/**
	 * Initialize default settings option on activation.
	 *
	 * @return void
	 */
	public static function init_default_settings(): void {
		$defaults = [
			'default_layout'       => 'grid',
			'per_page'             => 12,
			'default_sort'         => 'relevance',
			'show_search'          => true,
			'show_filters'         => true,
			'show_view_button'     => true,
			'show_download_button' => true,
			'viewer_behavior'      => 'modal', // 'modal', 'new_tab', 'direct'
			'batch_size'           => 5,
			'auto_index'           => true,
			'default_access_mode'  => 'public',
			'delete_on_uninstall'  => false,
		];

		$existing = get_option( self::OPTION_SETTINGS, [] );
		if ( empty( $existing ) || ! is_array( $existing ) ) {
			update_option( self::OPTION_SETTINGS, $defaults );
		}
	}

	/**
	 * Initialize settings hooks.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'admin_init', [ $this, 'handle_form_submissions' ] );
	}

	/**
	 * Process form actions across tabs.
	 *
	 * @return void
	 */
	public function handle_form_submissions(): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed. Please refresh the page.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to modify settings.', 'wpcalibrate-searchable-pdf-library' ) );
		}

		$action = sanitize_key( (string) ( $_POST['wpcal_action'] ?? '' ) );

		// 1. Save Search & Display settings
		if ( 'save_search_display' === $action ) {
			$current = get_option( self::OPTION_SETTINGS, [] );
			$current['default_layout']       = in_array( $_POST['default_layout'] ?? '', [ 'grid', 'list' ], true ) ? $_POST['default_layout'] : 'grid';
			$current['per_page']             = max( 1, min( 100, (int) ( $_POST['per_page'] ?? 12 ) ) );
			$current['default_sort']         = sanitize_key( (string) ( $_POST['default_sort'] ?? 'relevance' ) );
			$current['show_search']          = ! empty( $_POST['show_search'] );
			$current['show_filters']         = ! empty( $_POST['show_filters'] );
			$current['show_view_button']     = ! empty( $_POST['show_view_button'] );
			$current['show_download_button'] = ! empty( $_POST['show_download_button'] );
			$current['viewer_behavior']      = in_array( $_POST['viewer_behavior'] ?? '', [ 'modal', 'new_tab', 'direct' ], true ) ? $_POST['viewer_behavior'] : 'modal';

			update_option( self::OPTION_SETTINGS, $current );
			$this->redirect_with_message( 'search_display', 'settings_saved' );
		}

		// 2. Save Indexing settings
		if ( 'save_indexing' === $action ) {
			$current = get_option( self::OPTION_SETTINGS, [] );
			$current['batch_size'] = max( 1, min( 50, (int) ( $_POST['batch_size'] ?? 5 ) ) );
			$current['auto_index'] = ! empty( $_POST['auto_index'] );

			update_option( self::OPTION_SETTINGS, $current );
			$this->redirect_with_message( 'indexing', 'settings_saved' );
		}

		// 3. Save Access settings
		if ( 'save_access' === $action ) {
			$current = get_option( self::OPTION_SETTINGS, [] );
			$current['default_access_mode'] = in_array( $_POST['default_access_mode'] ?? '', [ 'public', 'logged_in', 'roles' ], true ) ? $_POST['default_access_mode'] : 'public';
			$current['delete_on_uninstall'] = ! empty( $_POST['delete_on_uninstall'] );

			update_option( self::OPTION_SETTINGS, $current );
			update_option( 'wpcal_spl_delete_on_uninstall', $current['delete_on_uninstall'] ? 1 : 0 );

			$this->redirect_with_message( 'access', 'settings_saved' );
		}

		// 4. Save Custom Field definition
		if ( 'save_custom_field' === $action ) {
			$res = $this->custom_field_manager->save_field( $_POST );
			if ( is_wp_error( $res ) ) {
				$this->redirect_with_message( 'custom_fields', 'error', $res->get_error_message() );
			} else {
				$this->redirect_with_message( 'custom_fields', 'field_saved' );
			}
		}

		// 5. Delete Custom Field definition
		if ( 'delete_custom_field' === $action ) {
			$key = sanitize_key( (string) ( $_POST['field_key'] ?? '' ) );
			$this->custom_field_manager->delete_field( $key );
			$this->redirect_with_message( 'custom_fields', 'field_deleted' );
		}

		// 6. Tools: Scan & Import Media Library PDFs
		if ( 'import_media_library' === $action ) {
			$batch_size = max( 1, min( 100, (int) ( $_POST['import_batch_size'] ?? 20 ) ) );
			$res        = $this->scanner->import_batch( $batch_size, true );
			$this->redirect_with_message( 'tools', 'imported', sprintf( __( 'Imported %d PDF attachments and queued them for indexing.', 'wpcalibrate-searchable-pdf-library' ), $res['imported_count'] ) );
		}

		// 7. Tools: Queue All Re-index
		if ( 'reindex_all' === $action ) {
			$count = $this->queue->queue_all( false );
			$this->redirect_with_message( 'tools', 'queued', sprintf( __( 'Queued %d documents for re-indexing.', 'wpcalibrate-searchable-pdf-library' ), $count ) );
		}

		// 8. Tools: Retry Failed
		if ( 'retry_failed' === $action ) {
			$count = $this->queue->retry_failed();
			$this->redirect_with_message( 'tools', 'queued', sprintf( __( 'Retried and queued %d failed documents.', 'wpcalibrate-searchable-pdf-library' ), $count ) );
		}

		// 9. Tools: Purge Stale Rows
		if ( 'purge_stale' === $action ) {
			$count = $this->tools->purge_stale_index_rows();
			$this->redirect_with_message( 'tools', 'purged', sprintf( __( 'Purged %d stale rows from the search index.', 'wpcalibrate-searchable-pdf-library' ), $count ) );
		}

		// 10. Tools: Rebuild entire index
		if ( 'rebuild_index' === $action ) {
			$count = $this->tools->rebuild_entire_index();
			$this->redirect_with_message( 'tools', 'rebuilt', sprintf( __( 'Search index cleared. Queued %d documents for complete re-indexing.', 'wpcalibrate-searchable-pdf-library' ), $count ) );
		}

		// 11. Save License Key
		if ( 'save_license' === $action ) {
			$key = sanitize_text_field( (string) ( $_POST['license_key'] ?? '' ) );
			$this->license_provider->save_key( $key );
			$this->redirect_with_message( 'license', 'license_saved' );
		}
	}

	/**
	 * Safe redirect helper with flash message query parameter.
	 *
	 * @param string      $tab Current tab.
	 * @param string      $msg Message code.
	 * @param string|null $custom_text Optional custom string.
	 * @return void
	 */
	private function redirect_with_message( string $tab, string $msg, ?string $custom_text = null ): void {
		$args = [
			'page'      => MenuManager::SUBMENU_PAGE_SLUG,
			'tab'       => $tab,
			'wpcal_msg' => $msg,
		];
		if ( $custom_text ) {
			$args['wpcal_note'] = rawurlencode( $custom_text );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = sanitize_key( (string) ( $_GET['tab'] ?? 'dashboard' ) );

		$tabs = [
			'dashboard'      => __( 'Dashboard', 'wpcalibrate-searchable-pdf-library' ),
			'overview'       => __( 'Overview', 'wpcalibrate-searchable-pdf-library' ),
			'documents'      => __( 'Documents', 'wpcalibrate-searchable-pdf-library' ),
			'categories'     => __( 'Categories', 'wpcalibrate-searchable-pdf-library' ),
			'custom_fields'  => __( 'Custom Fields', 'wpcalibrate-searchable-pdf-library' ),
			'search_display' => __( 'Search & Display', 'wpcalibrate-searchable-pdf-library' ),
			'indexing'       => __( 'Indexing', 'wpcalibrate-searchable-pdf-library' ),
			'access'         => __( 'Access Control', 'wpcalibrate-searchable-pdf-library' ),
			'license'        => __( 'License', 'wpcalibrate-searchable-pdf-library' ),
			'tools'          => __( 'Tools', 'wpcalibrate-searchable-pdf-library' ),
			'support'        => __( 'Support', 'wpcalibrate-searchable-pdf-library' ),
		];

		if ( ! array_key_exists( $current_tab, $tabs ) ) {
			$current_tab = 'dashboard';
		}

		$brand_icon_url = MenuManager::get_brand_icon_url( 'dark' );
		?>
		<div class="wrap wpcal-admin-wrap">
			<!-- Header with WPCalibrate Branding -->
			<header class="wpcal-admin-header">
				<div class="wpcal-header-brand">
					<?php if ( str_starts_with( $brand_icon_url, 'http' ) ) : ?>
						<img src="<?php echo esc_url( $brand_icon_url ); ?>" alt="WPCalibrate" class="wpcal-brand-logo" width="36" height="36">
					<?php else : ?>
						<span class="dashicons <?php echo esc_attr( $brand_icon_url ); ?> wpcal-brand-icon"></span>
					<?php endif; ?>
					<div>
						<h1 class="wpcal-header-title"><?php esc_html_e( 'Searchable PDF Library', 'wpcalibrate-searchable-pdf-library' ); ?></h1>
						<span class="wpcal-header-subtitle"><?php esc_html_e( 'by WPCalibrate', 'wpcalibrate-searchable-pdf-library' ); ?> &bull; v<?php echo esc_html( WPCAL_SPL_VERSION ); ?></span>
					</div>
				</div>
				<div class="wpcal-header-actions">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . DocumentPostType::POST_TYPE ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt2" style="margin-top:4px;"></span>
						<?php esc_html_e( 'Add New PDF Document', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
				</div>
			</header>

			<!-- Flash Notices -->
			<?php $this->render_notices(); ?>

			<!-- Tab Navigation -->
			<nav class="nav-tab-wrapper wpcal-nav-tabs">
				<?php foreach ( $tabs as $tab_id => $tab_title ) : ?>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => MenuManager::SUBMENU_PAGE_SLUG, 'tab' => $tab_id ], admin_url( 'admin.php' ) ) ); ?>" class="nav-tab <?php echo $current_tab === $tab_id ? 'nav-tab-active' : ''; ?>">
						<?php echo esc_html( $tab_title ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<!-- Tab Content Body -->
			<main class="wpcal-tab-content">
				<?php
				switch ( $current_tab ) {
					case 'dashboard':
						$this->render_dashboard_tab();
						break;
					case 'overview':
						$this->render_overview_tab();
						break;
					case 'documents':
						$this->render_documents_tab();
						break;
					case 'categories':
						$this->render_categories_tab();
						break;
					case 'custom_fields':
						$this->render_custom_fields_tab();
						break;
					case 'search_display':
						$this->render_search_display_tab();
						break;
					case 'indexing':
						$this->render_indexing_tab();
						break;
					case 'access':
						$this->render_access_tab();
						break;
					case 'license':
						$this->render_license_tab();
						break;
					case 'tools':
						$this->render_tools_tab();
						break;
					case 'support':
						$this->render_support_tab();
						break;
				}
				?>
			</main>
		</div>
		<?php
	}

	/**
	 * Render flash admin notices.
	 *
	 * @return void
	 */
	private function render_notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$msg = sanitize_key( (string) ( $_GET['wpcal_msg'] ?? '' ) );
		$note = isset( $_GET['wpcal_note'] ) ? sanitize_text_field( rawurldecode( (string) $_GET['wpcal_note'] ) ) : '';
		// phpcs:enable

		if ( empty( $msg ) ) {
			return;
		}

		$text = match ( $msg ) {
			'settings_saved' => __( 'Settings updated successfully.', 'wpcalibrate-searchable-pdf-library' ),
			'field_saved'    => __( 'Custom metadata field saved successfully.', 'wpcalibrate-searchable-pdf-library' ),
			'field_deleted'  => __( 'Custom metadata field deleted.', 'wpcalibrate-searchable-pdf-library' ),
			'license_saved'  => __( 'License key saved. (Integration unconfigured in V1).', 'wpcalibrate-searchable-pdf-library' ),
			default          => $note ?: __( 'Action completed.', 'wpcalibrate-searchable-pdf-library' ),
		};

		$class = 'error' === $msg ? 'notice-error' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible" style="margin:12px 0;"><p>' . esc_html( $text ) . '</p></div>';
	}

	/**
	 * Tab: Dashboard
	 *
	 * @return void
	 */
	private function render_dashboard_tab(): void {
		$counts       = $this->repository->get_status_counts();
		$db_health    = $this->schema_manager->check_health();
		$parser       = new LocalPdfParser();
		$availability = $parser->check_availability();
		$server_check = ProtectedFileDelivery::diagnose_server_protection();
		$unimported   = $this->scanner->count_unimported_pdfs();
		?>
		<div class="wpcal-dashboard-grid">
			<!-- Metrics Cards -->
			<div class="wpcal-metric-card">
				<span class="wpcal-metric-label"><?php esc_html_e( 'Total Managed PDFs', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				<span class="wpcal-metric-val"><?php echo esc_html( (string) $counts['total'] ); ?></span>
			</div>
			<div class="wpcal-metric-card">
				<span class="wpcal-metric-label"><?php esc_html_e( 'Indexed Documents', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				<span class="wpcal-metric-val text-success"><?php echo esc_html( (string) $counts['indexed'] ); ?></span>
			</div>
			<div class="wpcal-metric-card">
				<span class="wpcal-metric-label"><?php esc_html_e( 'Queued in Background', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				<span class="wpcal-metric-val text-info"><?php echo esc_html( (string) $counts['queued'] ); ?></span>
			</div>
			<div class="wpcal-metric-card">
				<span class="wpcal-metric-label"><?php esc_html_e( 'Failed Extractions', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				<span class="wpcal-metric-val text-danger"><?php echo esc_html( (string) $counts['failed'] ); ?></span>
			</div>
			<div class="wpcal-metric-card">
				<span class="wpcal-metric-label"><?php esc_html_e( 'Scanned / Low Text', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				<span class="wpcal-metric-val text-warning"><?php echo esc_html( (string) $counts['scanned_warning'] ); ?></span>
			</div>
			<div class="wpcal-metric-card">
				<span class="wpcal-metric-label"><?php esc_html_e( 'Unmanaged Media PDFs', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				<span class="wpcal-metric-val"><?php echo esc_html( (string) $unimported ); ?></span>
			</div>
		</div>

		<!-- System Diagnostics -->
		<div class="wpcal-admin-card" style="margin-top:24px;">
			<h3><?php esc_html_e( 'System & Search Engine Diagnostics', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
			<table class="widefat striped">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'PDF Parser Extensions (GD, iconv, zlib):', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td>
							<?php if ( $availability['is_available'] ) : ?>
								<span class="wpcal-badge wpcal-badge-success"><?php esc_html_e( 'All Required Extensions Installed', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php else : ?>
								<span class="wpcal-badge wpcal-badge-danger"><?php esc_html_e( 'Missing:', 'wpcalibrate-searchable-pdf-library' ); ?> <?php echo esc_html( implode( ', ', $availability['missing_extensions'] ) ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Database Search Index:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td>
							<?php if ( $db_health['fulltext_supported'] ) : ?>
								<span class="wpcal-badge wpcal-badge-success"><?php esc_html_e( 'MySQL FULLTEXT Active', 'wpcalibrate-searchable-pdf-library' ); ?></span>
								<small>(<?php printf( esc_html__( '%d indexed chunks in table', 'wpcalibrate-searchable-pdf-library' ), $db_health['total_indexed_rows'] ); ?>)</small>
							<?php else : ?>
								<span class="wpcal-badge wpcal-badge-warning"><?php esc_html_e( 'Degraded Fallback (LIKE) Mode Active', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Web Server File Protection:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td>
							<?php if ( $server_check['is_protected'] ) : ?>
								<span class="wpcal-badge wpcal-badge-success"><?php echo esc_html( $server_check['server'] ); ?> &bull; <?php esc_html_e( 'Deny Rules Active', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php else : ?>
								<span class="wpcal-badge wpcal-badge-warning"><?php echo esc_html( $server_check['server'] ); ?></span>
								<p class="description" style="color:#b45309;"><?php echo esc_html( $server_check['warning_message'] ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Background Processing:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td>
							<?php if ( function_exists( 'as_enqueue_async_action' ) ) : ?>
								<span class="wpcal-badge wpcal-badge-success"><?php esc_html_e( 'Action Scheduler Active', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php else : ?>
								<span class="wpcal-badge wpcal-badge-info"><?php esc_html_e( 'WordPress WP-Cron Queue Active', 'wpcalibrate-searchable-pdf-library' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Tab: Overview (Purpose, How to Use, Methods & Architecture)
	 *
	 * @return void
	 */
	private function render_overview_tab(): void {
		?>
		<div class="wpcal-overview-container">
			<!-- Hero Section: Purpose and Value -->
			<section class="wpcal-overview-hero">
				<h2><?php esc_html_e( 'Welcome to WPCalibrate Searchable PDF Library', 'wpcalibrate-searchable-pdf-library' ); ?></h2>
				<p>
					<?php esc_html_e( 'WPCalibrate Searchable PDF Library transforms static PDF files into an interactive, lightning-fast, full-text searchable document archive. Unlike standard WordPress media tools that only index file titles or descriptions, this plugin extracts and indexes the actual inner text of your PDF pages, enabling contextual keyword discovery, search snippets with exact page citations, role-based access security, and modern responsive frontend displays.', 'wpcalibrate-searchable-pdf-library' ); ?>
				</p>
				<div class="wpcal-hero-badges">
					<span class="wpcal-badge"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( '100% On-Premise Pure-PHP Extraction', 'wpcalibrate-searchable-pdf-library' ); ?></span>
					<span class="wpcal-badge"><span class="dashicons dashicons-database"></span> <?php esc_html_e( 'MySQL FULLTEXT Natural & Boolean Search', 'wpcalibrate-searchable-pdf-library' ); ?></span>
					<span class="wpcal-badge"><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'Role-Based Access & Tokenized File Delivery', 'wpcalibrate-searchable-pdf-library' ); ?></span>
					<span class="wpcal-badge"><span class="dashicons dashicons-block-default"></span> <?php esc_html_e( 'Gutenberg Block & Shortcode Ready', 'wpcalibrate-searchable-pdf-library' ); ?></span>
					<span class="wpcal-badge"><span class="dashicons dashicons-lock"></span> <?php esc_html_e( 'Zero External SaaS Fees or Data Leaks', 'wpcalibrate-searchable-pdf-library' ); ?></span>
				</div>
			</section>

			<!-- Quick-Start Workflow: How to Use -->
			<section class="wpcal-admin-card">
				<h3 class="wpcal-section-title">
					<span class="dashicons dashicons-controls-play" style="color:#0284c7;"></span>
					<?php esc_html_e( 'How to Use: 4-Step Quick Start Guide', 'wpcalibrate-searchable-pdf-library' ); ?>
				</h3>
				<div class="wpcal-steps-grid">
					<div class="wpcal-step-card">
						<span class="wpcal-step-number">1</span>
						<h3><?php esc_html_e( 'Add or Import PDFs', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Create new documents via Documents > Add New PDF Document, or go to Tools > Media Library Scanner to automatically scan and import existing WordPress PDF attachments into your searchable library.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
					<div class="wpcal-step-card">
						<span class="wpcal-step-number">2</span>
						<h3><?php esc_html_e( 'Automated Text Indexing', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'When saved, the plugin automatically extracts page text, normalizes characters, creates search chunks, and updates the MySQL search index in the background without slowing down page loads.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
					<div class="wpcal-step-card">
						<span class="wpcal-step-number">3</span>
						<h3><?php esc_html_e( 'Classify & Control Access', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Organize documents with Categories, Tags, and Custom Metadata fields (Authors, Publication Years, Reference IDs). Set visibility to Public, Logged-In Users Only, or specific user roles.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
					<div class="wpcal-step-card">
						<span class="wpcal-step-number">4</span>
						<h3><?php esc_html_e( 'Embed on Frontend', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Insert the Gutenberg block "Searchable PDF Library" or place the [wpcal_pdf_library] shortcode on any post, page, or widget to provide an interactive, instant-search document portal.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
				</div>
			</section>

			<!-- Methods & Architectural Purpose -->
			<section class="wpcal-admin-card">
				<h3 class="wpcal-section-title">
					<span class="dashicons dashicons-admin-generic" style="color:#0284c7;"></span>
					<?php esc_html_e( 'Plugin Methods & Technical Architecture', 'wpcalibrate-searchable-pdf-library' ); ?>
				</h3>
				<div class="wpcal-methods-grid">
					<div class="wpcal-method-card">
						<h3><span class="dashicons dashicons-media-document"></span> <?php esc_html_e( 'Extraction Method', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Uses an embedded, pure-PHP stream parser (prinsfrank/pdfparser). Operates entirely inside your WordPress environment without external CLI tools (no exec/shell_exec requirements) and without sending sensitive corporate documents to third-party cloud services.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
					<div class="wpcal-method-card">
						<h3><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Search & Snippet Method', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Performs high-speed queries on dedicated custom database tables using MySQL FULLTEXT indexes. Returns relevant contextual excerpts around matches with bold query term highlights and precise page-number citations.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
					<div class="wpcal-method-card">
						<h3><span class="dashicons dashicons-shield-alt"></span> <?php esc_html_e( 'Access Control Method', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Protected PDFs can be restricted by user capability or role. When restricted, direct public file access is blocked by server rewrite rules and documents are safely delivered via authenticated, tokenized endpoints with download-audit logging.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
					<div class="wpcal-method-card">
						<h3><span class="dashicons dashicons-layout"></span> <?php esc_html_e( 'Display & Viewer Method', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
						<p><?php esc_html_e( 'Offers Grid, Table, and List display templates. Includes instant AJAX search filtering without page reloads, responsive category dropdowns, and an inline modal PDF reader so users can read files without leaving the page.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
					</div>
				</div>
			</section>

			<!-- Shortcode & Embed Reference -->
			<section class="wpcal-admin-card">
				<h3 class="wpcal-section-title">
					<span class="dashicons dashicons-shortcode" style="color:#0284c7;"></span>
					<?php esc_html_e( 'Shortcode & Embedding Reference', 'wpcalibrate-searchable-pdf-library' ); ?>
				</h3>
				<div class="wpcal-code-box">
					<div class="wpcal-code-snippet">[wpcal_pdf_library layout="grid" per_page="12" show_search="true" show_filters="true"]</div>
					<p class="description" style="margin-bottom:12px;">
						<?php esc_html_e( 'Paste this shortcode anywhere on your site, or use the dedicated Gutenberg block "Searchable PDF Library" in the WordPress block editor.', 'wpcalibrate-searchable-pdf-library' ); ?>
					</p>
					<table class="widefat striped" style="margin-top:10px;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Attribute', 'wpcalibrate-searchable-pdf-library' ); ?></th>
								<th><?php esc_html_e( 'Default', 'wpcalibrate-searchable-pdf-library' ); ?></th>
								<th><?php esc_html_e( 'Options / Description', 'wpcalibrate-searchable-pdf-library' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><code>layout</code></td>
								<td><code>grid</code></td>
								<td><code>grid</code>, <code>table</code>, <code>list</code></td>
							</tr>
							<tr>
								<td><code>category</code></td>
								<td><em>(all)</em></td>
								<td><?php esc_html_e( 'Filter by category slug (e.g., category="annual-reports")', 'wpcalibrate-searchable-pdf-library' ); ?></td>
							</tr>
							<tr>
								<td><code>per_page</code></td>
								<td><code>12</code></td>
								<td><?php esc_html_e( 'Number of documents displayed per page (pagination)', 'wpcalibrate-searchable-pdf-library' ); ?></td>
							</tr>
							<tr>
								<td><code>show_search</code></td>
								<td><code>true</code></td>
								<td><code>true</code>, <code>false</code> <?php esc_html_e( '(Toggle live search bar)', 'wpcalibrate-searchable-pdf-library' ); ?></td>
							</tr>
							<tr>
								<td><code>show_filters</code></td>
								<td><code>true</code></td>
								<td><code>true</code>, <code>false</code> <?php esc_html_e( '(Toggle category and tag filters)', 'wpcalibrate-searchable-pdf-library' ); ?></td>
							</tr>
							<tr>
								<td><code>show_view_button</code></td>
								<td><code>true</code></td>
								<td><code>true</code>, <code>false</code> <?php esc_html_e( '(Toggle PDF view/read button)', 'wpcalibrate-searchable-pdf-library' ); ?></td>
							</tr>
							<tr>
								<td><code>show_download_button</code></td>
								<td><code>true</code></td>
								<td><code>true</code>, <code>false</code> <?php esc_html_e( '(Toggle download button)', 'wpcalibrate-searchable-pdf-library' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</section>

			<!-- Quick Actions Bar -->
			<section class="wpcal-admin-card" style="background:#f8fafc;">
				<h3 class="wpcal-section-title">
					<span class="dashicons dashicons-admin-links" style="color:#0284c7;"></span>
					<?php esc_html_e( 'Quick Shortcuts', 'wpcalibrate-searchable-pdf-library' ); ?>
				</h3>
				<div class="wpcal-quick-actions">
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . DocumentPostType::POST_TYPE ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-plus-alt2" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Add New PDF Document', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => MenuManager::SUBMENU_PAGE_SLUG, 'tab' => 'tools' ], admin_url( 'admin.php' ) ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-tools" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Media Library Scanner', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => MenuManager::SUBMENU_PAGE_SLUG, 'tab' => 'search_display' ], admin_url( 'admin.php' ) ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-appearance" style="margin-top:3px;"></span>
						<?php esc_html_e( 'Search & Display Settings', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => MenuManager::SUBMENU_PAGE_SLUG, 'tab' => 'documents' ], admin_url( 'admin.php' ) ) ); ?>" class="button">
						<span class="dashicons dashicons-list-view" style="margin-top:3px;"></span>
						<?php esc_html_e( 'View Managed Documents', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
					<a href="<?php echo esc_url( add_query_arg( [ 'page' => MenuManager::SUBMENU_PAGE_SLUG, 'tab' => 'dashboard' ], admin_url( 'admin.php' ) ) ); ?>" class="button">
						<span class="dashicons dashicons-dashboard" style="margin-top:3px;"></span>
						<?php esc_html_e( 'System Diagnostics', 'wpcalibrate-searchable-pdf-library' ); ?>
					</a>
				</div>
			</section>
		</div>
		<?php
	}

	/**
	 * Tab: Documents
	 *
	 * @return void
	 */
	private function render_documents_tab(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$status = sanitize_key( (string) ( $_GET['status'] ?? '' ) );
		$search = sanitize_text_field( (string) ( $_GET['s'] ?? '' ) );
		// phpcs:enable

		$query_result = $this->repository->query( [
			'paged'    => $paged,
			'per_page' => 20,
			'status'   => $status,
			'search'   => $search,
		] );
		?>
		<div class="wpcal-tab-header">
			<form method="GET" class="wpcal-filter-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( MenuManager::SUBMENU_PAGE_SLUG ); ?>">
				<input type="hidden" name="tab" value="documents">
				<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Filter by title...', 'wpcalibrate-searchable-pdf-library' ); ?>" class="regular-text">
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'wpcalibrate-searchable-pdf-library' ); ?></option>
					<option value="indexed" <?php selected( $status, 'indexed' ); ?>><?php esc_html_e( 'Indexed', 'wpcalibrate-searchable-pdf-library' ); ?></option>
					<option value="queued" <?php selected( $status, 'queued' ); ?>><?php esc_html_e( 'Queued', 'wpcalibrate-searchable-pdf-library' ); ?></option>
					<option value="needs_attention" <?php selected( $status, 'needs_attention' ); ?>><?php esc_html_e( 'Needs OCR', 'wpcalibrate-searchable-pdf-library' ); ?></option>
					<option value="failed" <?php selected( $status, 'failed' ); ?>><?php esc_html_e( 'Failed', 'wpcalibrate-searchable-pdf-library' ); ?></option>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'wpcalibrate-searchable-pdf-library' ); ?></button>
			</form>
		</div>

		<table class="wp-list-table widefat fixed striped posts" style="margin-top:16px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Document Title', 'wpcalibrate-searchable-pdf-library' ); ?></th>
					<th><?php esc_html_e( 'Source File', 'wpcalibrate-searchable-pdf-library' ); ?></th>
					<th><?php esc_html_e( 'Indexing Status', 'wpcalibrate-searchable-pdf-library' ); ?></th>
					<th><?php esc_html_e( 'Pages', 'wpcalibrate-searchable-pdf-library' ); ?></th>
					<th><?php esc_html_e( 'Access Mode', 'wpcalibrate-searchable-pdf-library' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'wpcalibrate-searchable-pdf-library' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $query_result['documents'] ) ) : ?>
					<tr>
						<td colspan="6" style="text-align:center;padding:24px;color:#94a3b8;">
							<?php esc_html_e( 'No PDF documents found matching your filter criteria.', 'wpcalibrate-searchable-pdf-library' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $query_result['documents'] as $doc ) : ?>
						<?php
						$status_key = $doc->get_indexing_status();
						$badge      = IndexStatus::get_badge_class( $status_key );
						$label      = IndexStatus::get_label( $status_key );
						$edit_url   = get_edit_post_link( $doc->get_id() );
						?>
						<tr>
							<td>
								<strong><a href="<?php echo esc_url( (string) $edit_url ); ?>"><?php echo esc_html( $doc->get_title() ); ?></a></strong>
							</td>
							<td>
								<?php
								$att_id = $doc->get_attachment_id();
								if ( $att_id > 0 ) {
									$url = wp_get_attachment_url( $att_id );
									echo '<a href="' . esc_url( (string) $url ) . '" target="_blank">' . esc_html( basename( (string) get_attached_file( $att_id ) ) ) . '</a>';
								} else {
									echo '<span style="color:#94a3b8;">' . esc_html__( 'No file attached', 'wpcalibrate-searchable-pdf-library' ) . '</span>';
								}
								?>
							</td>
							<td>
								<span class="wpcal-badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span>
							</td>
							<td><?php echo esc_html( (string) ( $doc->get_page_count() ?: '-' ) ); ?></td>
							<td><?php echo esc_html( ucfirst( $doc->get_access_mode() ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( (string) $edit_url ); ?>" class="button button-small"><?php esc_html_e( 'Edit', 'wpcalibrate-searchable-pdf-library' ); ?></a>
								<button type="button" class="button button-small wpcal-reindex-trigger" data-doc-id="<?php echo esc_attr( (string) $doc->get_id() ); ?>">
									<?php esc_html_e( 'Re-index', 'wpcalibrate-searchable-pdf-library' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Tab: Categories
	 *
	 * @return void
	 */
	private function render_categories_tab(): void {
		?>
		<div class="wpcal-admin-card">
			<h3><?php esc_html_e( 'Manage Document Taxonomies', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
			<p><?php esc_html_e( 'Categories and Tags help organize documents and power frontend filter dropdowns.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . DocumentTaxonomies::TAXONOMY_CATEGORY . '&post_type=' . DocumentPostType::POST_TYPE ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Open PDF Categories Manager &raquo;', 'wpcalibrate-searchable-pdf-library' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . DocumentTaxonomies::TAXONOMY_TAG . '&post_type=' . DocumentPostType::POST_TYPE ) ); ?>" class="button button-secondary" style="margin-left:8px;">
					<?php esc_html_e( 'Open PDF Tags Manager &raquo;', 'wpcalibrate-searchable-pdf-library' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Tab: Custom Fields
	 *
	 * @return void
	 */
	private function render_custom_fields_tab(): void {
		$fields = $this->custom_field_manager->get_fields();
		?>
		<div class="wpcal-two-col">
			<!-- Add Field Form -->
			<div class="wpcal-admin-card">
				<h3><?php esc_html_e( 'Add Custom Metadata Field', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
				<form method="POST">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wpcal_action" value="save_custom_field">

					<div class="wpcal-admin-field">
						<label for="field_label"><?php esc_html_e( 'Field Label (e.g. Department, Project Code):', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						<input type="text" id="field_label" name="label" class="widefat" required>
					</div>

					<div class="wpcal-admin-field" style="margin-top:10px;">
						<label for="field_key"><?php esc_html_e( 'Unique Key (Optional, auto-generated):', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						<input type="text" id="field_key" name="key" class="widefat" placeholder="cf_department">
					</div>

					<div class="wpcal-admin-field" style="margin-top:10px;">
						<label for="field_type"><?php esc_html_e( 'Field Type:', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						<select id="field_type" name="type" class="widefat">
							<option value="text"><?php esc_html_e( 'Single-line Text', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							<option value="number"><?php esc_html_e( 'Number', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							<option value="date"><?php esc_html_e( 'Date', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							<option value="select"><?php esc_html_e( 'Select Dropdown', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							<option value="checkbox"><?php esc_html_e( 'Checkbox', 'wpcalibrate-searchable-pdf-library' ); ?></option>
						</select>
					</div>

					<div class="wpcal-admin-field" id="options-row" style="margin-top:10px;display:none;">
						<label for="field_options"><?php esc_html_e( 'Select Options (One per line):', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						<textarea id="field_options" name="options" class="widefat" rows="3"></textarea>
					</div>

					<div class="wpcal-admin-field" style="margin-top:10px;">
						<label for="field_desc"><?php esc_html_e( 'Description / Help Text:', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						<input type="text" id="field_desc" name="description" class="widefat">
					</div>

					<div class="wpcal-admin-field" style="margin-top:12px;">
						<label><input type="checkbox" name="searchable" value="1" checked> <?php esc_html_e( 'Include in full-text search indexing', 'wpcalibrate-searchable-pdf-library' ); ?></label><br>
						<label><input type="checkbox" name="display_in_results" value="1" checked> <?php esc_html_e( 'Display in frontend search result cards', 'wpcalibrate-searchable-pdf-library' ); ?></label><br>
						<label><input type="checkbox" name="required" value="1"> <?php esc_html_e( 'Require value on document edit', 'wpcalibrate-searchable-pdf-library' ); ?></label>
					</div>

					<div style="margin-top:16px;">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Add Custom Field', 'wpcalibrate-searchable-pdf-library' ); ?></button>
					</div>
				</form>
			</div>

			<!-- Active Fields List -->
			<div class="wpcal-admin-card">
				<h3><?php esc_html_e( 'Configured Custom Fields', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
				<?php if ( empty( $fields ) ) : ?>
					<p style="color:#94a3b8;"><?php esc_html_e( 'No custom metadata fields defined yet.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Label', 'wpcalibrate-searchable-pdf-library' ); ?></th>
								<th><?php esc_html_e( 'Key', 'wpcalibrate-searchable-pdf-library' ); ?></th>
								<th><?php esc_html_e( 'Type', 'wpcalibrate-searchable-pdf-library' ); ?></th>
								<th><?php esc_html_e( 'Actions', 'wpcalibrate-searchable-pdf-library' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $fields as $k => $f ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $f['label'] ); ?></strong></td>
									<td><code><?php echo esc_html( (string) $k ); ?></code></td>
									<td><?php echo esc_html( ucfirst( $f['type'] ) ); ?></td>
									<td>
										<form method="POST" style="display:inline;">
											<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
											<input type="hidden" name="wpcal_action" value="delete_custom_field">
											<input type="hidden" name="field_key" value="<?php echo esc_attr( (string) $k ); ?>">
											<button type="submit" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Delete this custom field?', 'wpcalibrate-searchable-pdf-library' ); ?>');">
												<?php esc_html_e( 'Delete', 'wpcalibrate-searchable-pdf-library' ); ?>
											</button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab: Search & Display
	 *
	 * @return void
	 */
	private function render_search_display_tab(): void {
		$settings = get_option( self::OPTION_SETTINGS, [] );
		?>
		<div class="wpcal-admin-card" style="max-width:700px;">
			<h3><?php esc_html_e( 'Search & Display Configuration', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
			<form method="POST">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="wpcal_action" value="save_search_display">

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Layout', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<select name="default_layout">
								<option value="grid" <?php selected( $settings['default_layout'] ?? 'grid', 'grid' ); ?>><?php esc_html_e( 'Grid Layout', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="list" <?php selected( $settings['default_layout'] ?? 'grid', 'list' ); ?>><?php esc_html_e( 'List Layout', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Documents Per Page', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<input type="number" name="per_page" value="<?php echo esc_attr( (string) ( $settings['per_page'] ?? 12 ) ); ?>" min="1" max="100" class="small-text">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Sort Order', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<select name="default_sort">
								<option value="relevance" <?php selected( $settings['default_sort'] ?? 'relevance', 'relevance' ); ?>><?php esc_html_e( 'Relevance', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="title_asc" <?php selected( $settings['default_sort'] ?? 'relevance', 'title_asc' ); ?>><?php esc_html_e( 'Title: A-Z', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="title_desc" <?php selected( $settings['default_sort'] ?? 'relevance', 'title_desc' ); ?>><?php esc_html_e( 'Title: Z-A', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="newest_pub" <?php selected( $settings['default_sort'] ?? 'relevance', 'newest_pub' ); ?>><?php esc_html_e( 'Newest Publication', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="recent" <?php selected( $settings['default_sort'] ?? 'relevance', 'recent' ); ?>><?php esc_html_e( 'Recently Added', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Controls Visibility', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_search" value="1" <?php checked( $settings['show_search'] ?? true ); ?>> <?php esc_html_e( 'Display search input box', 'wpcalibrate-searchable-pdf-library' ); ?></label><br>
							<label><input type="checkbox" name="show_filters" value="1" <?php checked( $settings['show_filters'] ?? true ); ?>> <?php esc_html_e( 'Display category, tag & sort filters', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Action Buttons', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<label><input type="checkbox" name="show_view_button" value="1" <?php checked( $settings['show_view_button'] ?? true ); ?>> <?php esc_html_e( 'Show View button on results', 'wpcalibrate-searchable-pdf-library' ); ?></label><br>
							<label><input type="checkbox" name="show_download_button" value="1" <?php checked( $settings['show_download_button'] ?? true ); ?>> <?php esc_html_e( 'Show Download button on results', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Viewer Behavior', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<select name="viewer_behavior">
								<option value="modal" <?php selected( $settings['viewer_behavior'] ?? 'modal', 'modal' ); ?>><?php esc_html_e( 'Interactive Modal Popup', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="new_tab" <?php selected( $settings['viewer_behavior'] ?? 'modal', 'new_tab' ); ?>><?php esc_html_e( 'Open in New Browser Tab', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="direct" <?php selected( $settings['viewer_behavior'] ?? 'modal', 'direct' ); ?>><?php esc_html_e( 'Same Window Direct Navigation', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Where supported by modern browsers, page-specific search hits deep-link with #page=N.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Search Settings', 'wpcalibrate-searchable-pdf-library' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab: Indexing
	 *
	 * @return void
	 */
	private function render_indexing_tab(): void {
		$settings = get_option( self::OPTION_SETTINGS, [] );
		?>
		<div class="wpcal-admin-card" style="max-width:700px;">
			<h3><?php esc_html_e( 'Background Indexing Settings', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
			<form method="POST">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="wpcal_action" value="save_indexing">

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Batch Size', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<input type="number" name="batch_size" value="<?php echo esc_attr( (string) ( $settings['batch_size'] ?? 5 ) ); ?>" min="1" max="25" class="small-text">
							<p class="description"><?php esc_html_e( 'Number of documents processed per background execution to prevent server memory exhaustion.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic Indexing', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<label><input type="checkbox" name="auto_index" value="1" <?php checked( $settings['auto_index'] ?? true ); ?>> <?php esc_html_e( 'Queue document for indexing whenever PDF file or searchable metadata is saved', 'wpcalibrate-searchable-pdf-library' ); ?></label>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Indexing Settings', 'wpcalibrate-searchable-pdf-library' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab: Access Control
	 *
	 * @return void
	 */
	private function render_access_tab(): void {
		$settings = get_option( self::OPTION_SETTINGS, [] );
		$server   = ProtectedFileDelivery::diagnose_server_protection();
		?>
		<div class="wpcal-admin-card" style="max-width:700px;">
			<h3><?php esc_html_e( 'Access Control & Server Protection', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
			<form method="POST">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="wpcal_action" value="save_access">

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default Access Mode for New Documents', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<select name="default_access_mode">
								<option value="public" <?php selected( $settings['default_access_mode'] ?? 'public', 'public' ); ?>><?php esc_html_e( 'Public Access', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="logged_in" <?php selected( $settings['default_access_mode'] ?? 'public', 'logged_in' ); ?>><?php esc_html_e( 'Logged-in Users Only', 'wpcalibrate-searchable-pdf-library' ); ?></option>
								<option value="roles" <?php selected( $settings['default_access_mode'] ?? 'public', 'roles' ); ?>><?php esc_html_e( 'Selected Roles', 'wpcalibrate-searchable-pdf-library' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Uninstall Data Retention', 'wpcalibrate-searchable-pdf-library' ); ?></th>
						<td>
							<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( ! empty( $settings['delete_on_uninstall'] ) ); ?>> <?php esc_html_e( 'Delete all plugin data when uninstalled (Default is OFF - retain data)', 'wpcalibrate-searchable-pdf-library' ); ?></label>
							<p class="description"><?php esc_html_e( 'When checked, deleting the plugin will remove index tables, managed document records, and options. Original Media Library attachments are never deleted.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
						</td>
					</tr>
				</table>

				<div class="wpcal-admin-alert" style="margin-top:20px;padding:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;">
					<h4><?php esc_html_e( 'Server File Delivery Status', 'wpcalibrate-searchable-pdf-library' ); ?></h4>
					<?php if ( $server['is_protected'] ) : ?>
						<p style="color:#16a34a;">&check; <?php printf( esc_html__( 'Web server (%s) supports local deny rules (.htaccess / web.config) for protected file storage.', 'wpcalibrate-searchable-pdf-library' ), esc_html( $server['server'] ) ); ?></p>
					<?php else : ?>
						<p style="color:#b45309;">&#9888; <?php echo esc_html( $server['warning_message'] ); ?></p>
					<?php endif; ?>
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Access Settings', 'wpcalibrate-searchable-pdf-library' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab: License
	 *
	 * @return void
	 */
	private function render_license_tab(): void {
		$masked_key = $this->license_provider->get_masked_key();
		?>
		<div class="wpcal-admin-card" style="max-width:600px;">
			<h3><?php esc_html_e( 'WPCalibrate Plugin Licensing', 'wpcalibrate-searchable-pdf-library' ); ?></h3>

			<div class="notice notice-info inline" style="margin:16px 0;">
				<p><strong><?php esc_html_e( 'License integration not configured.', 'wpcalibrate-searchable-pdf-library' ); ?></strong></p>
				<p><?php esc_html_e( 'All core Searchable PDF Library features are operational. External license validation will be enabled when WPCalibrate license provider endpoints are deployed.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
			</div>

			<form method="POST">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
				<input type="hidden" name="wpcal_action" value="save_license">

				<div class="wpcal-admin-field">
					<label for="license_key"><?php esc_html_e( 'License Key:', 'wpcalibrate-searchable-pdf-library' ); ?></label>
					<input type="text" id="license_key" name="license_key" value="<?php echo esc_attr( $masked_key ); ?>" class="regular-text" placeholder="WPCAL-XXXX-XXXX-XXXX">
				</div>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save License Key', 'wpcalibrate-searchable-pdf-library' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Tab: Tools
	 *
	 * @return void
	 */
	private function render_tools_tab(): void {
		$unimported = $this->scanner->count_unimported_pdfs();
		?>
		<div class="wpcal-admin-card" style="max-width:700px;">
			<h3><?php esc_html_e( 'Administrative Maintenance & Import Tools', 'wpcalibrate-searchable-pdf-library' ); ?></h3>

			<!-- 1. Media Library Bulk Importer -->
			<div class="wpcal-tool-block">
				<h4><?php esc_html_e( 'Scan & Bulk Import Media Library PDFs', 'wpcalibrate-searchable-pdf-library' ); ?></h4>
				<p><?php printf( esc_html__( 'Discovered %d PDF attachments in your WordPress Media Library not yet added to the searchable library.', 'wpcalibrate-searchable-pdf-library' ), $unimported ); ?></p>
				<form method="POST">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wpcal_action" value="import_media_library">
					<label><?php esc_html_e( 'Batch Size:', 'wpcalibrate-searchable-pdf-library' ); ?>
						<input type="number" name="import_batch_size" value="25" min="5" max="100" class="small-text">
					</label>
					<button type="submit" class="button button-primary" <?php disabled( 0 === $unimported ); ?>>
						<?php esc_html_e( 'Import Next Batch', 'wpcalibrate-searchable-pdf-library' ); ?>
					</button>
				</form>
			</div>

			<hr style="margin:20px 0;border-top:1px solid #e2e8f0;">

			<!-- 2. Re-index All Documents -->
			<div class="wpcal-tool-block">
				<h4><?php esc_html_e( 'Re-index Managed Documents', 'wpcalibrate-searchable-pdf-library' ); ?></h4>
				<p><?php esc_html_e( 'Queue all managed documents to re-extract and refresh their full-text searchable indexes.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
				<form method="POST" style="display:inline;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wpcal_action" value="reindex_all">
					<button type="submit" class="button"><?php esc_html_e( 'Queue All Documents', 'wpcalibrate-searchable-pdf-library' ); ?></button>
				</form>
				<form method="POST" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wpcal_action" value="retry_failed">
					<button type="submit" class="button"><?php esc_html_e( 'Retry Failed Only', 'wpcalibrate-searchable-pdf-library' ); ?></button>
				</form>
			</div>

			<hr style="margin:20px 0;border-top:1px solid #e2e8f0;">

			<!-- 3. Stale Rows & Rebuild -->
			<div class="wpcal-tool-block">
				<h4><?php esc_html_e( 'Database Index Cleanliness & Rebuild', 'wpcalibrate-searchable-pdf-library' ); ?></h4>
				<p><?php esc_html_e( 'Remove orphaned index entries or completely rebuild the entire search index from scratch.', 'wpcalibrate-searchable-pdf-library' ); ?></p>
				<form method="POST" style="display:inline;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wpcal_action" value="purge_stale">
					<button type="submit" class="button" onclick="return confirm('<?php esc_attr_e( 'Purge stale index rows?', 'wpcalibrate-searchable-pdf-library' ); ?>');">
						<?php esc_html_e( 'Purge Stale Rows', 'wpcalibrate-searchable-pdf-library' ); ?>
					</button>
				</form>
				<form method="POST" style="display:inline;margin-left:8px;">
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<input type="hidden" name="wpcal_action" value="rebuild_index">
					<button type="submit" class="button button-link-delete" onclick="return confirm('<?php esc_attr_e( 'Rebuilding will clear the search index and re-queue all documents. Continue?', 'wpcalibrate-searchable-pdf-library' ); ?>');">
						<?php esc_html_e( 'Rebuild Entire Index', 'wpcalibrate-searchable-pdf-library' ); ?>
					</button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Tab: Support
	 *
	 * @return void
	 */
	private function render_support_tab(): void {
		$support = SupportManager::get_support_info();
		?>
		<div class="wpcal-admin-card" style="max-width:600px;">
			<h3><?php esc_html_e( 'Official WPCalibrate Support', 'wpcalibrate-searchable-pdf-library' ); ?></h3>
			<p><?php esc_html_e( 'Need assistance or have a feature request? Reach out directly to our engineering team.', 'wpcalibrate-searchable-pdf-library' ); ?></p>

			<table class="widefat striped" style="margin-top:16px;">
				<tbody>
					<tr>
						<td><strong><?php esc_html_e( 'Email Support:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td><a href="mailto:<?php echo esc_attr( $support['email'] ); ?>"><?php echo esc_html( $support['email'] ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Official Website:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td><a href="<?php echo esc_url( $support['website'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $support['website'] ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Marketplace Add-ons:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td><a href="<?php echo esc_url( $support['marketplace'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $support['marketplace'] ); ?></a></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Phone / WhatsApp:', 'wpcalibrate-searchable-pdf-library' ); ?></strong></td>
						<td><a href="tel:<?php echo esc_attr( $support['whatsapp'] ); ?>"><?php echo esc_html( $support['whatsapp'] ); ?></a></td>
					</tr>
				</tbody>
			</table>
		</div>
		<?php
	}
}
