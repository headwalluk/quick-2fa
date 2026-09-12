<?php
/**
 * GitHub Updater.
 *
 * Hooks into the WordPress plugin update system to check the configured
 * GitHub repository for new releases and serve them as standard plugin
 * updates.
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Checks GitHub Releases for plugin updates and hooks into the
 * WordPress plugin update system.
 *
 * @since 1.0.0
 */
class Github_Updater {

	/**
	 * Plugin basename (e.g. "quick-2fa/quick-2fa.php").
	 *
	 * @var string
	 */
	private string $plugin_basename;

	/**
	 * Plugin slug (directory name).
	 *
	 * @var string
	 */
	private string $plugin_slug;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->plugin_basename = QUICK_2FA_BASENAME;
		$this->plugin_slug     = dirname( $this->plugin_basename );

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Check whether GitHub auto-updates are enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		/**
		 * Filter whether GitHub auto-updates are enabled for Quick 2FA.
		 *
		 * Return false to disable update checks. Useful for staging
		 * environments, local development, or temporarily pinning the
		 * plugin to its current version.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $enabled Whether auto-updates are enabled. Default true.
		 */
		return (bool) apply_filters( 'quick_2fa_updater_enabled', true );
	}

	/**
	 * Check GitHub for a newer release and inject into the update transient.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $transient The update_plugins transient — an object once core
	 *                          has built it, but false or empty on early passes.
	 * @return mixed The transient, unchanged unless an update was injected.
	 */
	public function check_for_update( $transient ) {
		$checked = is_object( $transient ) && property_exists( $transient, 'checked' ) ? $transient->checked : false;

		if ( empty( $checked ) ) {
			// Early transient pass — WordPress hasn't populated checked list yet.
			$this->log( 'check_for_update: transient has no checked list, skipping.' );
		} elseif ( ! $this->is_enabled() ) {
			$this->log( 'check_for_update: updates disabled via filter, skipping.' );
		} else {
			$installed_version = $this->get_installed_version( $checked );
			$release           = $this->get_latest_release();

			if ( ! is_array( $release ) ) {
				$this->log( 'check_for_update: no release data returned from GitHub.' );
			} elseif ( version_compare( $installed_version, $release['version'], '>=' ) ) {
				$this->log( 'check_for_update: current version ' . $installed_version . ' is up to date (latest: ' . $release['version'] . ').' );
			} else {
				$this->log( 'check_for_update: update available ' . $installed_version . ' → ' . $release['version'] . '.' );
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
	 * Resolve the installed version that WordPress itself is working from.
	 *
	 * WordPress builds the transient's checked list from the plugin file
	 * header, and that is the number it shows in the admin and compares
	 * against new_version to decide whether to draw the update row. Comparing
	 * anything else here — such as the hand-maintained QUICK_2FA_VERSION —
	 * lets a stale constant advertise an update for a release that is already
	 * installed, which the update can never clear because reinstalling ships
	 * the same stale constant again.
	 *
	 * @since 1.3.0
	 *
	 * @param array $checked The transient's checked list, keyed by plugin basename.
	 * @return string Version string WordPress considers installed.
	 */
	private function get_installed_version( array $checked ): string {
		$installed_version = $checked[ $this->plugin_basename ] ?? '';

		if ( '' === $installed_version ) {
			$installed_version = QUICK_2FA_VERSION;
			$this->log( 'get_installed_version: ' . $this->plugin_basename . ' absent from the checked list, falling back to QUICK_2FA_VERSION ' . $installed_version . '.' );
		} elseif ( QUICK_2FA_VERSION !== $installed_version ) {
			$this->log_error( 'get_installed_version: version drift — the plugin header reports ' . $installed_version . ' but QUICK_2FA_VERSION is ' . QUICK_2FA_VERSION . '. Both are set in quick-2fa.php and must be bumped together.' );
		}

		return $installed_version;
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
		$requested_slug = is_object( $args ) ? ( $args->slug ?? '' ) : '';

		if ( 'plugin_information' !== $action || $requested_slug !== $this->plugin_slug || ! $this->is_enabled() ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( is_array( $release ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$plugin_data = get_plugin_data( QUICK_2FA_FILE, false, true );

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
			delete_transient( UPDATER_CACHE_KEY );
			delete_transient( UPDATER_FAILURE_CACHE_KEY );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release from GitHub, with transient caching.
	 *
	 * Three states are cached rather than two: a successful lookup for
	 * UPDATER_CACHE_TTL, and a failed one for the shorter
	 * UPDATER_FAILURE_CACHE_TTL. Without the second, an unreachable or
	 * rate-limiting API meant every update check made the request again and
	 * blocked the admin page for the full timeout each time.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Release data array, or null on failure.
	 */
	private function get_latest_release(): ?array {
		$release = null;
		$cached  = get_transient( UPDATER_CACHE_KEY );

		if ( is_array( $cached ) ) {
			$this->log( 'get_latest_release: using cached release data.' );
			$release = $cached;
		} elseif ( false !== get_transient( UPDATER_FAILURE_CACHE_KEY ) ) {
			$this->log( 'get_latest_release: backing off, a recent lookup failed.' );
		} else {
			$body = $this->request_latest_release();

			if ( is_array( $body ) ) {
				$release = $this->build_release( $body );
			}

			if ( null === $release ) {
				set_transient( UPDATER_FAILURE_CACHE_KEY, time(), UPDATER_FAILURE_CACHE_TTL );
			} else {
				set_transient( UPDATER_CACHE_KEY, $release, UPDATER_CACHE_TTL );
				delete_transient( UPDATER_FAILURE_CACHE_KEY );
			}
		}

		return $release;
	}

	/**
	 * Request the latest release from the GitHub API.
	 *
	 * Every failure path logs through log_error(), so a sysadmin diagnosing
	 * updates that are not arriving sees the reason without enabling WP_DEBUG.
	 *
	 * @since 1.3.0
	 *
	 * @return array|null Decoded response body, or null if the request failed.
	 */
	private function request_latest_release(): ?array {
		$body = null;
		$url  = sprintf( 'https://api.github.com/repos/%s/releases/latest', UPDATER_GITHUB_REPO );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => UPDATER_REQUEST_TIMEOUT,
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'request_latest_release: HTTP request to ' . $url . ' failed — ' . $response->get_error_message() );
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->log_error( 'request_latest_release: GitHub returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for ' . $url . '.' );
		} else {
			$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $decoded ) || empty( $decoded['tag_name'] ) ) {
				$this->log_error( 'request_latest_release: response JSON from ' . $url . ' missing tag_name.' );
			} else {
				$body = $decoded;
			}
		}

		return $body;
	}

	/**
	 * Build the cached release array from a GitHub API response body.
	 *
	 * @since 1.3.0
	 *
	 * @param array $body Decoded GitHub release API response.
	 * @return array|null Release data, or null if it carries no usable ZIP asset.
	 */
	private function build_release( array $body ): ?array {
		$release = null;
		$zip_url = $this->find_zip_asset( $body );

		if ( '' === $zip_url ) {
			$this->log_error( 'build_release: no matching .zip asset for tag ' . $body['tag_name'] . '.' );
		} else {
			$this->log( 'build_release: found release ' . $body['tag_name'] . '.' );

			$release = array(
				'version'      => ltrim( (string) $body['tag_name'], 'v' ),
				'zip_url'      => $zip_url,
				'html_url'     => $body['html_url'] ?? '',
				'body'         => $body['body'] ?? '',
				'published_at' => $body['published_at'] ?? '',
			);
		}

		return $release;
	}

	/**
	 * Find the plugin ZIP asset from a GitHub release.
	 *
	 * Looks for a .zip asset whose name matches the plugin slug
	 * (e.g. "quick-2fa.zip" or "quick-2fa-1.0.0.zip").
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

			// Versioned form: "<slug>-1.2.3.zip". Anchored and requiring a digit
			// after the hyphen so an unrelated asset such as "<slug>-docs.zip"
			// is never offered to WordPress as the plugin.
			$versioned_pattern = '/^' . preg_quote( $this->plugin_slug, '/' ) . '-[0-9][0-9a-z.\-]*\.zip$/i';

			foreach ( $release_data['assets'] as $asset ) {
				$name = $asset['name'] ?? '';

				if ( $stable_name === $name ) {
					$zip_url = $asset['browser_download_url'] ?? '';
					break;
				}

				if ( '' === $zip_url && 1 === preg_match( $versioned_pattern, $name ) ) {
					$zip_url = $asset['browser_download_url'] ?? '';
				}
			}
		}

		return $zip_url;
	}

	/**
	 * Log a debug message to the PHP error log when WP_DEBUG is on.
	 *
	 * For routine flow tracing — cache hits, version comparisons, "up to date"
	 * results. Use log_error() for actual failures that warrant investigation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The message to log.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Quick_2FA Github_Updater: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
		}
	}

	/**
	 * Log an error message to the PHP error log unconditionally.
	 *
	 * Used for genuine failure conditions (HTTP errors, malformed responses,
	 * missing release assets) that should always be visible to a sysadmin
	 * diagnosing why updates aren't flowing — without requiring WP_DEBUG.
	 *
	 * @since 1.1.2
	 *
	 * @param string $message The message to log.
	 */
	private function log_error( string $message ): void {
		error_log( 'Quick_2FA Github_Updater [error]: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for updater failures.
	}
}
