<?php
/**
 * Protected file delivery and access-controlled streaming for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Files;

use WPCalibrate\SearchablePdfLibrary\Access\DocumentAccessService;
use WPCalibrate\SearchablePdfLibrary\Documents\DocumentModel;
use WPCalibrate\SearchablePdfLibrary\Infrastructure\Logger;

/**
 * Class ProtectedFileDelivery
 *
 * Enforces server-level protection for restricted PDF files and provides
 * safe, access-controlled streaming endpoints.
 */
class ProtectedFileDelivery {

	private DocumentAccessService $access_service;

	/**
	 * Constructor.
	 *
	 * @param DocumentAccessService $access_service Document access service.
	 */
	public function __construct( DocumentAccessService $access_service ) {
		$this->access_service = $access_service;
	}

	/**
	 * Hook delivery endpoint into WordPress template_redirect.
	 *
	 * @return void
	 */
	public function init_hooks(): void {
		add_action( 'template_redirect', [ $this, 'handle_delivery_request' ] );
	}

	/**
	 * Get the protected directory path on disk.
	 *
	 * @return string
	 */
	public static function get_protected_dir(): string {
		$upload_dir = wp_upload_dir();
		$dir        = trailingslashit( $upload_dir['basedir'] ) . 'wpcal_spl_protected';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			self::ensure_server_deny_rules( $dir );
		}

		return $dir;
	}

	/**
	 * Write web-server denial rules (.htaccess, web.config, index.php) to prevent direct URL access.
	 *
	 * @param string $dir Protected directory path.
	 * @return void
	 */
	public static function ensure_server_deny_rules( string $dir ): void {
		// 1. Apache deny rule
		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$rules = "<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			@file_put_contents( $htaccess_file, $rules );
		}

		// 2. IIS web.config deny rule
		$webconfig_file = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $webconfig_file ) ) {
			$webconfig = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
				. '<configuration>' . "\n"
				. '  <system.webServer>' . "\n"
				. '    <authorization>' . "\n"
				. '      <deny users="*" />' . "\n"
				. '    </authorization>' . "\n"
				. '  </system.webServer>' . "\n"
				. '</configuration>';
			@file_put_contents( $webconfig_file, $webconfig );
		}

		// 3. Fallback index.php
		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			@file_put_contents( $index_file, "<?php\n// Silence is golden.\n" );
		}
	}

	/**
	 * Diagnose server configuration regarding direct-file access protection.
	 *
	 * @return array{is_protected: bool, server: string, warning_message: string}
	 */
	public static function diagnose_server_protection(): array {
		$server_software = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) );
		$server_type     = 'Unknown';
		$is_protected    = true;
		$warning         = '';

		if ( str_contains( $server_software, 'apache' ) || str_contains( $server_software, 'litespeed' ) ) {
			$server_type  = 'Apache / LiteSpeed';
			$is_protected = true;
		} elseif ( str_contains( $server_software, 'nginx' ) ) {
			$server_type  = 'Nginx';
			$is_protected = false;
			$warning      = __( 'Nginx detected: .htaccess deny rules are ignored by default. Ensure your Nginx configuration blocks direct requests to the /wpcal_spl_protected/ directory to prevent unauthorized downloads.', 'wpcalibrate-searchable-pdf-library' );
		} elseif ( str_contains( $server_software, 'microsoft-iis' ) ) {
			$server_type  = 'IIS';
			$is_protected = true;
		} else {
			$is_protected = false;
			$warning      = __( 'Web server could not be identified as Apache or IIS. Verify that direct URL access to protected PDF files is blocked by your web server.', 'wpcalibrate-searchable-pdf-library' );
		}

		return [
			'is_protected'    => $is_protected,
			'server'          => $server_type,
			'warning_message' => $warning,
		];
	}

	/**
	 * Handle authorized delivery request.
	 *
	 * @return void
	 */
	public function handle_delivery_request(): void {
		$action = sanitize_key( (string) ( $_GET['wpcal_spl_action'] ?? '' ) );
		if ( ! in_array( $action, [ 'view', 'download' ], true ) ) {
			return;
		}

		$doc_id = (int) ( $_GET['doc_id'] ?? 0 );
		if ( $doc_id <= 0 ) {
			wp_die( esc_html__( 'Invalid document request.', 'wpcalibrate-searchable-pdf-library' ), 400 );
		}

		$document = new DocumentModel( $doc_id );
		if ( $document->get_id() <= 0 ) {
			wp_die( esc_html__( 'Document not found.', 'wpcalibrate-searchable-pdf-library' ), 404 );
		}

		// Authorization check
		if ( ! $this->access_service->can_user_access_document( $document ) ) {
			if ( ! is_user_logged_in() ) {
				auth_redirect();
			} else {
				wp_die( esc_html__( 'You do not have permission to access this document.', 'wpcalibrate-searchable-pdf-library' ), 403 );
			}
		}

		// Resolve physical file
		$file_path = $document->get_file_path();
		if ( false === $file_path || ! file_exists( $file_path ) ) {
			Logger::warning( "Protected delivery failed: File not found for document #{$doc_id}" );
			wp_die( esc_html__( 'The requested PDF file is not available on disk.', 'wpcalibrate-searchable-pdf-library' ), 404 );
		}

		$filename = basename( $file_path );
		$filesize = filesize( $file_path );

		// Clean output buffer before sending headers
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		$disposition = ( 'view' === $action ) ? 'inline' : 'attachment';

		// Security and streaming headers
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . rawurlencode( $filename ) . '"' );
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Accept-Ranges: none' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-transform, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		if ( false !== $filesize && $filesize > 0 ) {
			header( 'Content-Length: ' . (string) $filesize );
		}

		// Stream file safely in chunks
		$handle = @fopen( $file_path, 'rb' );
		if ( false === $handle ) {
			wp_die( esc_html__( 'Unable to open file for streaming.', 'wpcalibrate-searchable-pdf-library' ), 500 );
		}

		while ( ! feof( $handle ) && ! connection_aborted() ) {
			echo fread( $handle, 8192 );
			flush();
		}

		fclose( $handle );
		exit;
	}
}
