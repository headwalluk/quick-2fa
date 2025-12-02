<?php
/**
 * Global Functions
 *
 * Public functions available globally.
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit();
}

/**
 * Get default plugin settings.
 *
 * @since 1.0.0
 * @return array Default settings.
 */
function quick_2fa_get_default_settings() {
	return array(
		Quick_2FA\OPTION_MODE                       => Quick_2FA\DEFAULT_MODE,
		Quick_2FA\OPTION_PROTECTED_ROLES            => quick_2fa_get_default_protected_roles(),
		Quick_2FA\OPTION_VERIFICATION_PERIOD        => Quick_2FA\DEFAULT_VERIFICATION_PERIOD,
		Quick_2FA\OPTION_CODE_LENGTH                => Quick_2FA\DEFAULT_CODE_LENGTH,
		Quick_2FA\OPTION_CODE_EXPIRY                => Quick_2FA\DEFAULT_CODE_EXPIRY,
		Quick_2FA\OPTION_PASSWORD_REMINDERS_ENABLED => Quick_2FA\DEFAULT_PASSWORD_REMINDERS_ENABLED,
		Quick_2FA\OPTION_PASSWORD_REMINDER_PERIOD   => Quick_2FA\DEFAULT_PASSWORD_REMINDER_PERIOD,
		Quick_2FA\OPTION_PASSWORD_REMINDER_COOLDOWN => Quick_2FA\DEFAULT_PASSWORD_REMINDER_COOLDOWN,
		Quick_2FA\OPTION_LOGO_URL                   => '',
		Quick_2FA\OPTION_VERIFY_INTRO               => quick_2fa_default_verify_intro(),
		Quick_2FA\OPTION_PASSWORD_INTRO             => quick_2fa_default_password_intro(),
		Quick_2FA\OPTION_EMAIL_FROM_NAME            => get_bloginfo( 'name' ),
		Quick_2FA\OPTION_EMAIL_FROM_ADDRESS         => get_option( 'admin_email' ),
		Quick_2FA\OPTION_EMAIL_SUBJECT              => __( 'Your verification code', 'quick-2fa' ),
		Quick_2FA\OPTION_EMAIL_TEMPLATE             => quick_2fa_default_email_template(),
	);
}

/**
 * Get default protected roles.
 *
 * Returns roles that have install_plugins or manage_options capability.
 *
 * @since 1.0.0
 * @return array Array of role slugs.
 */
function quick_2fa_get_default_protected_roles() {
	$roles           = wp_roles();
	$protected_roles = array();

	foreach ( $roles->roles as $role_slug => $role_info ) {
		$role = get_role( $role_slug );

		if ( $role && ( $role->has_cap( 'install_plugins' ) || $role->has_cap( 'manage_options' ) ) ) {
			$protected_roles[] = $role_slug;
		}
	}

	return $protected_roles;
}

/**
 * Get default email template.
 *
 * @since 1.0.0
 * @return string Default email template.
 */
function quick_2fa_default_email_template() {
	return __(
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- Template with placeholders.
		"Hello {name},\n\n" .
			"Your verification code is: {code}\n\n" .
			"This code will expire in 15 minutes.\n\n" .
			"If you did not request this code, please contact your site administrator immediately.\n\n" .
			"---\n" .
			"{site_name}\n" .
			'{site_url}',
		'quick-2fa'
	);
}

/**
 * Get default verification page intro text.
 *
 * @since 1.0.0
 * @return string Default intro text.
 */
function quick_2fa_default_verify_intro() {
	return __( 'For your security, we need to verify your identity before you can access the admin area.', 'quick-2fa' );
}

/**
 * Get default password reminder intro text.
 *
 * @since 1.0.0
 * @return string Default intro text.
 */
function quick_2fa_default_password_intro() {
	return __( 'Regular password changes help keep your account secure. We recommend updating your password every 60 days.', 'quick-2fa' );
}

/**
 * Get URL for verification page.
 *
 * @since 1.0.0
 * @return string Verification page URL.
 */
function quick_2fa_get_verify_url() {
	return add_query_arg( Quick_2FA\QUERY_PARAM, Quick_2FA\ACTION_VERIFY, wp_login_url() );
}

/**
 * Get URL for password reminder page.
 *
 * @since 1.0.0
 * @return string Password reminder page URL.
 */
function quick_2fa_get_password_url() {
	return add_query_arg( Quick_2FA\QUERY_PARAM, Quick_2FA\ACTION_PASSWORD, wp_login_url() );
}

/**
 * Check if current request is a 2FA page.
 *
 * @since 1.0.0
 * @return bool True if on a 2FA page.
 */
function quick_2fa_is_2fa_page() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking if parameter exists.
	return isset( $_GET[ Quick_2FA\QUERY_PARAM ] );
}
