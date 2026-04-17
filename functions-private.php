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
	global $quick_2fa_default_settings;

	if ( is_null( $quick_2fa_default_settings ) ) {
		$quick_2fa_default_settings = array(
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
			OPTION_EMAIL_FROM_NAME            => get_bloginfo( 'name' ),
			OPTION_EMAIL_FROM_ADDRESS         => get_option( 'admin_email' ),
			OPTION_EMAIL_SUBJECT              => __( 'Your verification code', 'quick-2fa' ),
		);
	}

	return $quick_2fa_default_settings;
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
	global $quick_2fa_default_protected_roles;

	if ( is_null( $quick_2fa_default_protected_roles ) ) {
		$roles           = wp_roles();
		$protected_roles = array();

		foreach ( $roles->roles as $role_slug => $role_info ) {
			$role = get_role( $role_slug );

			if ( $role && ( $role->has_cap( 'install_plugins' ) || $role->has_cap( 'manage_options' ) ) ) {
				$protected_roles[] = $role_slug;
			}
		}

		$quick_2fa_default_protected_roles = $protected_roles;
	}

	return $quick_2fa_default_protected_roles;
}

/**
 * Allowed HTML tags for filter-supplied intro text.
 *
 * The verify and password intros are rendered through `wp_kses()` so site
 * administrators can include light formatting (bold, italics, line breaks,
 * a link to a security policy) when filtering the intro. The whitelist is
 * deliberately tight — these are auth pages, so the surface stays minimal.
 *
 * Anything not on this list is silently stripped at render time.
 *
 * @since 1.0.0
 * @return array<string,array<string,bool>> wp_kses-compatible allowed HTML map.
 */
function get_intro_allowed_html(): array {
	return array(
		'a'      => array(
			'href'   => true,
			'rel'    => true,
			'target' => true,
			'title'  => true,
		),
		'b'      => array(),
		'br'     => array(),
		'em'     => array(),
		'i'      => array(),
		'span'   => array(
			'class' => true,
		),
		'strong' => array(),
	);
}

/**
 * Get the intro text for the 2FA verification page.
 *
 * Returns a sensible default that sites can override via the
 * `quick2fa_verify_intro` filter. Called at render time on every
 * verification page load, so the filter is always applied.
 *
 * @since 1.0.0
 * @return string Intro text. May contain a tight subset of inline HTML —
 *                see `get_intro_allowed_html()`. Render with `wp_kses()`.
 */
function get_verify_intro(): string {
	$intro = __( 'For your security, we need to verify your identity before you can access the admin area.', 'quick-2fa' );

	/**
	 * Filter the intro text shown on the 2FA verification page.
	 *
	 * The returned string is rendered through `wp_kses()` with a tight
	 * whitelist of inline HTML tags: `<a href|rel|target|title>`, `<b>`,
	 * `<br>`, `<em>`, `<i>`, `<span class>`, `<strong>`. Anything outside
	 * that whitelist is stripped silently. Plain text is always safe.
	 *
	 * @since 1.0.0
	 *
	 * @param string $intro Default intro text.
	 */
	return (string) apply_filters( 'quick2fa_verify_intro', $intro );
}

/**
 * Get the intro text for the password reminder page.
 *
 * Returns a sensible default that sites can override via the
 * `quick2fa_password_intro` filter. Called at render time on every
 * password reminder page load, so the filter is always applied.
 *
 * @since 1.0.0
 * @return string Intro text. May contain a tight subset of inline HTML —
 *                see `get_intro_allowed_html()`. Render with `wp_kses()`.
 */
function get_password_intro(): string {
	$intro = __( 'Regular password changes help keep your account secure. We recommend updating your password every 60 days.', 'quick-2fa' );

	/**
	 * Filter the intro text shown on the password reminder page.
	 *
	 * The returned string is rendered through `wp_kses()` with a tight
	 * whitelist of inline HTML tags: `<a href|rel|target|title>`, `<b>`,
	 * `<br>`, `<em>`, `<i>`, `<span class>`, `<strong>`. Anything outside
	 * that whitelist is stripped silently. Plain text is always safe.
	 *
	 * @since 1.0.0
	 *
	 * @param string $intro Default intro text.
	 */
	return (string) apply_filters( 'quick2fa_password_intro', $intro );
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
	if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return '';
	}

	return sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
}

/**
 * Get current admin URL.
 *
 * @since 1.0.0
 * @return string Current admin URL.
 */
function get_current_admin_url(): string {
	if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
		return admin_url();
	}

	$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) );
	if ( empty( $request_uri ) ) {
		return admin_url();
	}

	// Parse the request URI to get just the path and query.
	$parsed = wp_parse_url( $request_uri );
	$path   = isset( $parsed['path'] ) ? $parsed['path'] : '';
	$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

	return home_url( $path . $query );
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

	delete_transient( TRANSIENT_RETURN_URL . $user_id );

	if ( empty( $return_url ) ) {
		$return_url = admin_url();
	}

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

	// Theme/plugin editor loopback: core hits an admin URL with wp_scrape_key +
	// wp_scrape_nonce to check that a PHP edit didn't whitescreen the site. The
	// loopback carries the admin's auth cookie but WordPress's own User-Agent,
	// so it looks like an untrusted device to us — redirecting it would cause
	// core to revert the edit with "loopback_request_failed". Validate against
	// the transient core itself sets so bare query params can't bypass 2FA.
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated against transient below.
	if ( isset( $_REQUEST['wp_scrape_key'], $_REQUEST['wp_scrape_nonce'] ) ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated against transient below.
		$scrape_key = substr( sanitize_key( wp_unslash( $_REQUEST['wp_scrape_key'] ) ), 0, 32 );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as string to transient below.
		$scrape_nonce = (string) wp_unslash( $_REQUEST['wp_scrape_nonce'] );
		if ( (string) get_transient( 'scrape_key_' . $scrape_key ) === $scrape_nonce ) {
			return true;
		}
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
	if ( ! is_email( $email ) ) {
		return $email;
	}

	$parts = explode( '@', $email );
	if ( count( $parts ) !== 2 ) {
		return $email;
	}

	[$local, $domain] = $parts;

	$local_masked = mb_substr( $local, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $local ) - 1 ) );
	$domain_parts = explode( '.', $domain );
	if ( count( $domain_parts ) < 2 ) {
		$domain_masked = mb_substr( $domain, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $domain ) - 1 ) );
	} else {
		$tld           = array_pop( $domain_parts );
		$domain_name   = implode( '.', $domain_parts );
		$domain_masked = mb_substr( $domain_name, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $domain_name ) - 1 ) ) . '.' . $tld;
	}

	return $local_masked . '@' . $domain_masked;
}
