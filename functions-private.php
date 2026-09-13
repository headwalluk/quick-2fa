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
	static $default_settings = null;

	if ( null === $default_settings ) {
		$default_settings = array(
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

	return $default_settings;
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
	static $default_protected_roles = null;

	if ( null === $default_protected_roles ) {
		$roles                   = wp_roles();
		$default_protected_roles = array();

		foreach ( array_keys( $roles->roles ) as $role_slug ) {
			$role = get_role( $role_slug );

			if ( $role instanceof \WP_Role && ( $role->has_cap( 'install_plugins' ) || $role->has_cap( 'manage_options' ) ) ) {
				$default_protected_roles[] = $role_slug;
			}
		}
	}

	return $default_protected_roles;
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
 * Reads client-supplied proxy headers, so the result is spoofable and is for
 * logging only. Since 1.2.0 nothing gates access on it — device trust is
 * carried by a cookie token. Do not reintroduce it into any access decision.
 *
 * @since 1.0.0
 * @return string IP address, or '' if none could be validated.
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
		// No address available; $ip stays empty.
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

	if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
		$user_agent = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
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
	$admin_url   = admin_url();
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	if ( '' !== $request_uri ) {
		// Keep only the path and query; the host comes from home_url().
		$parsed = wp_parse_url( $request_uri );
		$path   = $parsed['path'] ?? '';
		$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

		$admin_url = home_url( $path . $query );
	}

	return $admin_url;
}

/**
 * Store return URL for redirect after verification.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 */
function store_return_url( int $user_id ): void {
	set_transient( TRANSIENT_RETURN_URL . $user_id, get_current_admin_url(), RETURN_URL_TTL );
}

/**
 * Get and delete return URL.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 * @return string Return URL (defaults to admin_url if not found).
 */
function get_return_url( int $user_id ): string {
	$transient_key = TRANSIENT_RETURN_URL . $user_id;
	$return_url    = get_transient( $transient_key );

	// Single-use: consumed whether or not it turns out to be usable.
	delete_transient( $transient_key );

	if ( empty( $return_url ) || ! is_string( $return_url ) ) {
		$return_url = admin_url();
	}

	return wp_validate_redirect( $return_url, admin_url() );
}

/**
 * Whether this request is core's theme/plugin editor loopback.
 *
 * After a PHP edit, core requests an admin URL carrying wp_scrape_key and
 * wp_scrape_nonce to check the site still loads. That loopback presents the
 * admin's auth cookie but WordPress's own User-Agent, so it looks like an
 * untrusted device; redirecting it makes core revert the edit with
 * "loopback_request_failed". The nonce is compared against the transient core
 * itself sets, so bare query parameters cannot use this to bypass 2FA.
 *
 * @since 1.3.0
 * @return bool True if this is a verified editor loopback request.
 */
function is_editor_loopback_request(): bool {
	$is_loopback = false;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated against core's transient below.
	if ( isset( $_REQUEST['wp_scrape_key'], $_REQUEST['wp_scrape_nonce'] ) ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Validated against core's transient below.
		$scrape_key = substr( sanitize_key( wp_unslash( $_REQUEST['wp_scrape_key'] ) ), 0, 32 );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared as a string to core's transient below.
		$scrape_nonce = (string) wp_unslash( $_REQUEST['wp_scrape_nonce'] );

		$is_loopback = (string) get_transient( 'scrape_key_' . $scrape_key ) === $scrape_nonce;
	}

	return $is_loopback;
}

/**
 * Check if 2FA should be skipped for current request.
 *
 * The branches are ordered cheapest-first, and each records why it matched.
 * $skip_reason is not returned, but it makes every branch self-labelling and
 * gives a single place to inspect when a request is unexpectedly skipped --
 * previously there was no way to tell which of ten guards had fired.
 *
 * @since 1.0.0
 * @return bool True if 2FA should be skipped.
 */
function should_skip_check(): bool {
	$skip_reason = '';

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		$skip_reason = 'wp-cli';
	} elseif ( wp_doing_ajax() ) {
		$skip_reason = 'ajax';
	} elseif ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		$skip_reason = 'rest-api';
	} elseif ( wp_doing_cron() ) {
		$skip_reason = 'cron';
	} elseif ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
		$skip_reason = 'xml-rpc';
	} elseif ( did_action( 'application_password_did_authenticate' ) ) {
		$skip_reason = 'application-password';
	} elseif ( function_exists( 'current_user_switched' ) && current_user_switched() ) {
		// User Switching plugin: the switch is admin-initiated and already authenticated.
		$skip_reason = 'user-switching';
	} elseif ( is_2fa_page() ) {
		// Already on a 2FA page; redirecting again would loop.
		$skip_reason = '2fa-page';
	} elseif ( is_editor_loopback_request() ) {
		$skip_reason = 'editor-loopback';
	} elseif ( MODE_DISABLED === get_option( OPTION_MODE, DEFAULT_MODE ) ) {
		$skip_reason = 'mode-disabled';
	} else {
		// No skip condition matched; 2FA applies.
	}

	return '' !== $skip_reason;
}

/**
 * Mask an email address for privacy.
 *
 * Masks the local part and domain to prevent email disclosure.
 * Example: paul@headwall.co.uk becomes p***@h**********.uk
 *
 * @since 0.9.1
 * @param string $email Email address to mask.
 * @return string Masked email address.
 */
function mask_email( string $email ): string {
	$parts = explode( '@', $email );

	// Anything we cannot parse is returned untouched rather than half-masked.
	if ( ! is_email( $email ) || 2 !== count( $parts ) ) {
		$masked = $email;
	} else {
		[$local, $domain] = $parts;

		$local_masked = mb_substr( $local, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $local ) - 1 ) );
		$domain_parts = explode( '.', $domain );

		if ( count( $domain_parts ) < 2 ) {
			// Defensive: is_email() already rejects a domain with no dot.
			$domain_masked = mb_substr( $domain, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $domain ) - 1 ) );
		} else {
			$tld           = array_pop( $domain_parts );
			$domain_name   = implode( '.', $domain_parts );
			$domain_masked = mb_substr( $domain_name, 0, 1 ) . str_repeat( '*', max( 3, mb_strlen( $domain_name ) - 1 ) ) . '.' . $tld;
		}

		$masked = $local_masked . '@' . $domain_masked;
	}

	return $masked;
}

/**
 * Check the nonce on a 2FA page submission.
 *
 * Deliberately a namespaced function rather than a Plugin method: the
 * WordPress.Security.NonceVerification sniff only recognises a nonce check when
 * wp_verify_nonce() is reached through a global function call, and skips any
 * call made through an object operator. As a method this helper would blind the
 * sniff to every $_POST read in the page handlers.
 *
 * @since 1.3.0
 * @param string $action Nonce action name.
 * @return bool True when a valid nonce for $action was submitted.
 */
function verify_page_nonce( string $action ): bool {
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on the same line.
	$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

	return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
}

/**
 * Check the nonce on an admin action link.
 *
 * The GET counterpart of verify_page_nonce(), and a namespaced function for
 * the same reason: WordPress.Security.NonceVerification only recognises a
 * nonce check made through a global function call.
 *
 * @since 1.3.0
 * @param string $action Nonce action name.
 * @return bool True when a valid nonce for $action was supplied.
 */
function verify_admin_action_nonce( string $action ): bool {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised on the same line.
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';

	return '' !== $nonce && false !== wp_verify_nonce( $nonce, $action );
}
