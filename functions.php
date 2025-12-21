<?php
/**
 * Plugin Functions
 *
 * All plugin functions (utility, settings, and helpers).
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

// ============================================================================
// Settings & Configuration Functions
// ============================================================================

/**
 * Get default plugin settings.
 *
 * @since 1.0.0
 * @return array Default settings.
 */
function get_default_settings(): array {
	global $q2fa_default_settings;

	if ( is_null( $q2fa_default_settings ) ) {
		$q2fa_default_settings = array(
			OPTION_MODE                       => DEFAULT_MODE,
			OPTION_PROTECTED_ROLES            => get_default_protected_roles(),
			OPTION_VERIFICATION_PERIOD        => DEFAULT_VERIFICATION_PERIOD,
			OPTION_CODE_LENGTH                => DEFAULT_CODE_LENGTH,
			OPTION_CODE_EXPIRY                => DEFAULT_CODE_EXPIRY,
			OPTION_PASSWORD_REMINDERS_ENABLED => DEFAULT_PASSWORD_REMINDERS_ENABLED,
			OPTION_PASSWORD_REMINDER_PERIOD   => DEFAULT_PASSWORD_REMINDER_PERIOD,
			OPTION_PASSWORD_REMINDER_COOLDOWN => DEFAULT_PASSWORD_REMINDER_COOLDOWN,
			OPTION_DISABLE_TRUSTED_DEVICES    => DEFAULT_DISABLE_TRUSTED_DEVICES,
			OPTION_TRUSTED_DEVICE_EXPIRY      => DEFAULT_TRUSTED_DEVICE_EXPIRY,
			OPTION_LOCKOUT_DURATION           => DEFAULT_LOCKOUT_DURATION,
			OPTION_LOGO_URL                   => '',
			OPTION_VERIFY_INTRO               => get_default_verify_intro(),
			OPTION_PASSWORD_INTRO             => get_default_password_intro(),
			OPTION_EMAIL_FROM_NAME            => get_bloginfo( 'name' ),
			OPTION_EMAIL_FROM_ADDRESS         => get_option( 'admin_email' ),
			OPTION_EMAIL_SUBJECT              => __( 'Your verification code', 'quick-2fa' ),
		);
	}

	return $q2fa_default_settings;
}

/**
 * Get default protected roles.
 *
 * Returns roles that have install_plugins or manage_options capability.
 *
 * @since 1.0.0
 * @return array Array of role slugs.
 */
function get_default_protected_roles(): array {
	global $q2fa_default_protected_roles;

	if ( is_null( $q2fa_default_protected_roles ) ) {
		$roles           = wp_roles();
		$protected_roles = array();

		foreach ( $roles->roles as $role_slug => $role_info ) {
			$role = get_role( $role_slug );

			if ( $role && ( $role->has_cap( 'install_plugins' ) || $role->has_cap( 'manage_options' ) ) ) {
				$protected_roles[] = $role_slug;
			}
		}

		$q2fa_default_protected_roles = $protected_roles;
	}

	return $q2fa_default_protected_roles;
}

/**
 * Get default verification page intro text.
 *
 * @since 1.0.0
 * @return string Default intro text.
 */
function get_default_verify_intro(): string {
	return __( 'For your security, we need to verify your identity before you can access the admin area.', 'quick-2fa' );
}

/**
 * Get default password reminder intro text.
 *
 * @since 1.0.0
 * @return string Default intro text.
 */
function get_default_password_intro(): string {
	return __( 'Regular password changes help keep your account secure. We recommend updating your password every 60 days.', 'quick-2fa' );
}

/**
 * Get URL for verification page.
 *
 * @since 1.0.0
 * @return string Verification page URL.
 */
function get_verify_url(): string {
	return add_query_arg( QUERY_PARAM, ACTION_VERIFY, wp_login_url() );
}

/**
 * Get URL for password reminder page.
 *
 * @since 1.0.0
 * @return string Password reminder page URL.
 */
function get_password_url(): string {
	return add_query_arg( QUERY_PARAM, ACTION_PASSWORD, wp_login_url() );
}

/**
 * Check if current request is a 2FA page.
 *
 * @since 1.0.0
 * @return bool True if on a 2FA page.
 */
function is_2fa_page(): bool {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking if parameter exists.
	return isset( $_GET[ QUERY_PARAM ] );
}

// ============================================================================
// Utility Functions
// ============================================================================

/**
 * Get client IP address.
 *
 * @since 1.0.0
 * @return string IP address.
 */
function get_ip_address(): string {
	$ip = '';

	if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
	} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
		// X-Forwarded-For can contain multiple IPs, use the first one.
		$forwarded = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
		$ips       = explode( ',', $forwarded );
		$ip        = trim( $ips[0] );
	} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	} else {
		// No IP address available - $ip remains empty string.
	}

	// Validate IP address format.
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 ) ) {
		$ip = '';
	}

	return $ip;
}

/**
 * Get client user agent.
 *
 * @since 1.0.0
 * @return string User agent.
 */
function get_user_agent(): string {
	$user_agent = '';

	if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		// HTTP_USER_AGENT not available - return empty string.
	} elseif ( empty( $user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) ) ) {
		// HTTP_USER_AGENT exists but is empty after sanitization - return empty string.
	} else {
		// Valid user agent assigned - return sanitized value.
	}

	return $user_agent;
}

/**
 * Get current admin URL.
 *
 * @since 1.0.0
 * @return string Current admin URL.
 */
function get_current_admin_url(): string {
	$url = admin_url();

	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		// REQUEST_URI not available (e.g., WP-CLI) - use default admin_url().
	} elseif ( empty( $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) ) {
		// REQUEST_URI exists but is empty after sanitization - use default admin_url().
	} else {
		// Parse the request URI to get just the path and query.
		$parsed = wp_parse_url( $request_uri );
		$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
		$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

		// Build full URL.
		$url = home_url( $path . $query );
	}

	return $url;
}

/**
 * Store return URL for redirect after verification.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 */
function store_return_url( int $user_id ): void {
	$return_url = get_current_admin_url();
	set_transient( TRANSIENT_RETURN_URL . $user_id, $return_url, 5 * MINUTE_IN_SECONDS );
}

/**
 * Get and delete return URL.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 * @return string Return URL (defaults to admin_url if not found).
 */
function get_return_url( int $user_id ): string {
	$return_url = get_transient( TRANSIENT_RETURN_URL . $user_id );

	// Clean up transient.
	delete_transient( TRANSIENT_RETURN_URL . $user_id );

	// Default to main admin page if no return URL.
	if ( empty( $return_url ) ) {
		$return_url = admin_url();
	}

	// Validate return URL is safe.
	$return_url = wp_validate_redirect( $return_url, admin_url() );

	return $return_url;
}

/**
 * Check if 2FA should be skipped for current request.
 *
 * @since 1.0.0
 * @return bool True if 2FA should be skipped.
 */
function should_skip_check(): bool {
	// WP-CLI.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return true;
	}

	// AJAX requests.
	if ( wp_doing_ajax() ) {
		return true;
	}

	// REST API requests.
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	// Cron jobs.
	if ( wp_doing_cron() ) {
		return true;
	}

	// XML-RPC.
	if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		return true;
	}

	// Application Password authentication.
	if ( did_action( 'application_password_did_authenticate' ) ) {
		return true;
	}

	// User Switching plugin - skip verification when switching between users.
	if ( function_exists( 'current_user_switched' ) && current_user_switched() ) {
		return true;
	}

	// Already on a 2FA page (prevents redirect loops).
	if ( is_2fa_page() ) {
		return true;
	}

	// Plugin is disabled.
	$mode = get_option( OPTION_MODE, DEFAULT_MODE );
	if ( MODE_DISABLED === $mode ) {
		return true;
	}

	return false;
}

/**
 * Mask an email address for privacy.
 *
 * Masks the local part and domain to prevent email disclosure.
 * Example: paul@headwall.co.uk becomes p***@h***********.uk
 *
 * @since 0.9.1
 * @param string $email Email address to mask.
 * @return string Masked email address.
 */
function mask_email( string $email ): string {
	// Validate email format.
	if ( ! is_email( $email ) ) {
		return $email;
	}

	// Split email into parts.
	$parts = explode( '@', $email );
	if ( count( $parts ) !== 2 ) {
		return $email;
	}

	list( $local, $domain ) = $parts;

	// Mask local part (show first char + asterisks).
	$local_masked = mb_substr( $local, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $local ) - 1 ) );

	// Split domain into name and TLD.
	$domain_parts = explode( '.', $domain );
	if ( count( $domain_parts ) < 2 ) {
		// No TLD, mask entire domain.
		$domain_masked = mb_substr( $domain, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $domain ) - 1 ) );
	} else {
		// Mask domain name, keep TLD visible.
		$tld          = array_pop( $domain_parts );
		$domain_name  = implode( '.', $domain_parts );
		$domain_masked = mb_substr( $domain_name, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $domain_name ) - 1 ) ) . '.' . $tld;
	}

	return $local_masked . '@' . $domain_masked;
}
