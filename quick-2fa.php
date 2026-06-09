<?php
/**
 * Plugin Name: Quick 2FA
 * Plugin URI: https://github.com/headwalluk/quick-2fa
 * Description: Lightweight email-based two-factor authentication for WordPress admin access.
 * Version: 1.1.4
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Paul Faulkner
 * Author URI: https://headwall-hosting.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: quick-2fa
 * Domain Path: /languages
 *
 * @package Quick_2FA
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

define( 'QUICK_2FA_VERSION', '1.1.4' );
define( 'QUICK_2FA_FILE', __FILE__ );
define( 'QUICK_2FA_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUICK_2FA_URL', plugin_dir_url( __FILE__ ) );
define( 'QUICK_2FA_BASENAME', plugin_basename( __FILE__ ) );

require_once QUICK_2FA_PATH . 'constants.php';
require_once QUICK_2FA_PATH . 'functions-private.php';

require_once QUICK_2FA_PATH . 'includes/class-account-security-handler.php';
require_once QUICK_2FA_PATH . 'includes/class-email-handler.php';
require_once QUICK_2FA_PATH . 'includes/class-verification-code-handler.php';
require_once QUICK_2FA_PATH . 'includes/class-password-reminder-handler.php';
require_once QUICK_2FA_PATH . 'includes/class-user-management.php';
require_once QUICK_2FA_PATH . 'includes/class-plugin.php';
require_once QUICK_2FA_PATH . 'includes/class-settings.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once QUICK_2FA_PATH . 'includes/class-cli-commands.php';
	WP_CLI::add_command( 'quick-2fa', 'Quick_2FA\CLI_Commands' );
}

// GitHub auto-updates (admin + cron only — no need to load on front-end requests).
if ( is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
	require_once QUICK_2FA_PATH . 'includes/class-github-updater.php';
	new Quick_2FA\Github_Updater();
}

/**
 * Activation hook.
 *
 * Note: This only runs when installed as a regular plugin.
 * For MU plugin installations, defaults are set via Plugin::check_first_run().
 *
 * @since 1.0.0
 */
function quick_2fa_activate(): void {
	$defaults = Quick_2FA\get_default_settings();

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value, '', 'yes' ); // Autoload enabled.
		}
	}

	add_option( Quick_2FA\OPTION_VERSION, QUICK_2FA_VERSION, '', 'yes' );
}
register_activation_hook( __FILE__, 'quick_2fa_activate' );

/**
 * Clear temporary transients and tidy up.
 *
 * @since 1.0.0
 */
function quick_2fa_deactivate(): void {
	global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options} 
		 WHERE option_name LIKE '_transient_q2fa_%' 
		 OR option_name LIKE '_transient_timeout_q2fa_%'"
	);
}
register_deactivation_hook( __FILE__, 'quick_2fa_deactivate' );

/**
 * Initialize the plugin.
 *
 * @since 1.0.0
 */
function quick_2fa_run(): void {
	$plugin = Quick_2FA\Plugin::instance();
	$plugin->run();
}
quick_2fa_run();
