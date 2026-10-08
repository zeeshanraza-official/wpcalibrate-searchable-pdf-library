<?php
/**
 * Native GitHub Release Updater for WPCalibrate Searchable PDF Library.
 *
 * @package WPCalibrate\SearchablePdfLibrary\Infrastructure
 */

declare(strict_types=1);

namespace WPCalibrate\SearchablePdfLibrary\Infrastructure;

/**
 * Handles automatic updates via GitHub releases directly inside WordPress dashboard.
 */
class GitHubUpdater {

	private const GITHUB_REPO     = 'zeeshanraza-official/wpcalibrate-searchable-pdf-library';
	private const TRANSIENT_KEY   = 'wpcal_spl_github_update_data';
	private const TRANSIENT_EXPIRY = 43200; // 12 hours.

	private string $plugin_file;
	private string $plugin_basename;
	private string $current_version;

	/**
	 * Constructor.
	 *
	 * @param string $plugin_file Full path to main plugin file.
	 * @param string $current_version Current plugin version.
	 */
	public function __construct( string $plugin_file, string $current_version ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->current_version = $current_version;
	}

	/**
	 * Register WordPress updater hooks.
	 */
	public function register(): void {
		add_filter( 'site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_popup_info' ], 20, 3 );
		add_filter( 'upgrader_post_install', [ $this, 'post_install_rename' ], 10, 3 );
	}

	/**
	 * Check GitHub releases and inject update data into update_plugins transient.
	 *
	 * @param mixed $transient Transient data.
	 * @return mixed Modified transient.
	 */
	public function check_for_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release_data = $this->get_latest_release();
		if ( empty( $release_data ) || empty( $release_data['tag_name'] ) ) {
			return $transient;
		}

		$remote_version = ltrim( (string) $release_data['tag_name'], 'v' );
		if ( version_compare( $remote_version, $this->current_version, '>' ) ) {
			$download_url = $this->extract_download_url( $release_data );

			if ( ! empty( $download_url ) ) {
				$item = (object) [
					'id'            => 'wpcalibrate-searchable-pdf-library',
					'slug'          => 'wpcalibrate-searchable-pdf-library',
					'plugin'        => $this->plugin_basename,
					'new_version'   => $remote_version,
					'url'           => 'https://github.com/' . self::GITHUB_REPO,
					'package'       => $download_url,
					'icons'         => [
						'1x' => plugins_url( 'branding/icon-menu.png', $this->plugin_file ),
						'2x' => plugins_url( 'branding/icon-menu@2x.png', $this->plugin_file ),
					],
					'banners'       => [],
					'compatibility' => (object) [],
				];

				$transient->response[ $this->plugin_basename ] = $item;
			}
		} else {
			// Ensure it is marked as checked and up to date.
			$transient->no_update[ $this->plugin_basename ] = (object) [
				'id'            => 'wpcalibrate-searchable-pdf-library',
				'slug'          => 'wpcalibrate-searchable-pdf-library',
				'plugin'        => $this->plugin_basename,
				'new_version'   => $this->current_version,
				'url'           => 'https://github.com/' . self::GITHUB_REPO,
				'package'       => '',
			];
		}

		return $transient;
	}

	/**
	 * Supply plugin information modal data when clicking "View version X.X details".
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action Action name.
	 * @param object $args Query args.
	 * @return mixed
	 */
	public function plugin_popup_info( mixed $result, string $action, object $args ): mixed {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'wpcalibrate-searchable-pdf-library' !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();
		if ( empty( $release ) ) {
			return $result;
		}

		$version     = ltrim( (string) ( $release['tag_name'] ?? $this->current_version ), 'v' );
		$download_url = $this->extract_download_url( $release );
		$body        = (string) ( $release['body'] ?? 'Full-text searchable PDF library for WordPress.' );

		$response = (object) [
			'name'          => 'WPCalibrate Searchable PDF Library',
			'slug'          => 'wpcalibrate-searchable-pdf-library',
			'version'       => $version,
			'author'        => '<a href="https://wpcalibrate.com">WPCalibrate</a>',
			'homepage'      => 'https://github.com/' . self::GITHUB_REPO,
			'download_link' => $download_url,
			'requires'      => '6.5',
			'tested'        => '7.1.3',
			'requires_php'  => '8.2',
			'last_updated'  => (string) ( $release['published_at'] ?? current_time( 'mysql' ) ),
			'sections'      => [
				'description' => 'Full-text searchable PDF document library for WordPress with local text extraction and access controls.',
				'changelog'   => nl2br( esc_html( $body ) ),
			],
		];

		return $response;
	}

	/**
	 * Rename unpacked directory to ensure standard slug folder name after upgrade.
	 *
	 * @param bool  $response Install result.
	 * @param array $hook_extra Extra hook parameters.
	 * @param array $result Result array.
	 * @return array
	 */
	public function post_install_rename( bool $response, array $hook_extra, array $result ): array {
		if ( empty( $result['destination'] ) || empty( $hook_extra['plugin'] ) ) {
			return $result;
		}

		if ( $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $result;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			\WP_Filesystem();
		}

		$correct_destination = WP_PLUGIN_DIR . '/wpcalibrate-searchable-pdf-library';
		if ( $result['destination'] !== $correct_destination && $wp_filesystem ) {
			$wp_filesystem->move( $result['destination'], $correct_destination );
			$result['destination'] = $correct_destination;
		}

		return $result;
	}

	/**
	 * Fetch latest release from GitHub API with caching.
	 *
	 * @return array<string, mixed>
	 */
	public function get_latest_release(): array {
		$cached = get_site_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$url      = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
		$response = wp_remote_get(
			$url,
			[
				'timeout'    => 10,
				'user-agent' => 'WPCalibrate-Searchable-PDF-Library/' . $this->current_version,
				'headers'    => [
					'Accept' => 'application/vnd.github.v3+json',
				],
			]
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return [];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return [];
		}

		set_site_transient( self::TRANSIENT_KEY, $data, self::TRANSIENT_EXPIRY );
		return $data;
	}

	/**
	 * Extract release asset zip or fallback to zipball URL.
	 *
	 * @param array<string, mixed> $release
	 * @return string
	 */
	private function extract_download_url( array $release ): string {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				$name = (string) ( $asset['name'] ?? '' );
				if ( str_ends_with( $name, '.zip' ) && ! empty( $asset['browser_download_url'] ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return (string) ( $release['zipball_url'] ?? '' );
	}
}
