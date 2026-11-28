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
 * Each release must attach "<slug>.zip" or "<slug>-<version>.zip", and the main
 * plugin file must sit at the repository root, where its header is read at the tag.
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
	 * Bytes of the released main file read for its header, as get_file_data() does.
	 *
	 * @var int
	 */
	private const HEADER_BYTES = 8 * KB_IN_BYTES;

	/**
	 * User agent sent to GitHub, in place of core's, which names the site's URL.
	 *
	 * @var string
	 */
	private const USER_AGENT = 'WordPress';

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
	 * Whether this request has replaced the plugin's files, leaving the old version constant in memory.
	 *
	 * @var bool
	 */
	private bool $has_updated_this_request = false;

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
		add_filter( 'site_transient_update_plugins', array( $this, 'withdraw_offer_when_disabled' ) );
		add_filter( 'auto_update_plugin', array( $this, 'block_incompatible_auto_update' ), PHP_INT_MAX, 2 );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_post_install', array( $this, 'note_files_replaced' ), 10, 2 );
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
					'slug'         => $this->plugin_slug,
					'plugin'       => $this->plugin_basename,
					'new_version'  => $release['version'],
					'url'          => $release['html_url'],
					'package'      => $release['zip_url'],
					'requires'     => $release['requires'],
					'requires_php' => $release['requires_php'],
				);
			}
		}

		return $transient;
	}

	/**
	 * Hide this plugin's stored update offer while the updater is disabled.
	 *
	 * @since 1.8.0
	 *
	 * @param mixed $transient The update_plugins transient as read.
	 * @return mixed The transient, without this plugin's offer when disabled.
	 */
	public function withdraw_offer_when_disabled( mixed $transient ): mixed {
		// An offer stored before the filter was switched off survives until the next check, up to 12 hours.
		if (
			is_object( $transient ) &&
			isset( $transient->response ) &&
			is_array( $transient->response ) &&
			isset( $transient->response[ $this->plugin_basename ] ) &&
			! $this->is_enabled()
		) {
			unset( $transient->response[ $this->plugin_basename ] );
		}

		return $transient;
	}

	/**
	 * Refuse a background auto-update whose release needs a newer WordPress or PHP.
	 *
	 * @since 1.8.0
	 *
	 * @param mixed $update Whether to auto-update, or null to defer to the site's setting.
	 * @param mixed $item   The update offer.
	 * @return mixed False for this plugin's incompatible offer, otherwise $update unchanged.
	 */
	public function block_incompatible_auto_update( mixed $update, mixed $item ): mixed {
		// Core's automatic updater checks only requires_php, and its single upgrade() skips the WordPress check.
		$item_basename = is_object( $item ) ? ( $item->plugin ?? '' ) : '';

		if ( $item_basename === $this->plugin_basename ) {
			$requires     = is_string( $item->requires ?? null ) ? $item->requires : '';
			$requires_php = is_string( $item->requires_php ?? null ) ? $item->requires_php : '';

			if ( ! is_wp_version_compatible( $requires ) || ! is_php_version_compatible( $requires_php ) ) {
				$this->log( 'block_incompatible_auto_update: release needs WordPress ' . $requires . ', PHP ' . $requires_php . '; auto-update refused.' );
				$update = false;
			}
		}

		return $update;
	}

	/**
	 * Resolve the installed version from the file header, as recorded in the update transient.
	 *
	 * Reads the header, not the version constant, and logs an error when they differ,
	 * unless this request has just replaced the plugin's files.
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
		} elseif ( $this->plugin_version !== $installed_version && $this->has_updated_this_request ) {
			// The new header is on disk but the old constant is still loaded until the next request.
			$this->log( 'get_installed_version: header reports ' . $installed_version . ', constant ' . $this->plugin_version . '; expected straight after an update.' );
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
			$result->requires      = $release['requires'];
			$result->requires_php  = $release['requires_php'];
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
	 * Record that this request has replaced the plugin's files.
	 *
	 * @since 1.9.1
	 *
	 * @param mixed $response   Installation response, passed through unchanged.
	 * @param mixed $hook_extra Extra arguments for the install, naming the plugin.
	 * @return mixed The response, unchanged.
	 */
	public function note_files_replaced( mixed $response, mixed $hook_extra ): mixed {
		if ( is_array( $hook_extra ) && ( $hook_extra['plugin'] ?? '' ) === $this->plugin_basename && ! is_wp_error( $response ) ) {
			$this->has_updated_this_request = true;
		}

		return $response;
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

		// A cache entry from a version that didn't record requirements counts as a miss.
		if ( is_array( $cached ) && array_key_exists( 'requires_php', $cached ) ) {
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
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => self::USER_AGENT,
				'headers'    => array(
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
	 * @return array|null Release data, or null if it has no usable ZIP asset or readable header.
	 */
	private function build_release( array $body ): ?array {
		$release     = null;
		$tag_name    = (string) $body['tag_name'];
		$tag_version = ltrim( $tag_name, 'v' );
		$zip_url     = $this->find_zip_asset( $body );
		$header      = '' === $zip_url ? null : $this->request_release_header( $tag_name );

		if ( '' === $zip_url ) {
			$this->log_error( 'build_release: no matching .zip asset for tag ' . $tag_name . '.' );
		} elseif ( null === $header ) {
			// request_release_header() has logged why.
		} elseif ( $tag_version !== $header['version'] ) {
			$this->log_error( 'build_release: tag ' . $tag_name . ' does not match the released header Version ' . $header['version'] . '.' );
		} else {
			$this->log( 'build_release: found release ' . $tag_name . ' (requires WordPress ' . $header['requires'] . ', PHP ' . $header['requires_php'] . ').' );

			$release = array(
				'version'      => $tag_version,
				'zip_url'      => $zip_url,
				'html_url'     => $body['html_url'] ?? '',
				'body'         => $body['body'] ?? '',
				'published_at' => $body['published_at'] ?? '',
				'requires'     => $header['requires'],
				'requires_php' => $header['requires_php'],
			);
		}

		return $release;
	}

	/**
	 * Read the main plugin file's header at a release tag, logging every failure.
	 *
	 * @since 1.8.0
	 *
	 * @param string $tag_name The release's git tag.
	 * @return array|null Header fields (version, requires, requires_php), or null on failure.
	 */
	private function request_release_header( string $tag_name ): ?array {
		$header = null;
		$url    = sprintf(
			'https://raw.githubusercontent.com/%s/%s/%s',
			$this->github_repo,
			rawurlencode( $tag_name ),
			rawurlencode( basename( $this->plugin_file ) )
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => self::REQUEST_TIMEOUT,
				'user-agent'          => self::USER_AGENT,
				'limit_response_size' => self::HEADER_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_error( 'request_release_header: HTTP request to ' . $url . ' failed — ' . $response->get_error_message() );
		} elseif ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			$this->log_error( 'request_release_header: GitHub returned HTTP ' . wp_remote_retrieve_response_code( $response ) . ' for ' . $url . '.' );
		} else {
			$header = $this->parse_plugin_header( wp_remote_retrieve_body( $response ) );

			if ( '' === $header['version'] ) {
				$this->log_error( 'request_release_header: no Version header in ' . $url . '.' );
				$header = null;
			}
		}

		return $header;
	}

	/**
	 * Extract the version and requirement fields from a plugin file's header comment.
	 *
	 * @since 1.8.0
	 *
	 * @param string $contents The start of the main plugin file.
	 * @return array{version: string, requires: string, requires_php: string} Each field, or '' when absent.
	 */
	private function parse_plugin_header( string $contents ): array {
		$header_names = array(
			'version'      => 'Version',
			'requires'     => 'Requires at least',
			'requires_php' => 'Requires PHP',
		);
		$header       = array();
		$contents     = str_replace( "\r", "\n", substr( $contents, 0, self::HEADER_BYTES ) );

		foreach ( $header_names as $field => $header_name ) {
			// The same pattern and comment clean-up as core's get_file_data().
			$pattern          = '/^(?:[ \t]*<\?(?:php)?)?[ \t\/*#@]*' . preg_quote( $header_name, '/' ) . ':(.*)$/mi';
			$header[ $field ] = 1 === preg_match( $pattern, $contents, $matches )
				? trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $matches[1] ) )
				: '';
		}

		return $header;
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
