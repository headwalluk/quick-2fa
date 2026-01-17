<?php
/**
 * Verification Page Template
 *
 * @package Quick_2FA
 * @since 1.0.0
 *
 * Variables available in this template:
 * @var WP_User     $user                     Current user object
 * @var WP_Error    $error                    Error object (if any)
 * @var string|null $message                  Success message (if any)
 * @var bool        $trusted_devices_enabled  Whether trusted devices feature is enabled
 * @var int         $trusted_device_expiry    Number of days to trust a device
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

// Security: Ensure user is logged in.
if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url() );
	exit();
}

// Start HTML output.
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() is safe.
printf( '<!DOCTYPE html><html %s class="wp-core-ui"><head>', get_language_attributes() );
// phpcs:enable
printf( '<meta charset="%s">', esc_attr( get_bloginfo( 'charset' ) ) );
echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
echo '<meta name="robots" content="noindex, nofollow">';
printf( '<title>%s - %s</title>', esc_html__( 'Account Verification Required', 'quick-2fa' ), esc_html( get_bloginfo( 'name' ) ) );

// Enqueue WordPress login styles and fire hook.
wp_enqueue_style( 'login' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_enqueue_scripts' );

// Output login page head content (NOT wp_head which includes theme/frontend assets).
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_head' );

// Disable Query Monitor output for security (prevents debug info leakage on login pages).
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Query Monitor hook.
do_action( 'qm/cease' );

echo '</head>';
printf( '<body class="login no-js login-action-login wp-core-ui %s">', esc_attr( 'locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', get_locale() ) ) ) ) );

// Main login container - WordPress default structure.
echo '<div id="login">';

// Heading.
printf( '<h1><a href="%s">%s</a></h1>', esc_url( home_url( '/' ) ), esc_html( get_bloginfo( 'name' ) ) );

// Error message.
if ( $error ) {
	printf( '<div id="login_error">%s</div>', esc_html( $error->get_error_message() ) );
}

// Success message.
if ( $message ) {
	printf( '<div class="message">%s</div>', esc_html( $message ) );
}

// Show form unless rate limited.
if ( ! $error || 'rate_limited' !== $error->get_error_code() ) {
	// Verification form using WordPress login form structure.
	echo '<form name="q2fa-verify-form" id="loginform" action="" method="post">';

	printf(
		'<p>%s<br><br>%s<br><strong>%s</strong><br><br>%s</p>',
		esc_html__( 'Account Verification Required', 'quick-2fa' ),
		esc_html__( 'For your security, we need to verify your identity before you can access the admin area.', 'quick-2fa' ),
		esc_html( \Quick_2FA\mask_email( $user->user_email ) ),
		esc_html__( 'Please enter the verification code sent to your email.', 'quick-2fa' )
	);

	wp_nonce_field( 'quick2fa_verify' );

	// Verification code field.
	echo '<p>';
	printf( '<label for="q2fa_code">%s</label>', esc_html__( 'Verification Code:', 'quick-2fa' ) );
	echo '<input type="text" name="q2fa_code" id="q2fa_code" class="input q2fa-verification-input" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" inputmode="numeric" autofocus>';
	echo '</p>';

	// Trust device checkbox.
	if ( $trusted_devices_enabled ) {
		/* translators: %d: number of days */
		$q2fa_trust_text = _n( 'Trust this device for %d day', 'Trust this device for %d days', $trusted_device_expiry, 'quick-2fa' );
		printf(
			'<p class="forgetmenot"><input type="checkbox" name="q2fa_trust_device" id="q2fa_trust_device" value="1"><label for="q2fa_trust_device">%s</label></p>',
			sprintf( esc_html( $q2fa_trust_text ), (int) $trusted_device_expiry )
		);
	}

	// Submit button.
	printf( '<p class="submit"><input type="submit" name="q2fa_verify" id="wp-submit" class="button button-primary button-large" value="%s"></p>', esc_html__( 'Verify', 'quick-2fa' ) );

	echo '</form>';

	// Resend code form.
	echo '<form method="post" action="" class="simple">';
	wp_nonce_field( 'quick2fa_resend' );
	printf( '<button type="submit" name="q2fa_resend" class="button button-secondary">%s</button>', esc_html__( 'Resend Code', 'quick-2fa' ) );
	echo '</form>';
}

// Footer.
printf(
	'<p id="backtoblog"><a href="%s">%s</a></p>',
	esc_url( wp_logout_url() ),
	esc_html__( '&larr; Log out', 'quick-2fa' )
);

echo '</div>';

// Output login page footer.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_footer' );

echo '</body></html>';
