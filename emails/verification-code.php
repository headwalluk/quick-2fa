<?php
/**
 * Email Template: Verification Code
 *
 * Available variables:
 *
 * @var string   $code        Verification code
 * @var \WP_User $user        User object
 * @var int      $code_expiry Code expiry in minutes
 *
 * @package Quick_2FA
 * @since 0.4.0
 */

// Block direct access.
defined( 'ABSPATH' ) || die();

printf(
	/* translators: %s: User display name */
	esc_html__( 'Hi %s,', 'quick-2fa' ),
	esc_html( $user->display_name )
);
echo "\n\n";

printf(
	/* translators: %s: Verification code */
	esc_html__( 'Your verification code is: %s', 'quick-2fa' ),
	esc_html( $code )
);
echo "\n\n";

printf(
	/* translators: %d: Number of minutes */
	esc_html( _n( 'This code will expire in %d minute.', 'This code will expire in %d minutes.', (int) $code_expiry, 'quick-2fa' ) ),
	(int) $code_expiry
);
echo "\n\n";

esc_html_e( 'If you did not request this code, please contact your site administrator immediately.', 'quick-2fa' );
echo "\n\n";

echo "---\n";
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Plain text email, html_entity_decode for proper rendering.
echo html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) . "\n";
echo esc_url( home_url() );
