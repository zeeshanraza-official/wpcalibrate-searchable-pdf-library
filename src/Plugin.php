<?php
/**
 * Main plugin orchestrator for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary;

use WPCalibrate\SearchablePdfLibrary\Database\SchemaManager;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentPostType;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentTaxonomies;
use WPCalibrate\SearchablePdfLibrary\Metadata\CustomFieldManager;
use WPCalibrate\SearchablePdfLibrary\Extraction\PdfExtractorInterface;
use WPCalibrate\SearchablePdfLibrary\Extraction\LocalPdfParser;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexingQueue;
use WPCalibrate\SearchablePdfLibrary\Indexing\IndexWriter;
use WPCalibrate\SearchablePdfLibrary\Search\SearchEngineInterface;
use WPCalibrate\SearchablePdfLibrary\Search\MySqlSearchEngine;
use WPCalibrate\SearchablePdfLibrary\Access\DocumentAccessService;
use WPCalibrate\SearchablePdfLibrary\Files\ProtectedFileDelivery;
use WPCalibrate\SearchablePdfLibrary\Rest\RestController;
use WPCalibrate\SearchablePdfLibrary\Frontend\FrontendRenderer;
use WPCalibrate\SearchablePdfLibrary\Frontend\ShortcodeHandler;
use WPCalibrate\SearchablePdfLibrary\Frontend\BlockHandler;
use WPCalibrate\SearchablePdfLibrary\Admin\MenuManager;
use WPCalibrate\SearchablePdfLibrary\Admin\SettingsPage;
use WPCalibrate\SearchablePdfLibrary\Admin\DocumentAdmin;
use WPCalibrate\SearchablePdfLibrary\Admin\Assets as AdminAssets;
use WPCalibrate\SearchablePdfLibrary\Licensing\DefaultLicenseProvider;
use WPCalibrate\SearchablePdfLibrary\Licensing\LicenseProviderInterface;
use WPCalibrate\SearchablePdfLibrary\Tools\MediaLibraryScanner;
use WPCalibrate\SearchablePdfLibrary\Tools\MaintenanceTools;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\GitHubUpdater;

/**
 * Class Plugin
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Whether the plugin has booted.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = new Container();
		$this->register_services();
	}

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get service container.
	 *
	 * @return Container
	 */
	public function get_container(): Container {
		return $this->container;
	}

	/**
	 * Register services inside container.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$c = $this->container;

		// Database & Migrations
		$c->set( SchemaManager::class, fn() => new SchemaManager() );

		// Metadata & Custom Fields
		$c->set( CustomFieldManager::class, fn() => new CustomFieldManager() );

		// Documents & Taxonomies
		$c->set( DocumentPostType::class, fn() => new DocumentPostType( $c->get( CustomFieldManager::class ) ) );
		$c->set( DocumentTaxonomies::class, fn() => new DocumentTaxonomies() );

		// PDF Text Extraction
		$c->set( PdfExtractorInterface::class, fn() => new LocalPdfParser() );

		// Indexing
		$c->set( IndexWriter::class, fn() => new IndexWriter( $c->get( SchemaManager::class ) ) );
		$c->set( IndexingQueue::class, fn() => new IndexingQueue(
			$c->get( PdfExtractorInterface::class ),
			$c->get( IndexWriter::class )
		) );

		// Access Control & Protected Files
		$c->set( DocumentAccessService::class, fn() => new DocumentAccessService() );
		$c->set( ProtectedFileDelivery::class, fn() => new ProtectedFileDelivery( $c->get( DocumentAccessService::class ) ) );

		// Search Engine
		$c->set( SearchEngineInterface::class, fn() => new MySqlSearchEngine(
			$c->get( DocumentAccessService::class ),
			$c->get( SchemaManager::class )
		) );

		// Licensing
		$c->set( LicenseProviderInterface::class, fn() => new DefaultLicenseProvider() );

		// Frontend Renderer, Shortcode & Gutenberg Block
		$c->set( FrontendRenderer::class, fn() => new FrontendRenderer(
			$c->get( SearchEngineInterface::class ),
			$c->get( CustomFieldManager::class ),
			$c->get( DocumentAccessService::class )
		) );
		$c->set( ShortcodeHandler::class, fn() => new ShortcodeHandler( $c->get( FrontendRenderer::class ) ) );
		$c->set( BlockHandler::class, fn() => new BlockHandler( $c->get( FrontendRenderer::class ) ) );

		// Tools
		$c->set( MediaLibraryScanner::class, fn() => new MediaLibraryScanner( $c->get( IndexingQueue::class ) ) );
		$c->set( MaintenanceTools::class, fn() => new MaintenanceTools(
			$c->get( SchemaManager::class ),
			$c->get( IndexingQueue::class )
		) );

		// REST API
		$c->set( RestController::class, fn() => new RestController(
			$c->get( SearchEngineInterface::class ),
			$c->get( DocumentAccessService::class ),
			$c->get( IndexingQueue::class ),
			$c->get( MediaLibraryScanner::class )
		) );

		// Admin
		$c->set( MenuManager::class, fn() => new MenuManager() );
		$c->set( AdminAssets::class, fn() => new AdminAssets() );
		$c->set( SettingsPage::class, fn() => new SettingsPage(
			$c->get( CustomFieldManager::class ),
			$c->get( SchemaManager::class ),
			$c->get( IndexingQueue::class ),
			$c->get( LicenseProviderInterface::class ),
			$c->get( MaintenanceTools::class ),
			$c->get( MediaLibraryScanner::class )
		) );
		$c->set( DocumentAdmin::class, fn() => new DocumentAdmin(
			$c->get( CustomFieldManager::class ),
			$c->get( IndexingQueue::class ),
			$c->get( DocumentAccessService::class )
		) );
		$c->set( GitHubUpdater::class, fn() => new GitHubUpdater(
			WPCAL_SPL_FILE,
			WPCAL_SPL_VERSION
		) );
	}

	/**
	 * Boot plugin components and bind WordPress hooks.
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		// Ensure database tables exist and are up to date (auto-migration on upgrade or FTP deploy)
		if ( get_option( SchemaManager::DB_VERSION_OPTION ) !== SchemaManager::DB_VERSION ) {
			$this->container->get( SchemaManager::class )->create_or_update_tables();
		}

		// Load translations
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// CPT and Taxonomies
		add_action( 'init', [ $this->container->get( DocumentPostType::class ), 'register' ] );
		add_action( 'init', [ $this->container->get( DocumentTaxonomies::class ), 'register' ] );

		// Shortcode & Blocks
		add_action( 'init', [ $this->container->get( ShortcodeHandler::class ), 'register' ] );
		add_action( 'init', [ $this->container->get( BlockHandler::class ), 'register' ] );

		// REST API
		add_action( 'rest_api_init', [ $this->container->get( RestController::class ), 'register_routes' ] );

		// Background indexing queue hooks
		$this->container->get( IndexingQueue::class )->init_hooks();

		// Protected file delivery endpoint
		$this->container->get( ProtectedFileDelivery::class )->init_hooks();

		// Admin hooks
		if ( is_admin() ) {
			$this->container->get( MenuManager::class )->init_hooks();
			$this->container->get( SettingsPage::class )->init_hooks();
			$this->container->get( DocumentAdmin::class )->init_hooks();
			$this->container->get( AdminAssets::class )->init_hooks();
			$this->container->get( GitHubUpdater::class )->register();
		}

		// Frontend assets
		add_action( 'wp_enqueue_scripts', [ $this->container->get( FrontendRenderer::class ), 'register_assets' ] );
	}

	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'wpcalibrate-searchable-pdf-library',
			false,
			dirname( plugin_basename( WPCAL_SPL_FILE ) ) . '/languages'
		);
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Version guard
		if ( version_compare( PHP_VERSION, '8.2.0', '<' ) ) {
			deactivate_plugins( plugin_basename( WPCAL_SPL_FILE ) );
			wp_die(
				esc_html__( 'WPCalibrate Searchable PDF Library requires PHP 8.2 or higher.', 'wpcalibrate-searchable-pdf-library' ),
				'Plugin Activation Error',
				[ 'back_link' => true ]
			);
		}

		$plugin = self::get_instance();

		// Run database migrations and schema installation
		$schema_manager = $plugin->get_container()->get( SchemaManager::class );
		$schema_manager->create_or_update_tables();

		// Set default settings if not already present
		SettingsPage::init_default_settings();

		// Schedule queue cron if Action Scheduler is not present
		$indexing_queue = $plugin->get_container()->get( IndexingQueue::class );
		$indexing_queue->schedule_queue();

		// Set transient to flush rewrite rules safely on next load
		set_transient( 'wpcal_spl_flush_rewrites', 1, 60 );
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$plugin = self::get_instance();
		$indexing_queue = $plugin->get_container()->get( IndexingQueue::class );
		$indexing_queue->unschedule_queue();

		// Release any stale transient locks
		delete_transient( 'wpcal_spl_indexing_lock' );
	}
}
