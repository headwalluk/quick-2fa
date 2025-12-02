<?php
/**
 * Plugin Name: Quick 2FA
 * Plugin URI: https://github.com/headwalluk/quick-2fa
 * Description: Lightweight email-based two-factor authentication for WordPress admin access.
 * Version: 0.3.0
 * Requires at least: 6.0
 * Requires PHP: 8.2
 * Author: Paul Faulkner
 * Author URI: https://power-plugins.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: quick-2fa
 * Domain Path: /languages
 *
 * @package Quick_2FA
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

// Define plugin constants.
define( 'QUICK_2FA_VERSION', '0.3.0' );
define( 'QUICK_2FA_FILE', __FILE__ );
define( 'QUICK_2FA_PATH', plugin_dir_path( __FILE__ ) );
define( 'QUICK_2FA_URL', plugin_dir_url( __FILE__ ) );
define( 'QUICK_2FA_BASENAME', plugin_basename( __FILE__ ) );

// Load plugin constants.
require_once QUICK_2FA_PATH . 'constants.php';

// Load global functions.
require_once QUICK_2FA_PATH . 'functions.php';

// Load private/helper functions.
require_once QUICK_2FA_PATH . 'functions-private.php';

// Load main plugin class.
require_once QUICK_2FA_PATH . 'includes/class-plugin.php';

// Load settings class.
require_once QUICK_2FA_PATH . 'includes/class-settings.php';

/**
 * Activation hook.
 *
 * @since 1.0.0
 */
function quick_2fa_activate() {
	// Set default options if not already set.
	$defaults = quick_2fa_get_default_settings();

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}

	// Store plugin version.
	update_option( 'quick2fa_version', QUICK_2FA_VERSION );
}
register_activation_hook( __FILE__, 'quick_2fa_activate' );

/**
 * Deactivation hook.
 *
 * @since 1.0.0
 */
function quick_2fa_deactivate() {
	// Clear temporary transients.
	global $wpdb;
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
function quick_2fa_run() {
	$plugin = Quick_2FA\Plugin::instance();
	$plugin->run();

	$settings = new Quick_2FA\Settings();
	$settings->run();
}
quick_2fa_run();
