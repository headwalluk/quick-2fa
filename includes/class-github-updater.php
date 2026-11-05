<?php
/**
 * GitHub Updater.
 *
 * Serves GitHub Releases as standard WordPress plugin updates. Portable: copy
 * this file into another plugin and change only the `namespace` and `@package`
 * lines, then load it from the main plugin file on admin, cron and WP-CLI requests:
 *
 *     new My_Plugin\Github_Updater( __FILE__, 'owner/my-plugin', MY_PLUGIN_VERSION, 'my_plugin_updater_enabled' );
 *
 * Each release must attach "<slug>.zip" or "<slug>-<version>.zip".
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
	 * Seconds to cache a successful release lookup.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * Seconds to back off after a failed lookup. Shorter than CACHE_TTL so a
	 * real release is not missed for long.
	 *
	 * @var int
	 */
	private const FAILURE_CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Seconds to wait on the GitHub API before giving up.
	 *
	 * @var int
	 */
	private const REQUEST_TIMEOUT = 10;

	/**
	 * Absolute path to the main plugin file.
	 *
	 * @var string
	 */
	private string $plugin_file;

	/**
	 * Plugin basename (e.g. "my-plugin/my-plugin.php").
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
	 * GitHub repository in "owner/repo" format.
	 *
	 * @var string
	 */
	private string $github_repo;

	/**
	 * The plugin's version constant, checked against the file header.
	 *
	 * @var string
	 */
	private string $plugin_version;

	/**
	 * Name of the filter that can switch this updater off.
	 *
	 * @var string
	 */
	private string $enabled_filter;

	/**
	 * Store the plugin's details and register the update hooks.
	 *
	 * @since 1.0.0
	 * @since 1.6.1 Takes the plugin's details as arguments.
	 *
	 * @param string $plugin_file    Absolute path to the main plugin file.
	 * @param string $github_repo    GitHub repository in "owner/repo" format.
	 * @param string $plugin_version The plugin's version constant.
	 * @param string $enabled_filter Filter name that returns false to disable updates, e.g. "my_plugin_updater_enabled".
	 */
	public function __construct( string $plugin_file, string $github_repo, string $plugin_version, string $enabled_filter ) {
		$this->plugin_file     = $plugin_file;
		$this->plugin_basename = plugin_basename( $plugin_file );
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->github_repo     = $github_repo;
		$this->plugin_version  = $plugin_version;
		$this->enabled_filter  = $enabled_filter;

		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );
	}

	/**
	 * Delete a plugin's cached release data and failure back-off.
	 *
	 * @since 1.6.1
	 *
	 * @param string $plugin_slug Plugin directory name.
	 */
	public static function delete_cache( string $plugin_slug ): void {
		delete_transient( self::get_cache_key( $plugin_slug, 'release' ) );
		delete_transient( self::get_cache_key( $plugin_slug, 'failed' ) );
	}

	/**
	 * Build a per-plugin transient name, e.g. "my_plugin_github_release".
	 *
	 * @since 1.6.1
	 *
	 * @param string $plugin_slug Plugin directory name.
	 * @param string $suffix      "release" or "failed".
	 * @return string Transient name.
	 */
	private static function get_cache_key( string $plugin_slug, string $suffix ): string {
		return str_replace( '-', '_', $plugin_slug ) . '_github_' . $suffix;
	}

	/**
	 * Check whether GitHub auto-updates are enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The plugin passes its own prefixed filter name to the constructor.
		return (bool) filter_var( apply_filters( $this->enabled_filter, true ), FILTER_VALIDATE_BOOLEAN );
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
	public function check_for_update( mixed $transient ): mixed {
		$checked = is_object( $transient ) && property_exists( $transient, 'checked' ) ? $transient->checked : false;

		if ( empty( $checked ) || ! is_array( $checked ) ) {
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
	 * Resolve the installed version from the file header, as recorded in the update transient.
	 *
	 * Reads the header, not the version constant, and logs an error when they differ.
	 *
	 * @since 1.3.0
	 *
	 * @param array $checked The transient's checked list, keyed by plugin basename.
	 * @return string Version string WordPress considers installed.
	 */
	private function get_installed_version( array $checked ): string {
		$installed_version = (string) ( $checked[ $this->plugin_basename ] ?? '' );

		if ( '' === $installed_version ) {
			$installed_version = $this->plugin_version;
			$this->log( 'get_installed_version: ' . $this->plugin_basename . ' absent from the checked list, falling back to the version constant ' . $installed_version . '.' );
		} elseif ( $this->plugin_version !== $installed_version ) {
			$this->log_error( 'get_installed_version: version drift — the ' . $this->plugin_basename . ' header reports ' . $installed_version . ' but its version constant is ' . $this->plugin_version . '. Both must be bumped together.' );
		} else {
			// Header and constant agree.
		}

		return $installed_version;
	}

	/**
	 * Provide plugin information for the "View details" modal.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $result The result object or array. Default false.
	 * @param mixed $action The API action being performed.
	 * @param mixed $args   Plugin API arguments.
	 * @return mixed Our plugin information, or $result unchanged for any other request.
	 */
	public function plugin_info( mixed $result, mixed $action, mixed $args ): mixed {
		$requested_slug = is_object( $args ) ? ( $args->slug ?? '' ) : '';

		if ( 'plugin_information' !== $action || $requested_slug !== $this->plugin_slug || ! $this->is_enabled() ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( is_array( $release ) ) {
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
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
	 * @param mixed $upgrader The upgrader instance.
	 * @param mixed $options  Update details.
	 */
	public function clear_cache( mixed $upgrader, mixed $options ): void {
		if (
			is_array( $options ) &&
			'update' === ( $options['action'] ?? '' ) &&
			'plugin' === ( $options['type'] ?? '' ) &&
			! empty( $options['plugins'] ) &&
			is_array( $options['plugins'] ) &&
			in_array( $this->plugin_basename, $options['plugins'], true )
		) {
			self::delete_cache( $this->plugin_slug );
			delete_site_transient( 'update_plugins' );
		}
	}

	/**
	 * Fetch the latest release from GitHub, caching success and failure separately.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Release data array, or null on failure.
	 */
	private function get_latest_release(): ?array {
		$release     = null;
		$release_key = self::get_cache_key( $this->plugin_slug, 'release' );
		$failure_key = self::get_cache_key( $this->plugin_slug, 'failed' );
		$cached      = get_transient( $release_key );

		if ( is_array( $cached ) ) {
			$this->log( 'get_latest_release: using cached release data.' );
			$release = $cached;
		} elseif ( false !== get_transient( $failure_key ) ) {
			$this->log( 'get_latest_release: backing off, a recent lookup failed.' );
		} else {
			$body = $this->request_latest_release();

			if ( is_array( $body ) ) {
				$release = $this->build_release( $body );
			}

			if ( null === $release ) {
				set_transient( $failure_key, time(), self::FAILURE_CACHE_TTL );
			} else {
				set_transient( $release_key, $release, self::CACHE_TTL );
				delete_transient( $failure_key );
			}
		}

		return $release;
	}

	/**
	 * Request the latest release from the GitHub API, logging every failure.
	 *
	 * @since 1.3.0
	 *
	 * @return array|null Decoded response body, or null if the request failed.
	 */
	private function request_latest_release(): ?array {
		$body = null;
		$url  = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->github_repo );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => self::REQUEST_TIMEOUT,
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
	 * Find the release's "{slug}.zip" asset, or failing that "{slug}-{version}.zip".
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

			// Requires a digit after the hyphen so "<slug>-docs.zip" never matches.
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
	 * Log a flow-tracing message to the PHP error log when WP_DEBUG is on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message The message to log.
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( __NAMESPACE__ . ' Github_Updater: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional debug logging.
		}
	}

	/**
	 * Log a failure to the PHP error log unconditionally.
	 *
	 * @since 1.1.2
	 *
	 * @param string $message The message to log.
	 */
	private function log_error( string $message ): void {
		error_log( __NAMESPACE__ . ' Github_Updater [error]: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional error logging for updater failures.
	}
}
