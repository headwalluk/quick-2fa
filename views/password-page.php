<?php
/**
 * Password Reminder Page Template
 *
 * @package Quick_2FA
 * @since 1.0.0
 *
 * Variables available in this template:
 * @var WP_User     $user           Current user object
 * @var WP_Error    $error          Error object (if any)
 * @var string|null $message        Success message (if any)
 * @var int         $days_since     Days since last password change
 * @var string      $new_password   Pre-generated strong password
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
printf( '<title>%s - %s</title>', esc_html__( 'Update Your Password', 'quick-2fa' ), esc_html( get_bloginfo( 'name' ) ) );

wp_enqueue_style( 'login' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_enqueue_scripts' );

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_head' );

echo '</head>';
printf( '<body class="login no-js login-action-password wp-core-ui %s">', esc_attr( 'locale-' . sanitize_html_class( strtolower( str_replace( '_', '-', get_locale() ) ) ) ) );

echo '<div id="login">';
printf( '<h1><a href="%s">%s</a></h1>', esc_url( home_url( '/' ) ), esc_html( get_bloginfo( 'name' ) ) );

if ( $error ) {
	printf( '<div id="login_error">%s</div>', esc_html( $error->get_error_message() ) );
}

if ( $message ) {
	printf( '<div class="message">%s</div>', esc_html( $message ) );
}

if ( (int) $days_since > 365 ) {
	$quick_2fa_age_message = __( "It's been over a year since you last changed your password. For your security, we recommend updating it regularly.", 'quick-2fa' );
} else {
	$quick_2fa_age_message = sprintf(
		/* translators: %d: number of days since last password change */
		__( "It's been %d days since you last changed your password. For your security, we recommend updating it regularly.", 'quick-2fa' ),
		(int) $days_since
	);
}

printf(
	'<div class="message" style="border-left-color: #dba617;">%s</div>',
	esc_html( $quick_2fa_age_message )
);

echo '<form name="q2fa-password-form" id="loginform" action="" method="post" autocomplete="on">';

wp_nonce_field( 'quick2fa_password' );

printf( '<input type="hidden" name="username" value="%s" autocomplete="username">', esc_attr( $user->user_login ) );
printf( '<input type="hidden" name="email" value="%s" autocomplete="email">', esc_attr( $user->user_email ) );

echo '<p>';
printf( '<label for="q2fa_new_password">%s</label>', esc_html__( 'New Password:', 'quick-2fa' ) );
printf(
	'<input type="password" name="password" id="q2fa_new_password" class="input" value="%s" size="20" required autocomplete="new-password">',
	esc_attr( $new_password )
);
echo '</p>';

printf(
	'<p><button type="button" class="button button-secondary wp-hide-pw hide-if-no-js" data-toggle="0" aria-label="%s"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button></p>',
	esc_attr__( 'Show password', 'quick-2fa' )
);

printf(
	'<p class="description">%s</p>',
	esc_html__( 'A strong password has been generated for you. You can use it as-is or change it to your own.', 'quick-2fa' )
);

printf(
	'<p class="submit"><input type="submit" name="q2fa_update_password" id="wp-submit" class="button button-primary button-large" value="%s"></p>',
	esc_html__( 'Update Password', 'quick-2fa' )
);

echo '</form>';

echo '<form method="post" action="" style="text-align: center; margin-top: 20px;">';
wp_nonce_field( 'quick2fa_remind_later' );
printf(
	'<button type="submit" name="q2fa_remind_later" class="button button-secondary">%s</button>',
	esc_html__( 'Remind Me Later', 'quick-2fa' )
);
echo '</form>';

printf(
	'<p id="backtoblog"><a href="%s">%s</a></p>',
	esc_url( wp_logout_url() ),
	esc_html__( '&larr; Log out', 'quick-2fa' )
);

echo '</div>';

// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core hook.
do_action( 'login_footer' );

echo '<script>document.querySelector(".wp-hide-pw").addEventListener("click",function(){var e=document.getElementById("q2fa_new_password"),t=this.getAttribute("data-toggle");e.type="0"===t?"text":"password",this.setAttribute("data-toggle","0"===t?"1":"0"),this.setAttribute("aria-label","0"===t?"' . esc_js( __( 'Hide password', 'quick-2fa' ) ) . '":"' . esc_js( __( 'Show password', 'quick-2fa' ) ) . '")});</script>';

echo '</body></html>';
