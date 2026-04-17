<?php
/**
 * GitHub Plugin Updater — drop-in auto-update from GitHub Releases.
 *
 * Portable: copy this file into any WordPress plugin's includes/ directory,
 * require it, and instantiate with one line. A version constant guard
 * (HW_GITHUB_UPDATER_VERSION) prevents conflicts when multiple plugins
 * bundle the same file — only the first-loaded copy runs.
 *
 * Usage:
 *   require_once __DIR__ . '/includes/class-headwall-github-plugin-updater.php';
 *   new Headwall_GitHub_Plugin_Updater( __FILE__, 'owner/repo-name' );
 *
 * @package Headwall
 * @version 1.1.2
 * @license GPLv2+
 * @author  Paul Faulkner — Headwall Hosting (https://headwall-hosting.com/)
 */

// Block direct access.
defined( 'ABSPATH' ) || die();

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- Intentionally portable class shared across plugins.

if ( defined( 'HW_GITHUB_UPDATER_VERSION' ) ) {
	return;
}

define( 'HW_GITHUB_UPDATER_VERSION', '1.1.2' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared constant across plugins.

/**
 * Checks GitHub Releases for plugin updates and hooks into the
 * WordPress plugin update system.
 *
 * @since 1.0.0
 */
class Headwall_GitHub_Plugin_Updater {

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin basename (e.g. "vulnz-agent/vulnz-agent.php").
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Plugin slug (directory name, e.g. "vulnz-agent").
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Current installed version from the plugin header.
	 *
	 * @var string
	 */
	private string $current_version;

	/**
	 * GitHub repository in "owner/repo" format.
	 *
	 * @var string
	 */
	private string $github_repo;

	/**
	 * Transient cache TTL in seconds.
	 *
	 * @var int
	 */
	private int $cache_ttl;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_file Absolute path to the main plugin file (__FILE__).
	 * @param string $github_repo GitHub repo in "owner/repo" format.
	 * @param int    $cache_ttl   Transient cache TTL in seconds. Default 12 hours.
	 */
	public function __construct( string $plugin_file, string $github_repo, int $cache_ttl = 43200 ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->github_repo     = $github_repo;
		$this->cache_ttl       = $cache_ttl;

		// Read current version from plugin header.
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugin_data           = get_plugin_data( $plugin_file, false, false );
		$this->current_version = $plugin_data['Version'] ?? '0.0.0';

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Check whether GitHub auto-updates are enabled for this plugin.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		/**
		 * Filter whether GitHub auto-updates are enabled for a plugin.
		 *
		 * Return false to disable update checks for the given plugin slug.
		 * Useful for staging environments, local development, or temporarily
		 * pinning a plugin to its current version.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $enabled     Whether auto-updates are enabled. Default true.
		 * @param string $plugin_slug The plugin directory name (e.g. "vulnz-agent").
		 * @param string $github_repo The GitHub repo in "owner/repo" format.
		 */
		return (bool) apply_filters( 'headwall_github_updater_enabled', true, $this->plugin_slug, $this->github_repo ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Shared hook across plugins.
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * @since 1.0.0
	 *
	 * @param object $transient The update_plugins transient object.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		$checked = is_object( $transient ) && property_exists( $transient, 'checked' ) ? $transient->checked : false;

		if ( empty( $checked ) ) {
			// Early transient pass — WordPress hasn't populated checked list yet.
			$this->log( 'check_for_update: transient has no checked list, skipping.' );
		} elseif ( ! $this->is_enabled() ) {
			$this->log( 'check_for_update: updates disabled via filter, skipping.' );
		} else {
			$release = $this->get_latest_release();

			if ( ! is_array( $release ) ) {
				$this->log( 'check_for_update: no release data returned from GitHub.' );
			} elseif ( version_compare( $this->current_version, $release['version'], '>=' ) ) {
				$this->log( 'check_for_update: current version ' . $this->current_version . ' is up to date (latest: ' . $release['version'] . ').' );
			} else {
				$this->log( 'check_for_update: update available ' . $this->current_version . ' → ' . $release['version'] . '.' );
				$transient->response[ $this->plugin_basename ] = (object) array(
					'slug'        => $this->plugin_slug,
					'plugin'      => $this->plugin_basename,
					'new_version' => $release['version'],
					'url'         => $release['html_url'],
					'package'     => $release['zip_url'],
				);
			}
		}

		return $transient;
	}

	/**
	 * Provide plugin information for the "View details" modal.
	 *
	 * @since 1.0.0
	 *
	 * @param false|object|array $result The result object or array. Default false.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->plugin_slug || ! $this->is_enabled() ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( is_array( $release ) ) {
			$plugin_data = get_plugin_data( $this->plugin_file, false, true );

			$result                = new \stdClass();
			$result->name          = $plugin_data['Name'] ?? $this->plugin_slug;
			$result->slug          = $this->plugin_slug;
			$result->version       = $release['version'];
			$result->author        = $plugin_data['AuthorName'] ?? '';
			$result->homepage      = $plugin_data['PluginURI'] ?? $release['html_url'];
			$result->requires      = $plugin_data['RequiresWP'] ?? '';
			$result->requires_php  = $plugin_data['RequiresPHP'] ?? '';
			$result->downloaded    = 0;
			$result->last_updated  = $release['published_at'] ?? '';
			$result->download_link = $release['zip_url'];

			if ( ! empty( $release['body'] ) ) {
				$result->sections = array(
					'description' => $plugin_data['Description'] ?? '',
					'changelog'   => wp_kses_post( wpautop( $release['body'] ) ),
				);
			}
		}

		return $result;
	}

	/**
	 * Clear the cached release data after a plugin update completes.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Upgrader $upgrader The upgrader instance.
	 * @param array        $options  Update details.
	 */
	public function clear_cache( $upgrader, $options ): void {
		if (
			'update' === ( $options['action'] ?? '' ) &&
			'plugin' === ( $options['type'] ?? '' ) &&
			! empty( $options['plugins'] ) &&
			in_array( $this->plugin_basename, $options['plugins'], true )
		) {
			delete_transient( $this->get_cache_key() );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release from GitHub, with transient caching.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Release data array, or null on failure.
	 */
	private function get_latest_release(): ?array {
		$release = null;

		$cache_key = $this->get_cache_key();
		$cached    = get_transient( $cache_key );

		if ( is_array( $cached ) ) {
			$this->log( 'get_latest_release: using cached release data.' );
			$release = $cached;
		} else {
			$url      = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->github_repo );
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/vnd.github.v3+json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				$this->log( 'get_latest_release: HTTP request failed — ' . $response->get_error_message() );
			} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
				$this->log( 'get_latest_release: GitHub returned HTTP ' . wp_remote_retrieve_response_code( $response ) . '.' );
			} else {
				$body = json_decode( wp_remote_retrieve_body( $response ), true );

				if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
					$this->log( 'get_latest_release: response JSON missing tag_name.' );
				} else {
					$zip_url = $this->find_zip_asset( $body );

					if ( empty( $zip_url ) ) {
						$this->log( 'get_latest_release: no matching .zip asset for tag ' . $body['tag_name'] . '.' );
					} else {
						$this->log( 'get_latest_release: found release ' . $body['tag_name'] . '.' );

						$release = array(
							'version'      => ltrim( $body['tag_name'], 'v' ),
							'zip_url'      => $zip_url,
							'html_url'     => $body['html_url'] ?? '',
							'body'         => $body['body'] ?? '',
							'published_at' => $body['published_at'] ?? '',
						);

						set_transient( $cache_key, $release, $this->cache_ttl );
					}
				}
			}
		}

		return $release;
	}

	/**
	 * Find the plugin ZIP asset from a GitHub release.
	 *
	 * Looks for a .zip asset whose name matches the plugin slug
	 * (e.g. "vulnz-agent.zip" or "vulnz-agent-2.2.1.zip").
	 * Prefers the stable "{slug}.zip" over a versioned match.
	 *
	 * @since 1.0.0
	 *
	 * @param array $release_data Decoded GitHub release API response.
	 * @return string Download URL, or empty string if no suitable asset found.
	 */
	private function find_zip_asset( array $release_data ): string {
		$zip_url = '';

		if ( ! empty( $release_data['assets'] ) && is_array( $release_data['assets'] ) ) {
			$stable_name = $this->plugin_slug . '.zip';

			foreach ( $release_data['assets'] as $asset ) {
				$name = $asset['name'] ?? '';

				if ( $stable_name === $name ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}

				// Accept any zip starting with the plugin slug as a fallback.
				if ( empty( $zip_url ) && str_starts_with( $name, $this->plugin_slug ) && str_ends_with( $name, '.zip' ) ) {
					$zip_url = $asset['browser_download_url'] ?? '';
				}
			}
		}

		return $zip_url;
	}

	/**
	 * Get the transient cache key for this plugin's release data.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_cache_key(): string {
		return 'headwall_ghu_' . md5( $this->github_repo );
	}

	/**
	 * Log a message to the PHP error log, prefixed with the plugin slug.
	 *
	 * @since 1.1.0
	 *
	 * @param string $message The message to log.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'HW_GitHub_Updater [' . $this->plugin_slug . ']: ' . $message );  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
		}
	}
}
