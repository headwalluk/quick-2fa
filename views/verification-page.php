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

if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url() );
	exit();
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- get_language_attributes() is safe.
printf( '<!DOCTYPE html><html %s class="wp-core-ui"><head>', get_language_attributes() );
// phpcs:enable
printf( '<meta charset="%s">', esc_attr( get_bloginfo( 'charset' ) ) );
echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
echo '<meta name="robots" content="noindex, nofollow">';
printf( '<title>%s - %s</title>', esc_html__( 'Account Verification Required', 'quick-2fa' ), esc_html( get_bloginfo( 'name' ) ) );

wp_enqueue_style( 'login' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_enqueue_scripts' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_head' );

echo '</head>';
printf( '<body class="login no-js login-action-login wp-core-ui %s">', esc_attr( 'locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', get_locale() ) ) ) ) );

echo '<div id="login">';
printf( '<h1><a href="%s">%s</a></h1>', esc_url( home_url( '/' ) ), esc_html( get_bloginfo( 'name' ) ) );

if ( $error ) {
	printf( '<div id="login_error">%s</div>', esc_html( $error->get_error_message() ) );
}

if ( $message ) {
	printf( '<div class="message">%s</div>', esc_html( $message ) );
}

if ( ! $error || 'rate_limited' !== $error->get_error_code() ) {
	echo '<form name="q2fa-verify-form" id="loginform" action="" method="post">';

	printf(
		'<p>%s<br><br>%s<br><strong>%s</strong><br><br>%s</p>',
		esc_html__( 'Account Verification Required', 'quick-2fa' ),
		esc_html__( 'For your security, we need to verify your identity before you can access the admin area.', 'quick-2fa' ),
		esc_html( \Quick_2FA\mask_email( $user->user_email ) ),
		esc_html__( 'Please enter the verification code sent to your email.', 'quick-2fa' )
	);

	wp_nonce_field( 'quick2fa_verify' );

	echo '<p>';
	printf( '<label for="q2fa_code">%s</label>', esc_html__( 'Verification Code:', 'quick-2fa' ) );
	echo '<input type="text" name="q2fa_code" id="q2fa_code" class="input q2fa-verification-input" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" inputmode="numeric" autofocus>';
	echo '</p>';

	if ( $trusted_devices_enabled ) {
		/* translators: %d: number of days */
		$quick_2fa_trust_text = _n( 'Trust this device for %d day', 'Trust this device for %d days', $trusted_device_expiry, 'quick-2fa' );
		printf(
			'<p class="forgetmenot"><input type="checkbox" name="q2fa_trust_device" id="q2fa_trust_device" value="1"><label for="q2fa_trust_device">%s</label></p>',
			sprintf( esc_html( $quick_2fa_trust_text ), (int) $trusted_device_expiry )
		);
	}

	printf( '<p class="submit"><input type="submit" name="q2fa_verify" id="wp-submit" class="button button-primary button-large" value="%s"></p>', esc_html__( 'Verify', 'quick-2fa' ) );

	echo '</form>';

	echo '<form method="post" action="" class="simple">';
	wp_nonce_field( 'quick2fa_resend' );
	printf( '<button type="submit" name="q2fa_resend" class="button button-secondary">%s</button>', esc_html__( 'Resend Code', 'quick-2fa' ) );
	echo '</form>';
}

printf(
	'<p id="backtoblog"><a href="%s">%s</a></p>',
	esc_url( wp_logout_url() ),
	esc_html__( '&larr; Log out', 'quick-2fa' )
);

echo '</div>';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_footer' );

echo '</body></html>';
