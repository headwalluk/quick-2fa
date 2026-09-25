<?php
/**
 * Quick 2FA — Uninstaller
 *
 * Runs when the plugin is deleted from the Plugins screen, not on deactivation.
 * Deletes nothing unless the "Delete all plugin data when uninstalled" setting
 * is on; see docs/how-it-works.md for what is kept and why.
 *
 * @package Quick_2FA
 */

namespace Quick_2FA;

// Bail out if not invoked by WordPress's uninstall handler.
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

require_once __DIR__ . '/constants.php';

$quick_2fa_delete_data = (bool) filter_var(
	get_option( OPTION_DELETE_DATA_ON_UNINSTALL, DEFAULT_DELETE_DATA_ON_UNINSTALL ),
	FILTER_VALIDATE_BOOLEAN
);

if ( $quick_2fa_delete_data ) {
	global $wpdb;

	// Keys are found by prefix so data from older versions goes too; each is then
	// deleted through the WordPress API so object caches are cleared with it.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off prefix lookup at uninstall; there is no API for it.
	$quick_2fa_meta_keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( META_PREFIX ) . '%'
		)
	);

	$quick_2fa_option_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( OPTION_PREFIX ) . '%'
		)
	);

	// Under a persistent object cache, transients never reach the options table;
	// these per-user ones then expire on their own within 15 minutes.
	$quick_2fa_transient_names = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . TRANSIENT_RETURN_URL ) . '%'
		)
	);

	$quick_2fa_transient_names = array_merge(
		$quick_2fa_transient_names,
		$wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . TRANSIENT_RATE_LIMIT ) . '%'
			)
		)
	);
	// phpcs:enable

	// META_PASSWORD_LAST_CHANGED doesn't carry the plugin prefix.
	$quick_2fa_meta_keys[] = META_PASSWORD_LAST_CHANGED;

	foreach ( $quick_2fa_meta_keys as $quick_2fa_meta_key ) {
		delete_metadata( 'user', 0, $quick_2fa_meta_key, '', true );
	}

	foreach ( $quick_2fa_option_names as $quick_2fa_option_name ) {
		delete_option( $quick_2fa_option_name );
	}

	foreach ( $quick_2fa_transient_names as $quick_2fa_transient_name ) {
		delete_transient( substr( $quick_2fa_transient_name, strlen( '_transient_' ) ) );
	}

	delete_transient( TRANSIENT_LOCKED_COUNT );
	delete_transient( UPDATER_CACHE_KEY );
	delete_transient( UPDATER_FAILURE_CACHE_KEY );
}
