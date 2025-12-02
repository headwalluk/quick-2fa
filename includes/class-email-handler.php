<?php
/**
 * Email Handler
 *
 * Handles all email-related functionality for verification codes.
 *
 * @package Quick_2FA
 * @since   0.4.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * Email Handler Class
 *
 * Manages email sending for verification codes including:
 * - Template rendering with placeholder replacement
 * - Header sanitization to prevent injection attacks
 * - Email configuration and sending
 *
 * @since 0.4.0
 */
class Email_Handler {

	/**
	 * Send verification code via email.
	 *
	 * @since 0.4.0
	 * @param \WP_User $user User object.
	 * @param string   $code Verification code.
	 * @return array{success: bool, error?: string} Result array with success status and optional error message.
	 */
	public function send_verification_code( $user, $code ) {
		$to      = $user->user_email;
		$subject = get_option( OPTION_EMAIL_SUBJECT, __( 'Your verification code', 'quick-2fa' ) );
		$message = $this->get_message( $code, $user );
		$headers = $this->get_headers();

		// Send email.
		$sent = wp_mail( $to, $subject, $message, $headers );

		return array(
			'success' => $sent,
			'error'   => $sent ? null : __( 'Failed to send verification email. Please contact your site administrator.', 'quick-2fa' ),
		);
	}

	/**
	 * Get formatted email message.
	 *
	 * @since 0.4.0
	 * @param string   $code Verification code.
	 * @param \WP_User $user User object.
	 * @return string Formatted email message.
	 */
	public function get_message( $code, $user ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Variables extracted in template.
		$code_expiry = get_option( OPTION_CODE_EXPIRY, DEFAULT_CODE_EXPIRY );

		ob_start();
		include \QUICK_2FA_PATH . 'emails/verification-code.php';
		return ob_get_clean();
	}

	/**
	 * Get email headers.
	 *
	 * @since 0.4.0
	 * @return array Email headers.
	 */
	public function get_headers() {
		$from_name  = get_option( OPTION_EMAIL_FROM_NAME, get_bloginfo( 'name' ) );
		$from_email = get_option( OPTION_EMAIL_FROM_ADDRESS, get_option( 'admin_email' ) );

		// Sanitize headers.
		list( $from_name, $from_email ) = $this->sanitize_headers( $from_name, $from_email );

		$headers = array(
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);

		return $headers;
	}

	/**
	 * Sanitize email headers to prevent injection attacks.
	 *
	 * @since 0.4.0
	 * @param string $from_name  From name.
	 * @param string $from_email From email address.
	 * @return array{0: string, 1: string} Sanitized from name and email.
	 */
	public function sanitize_headers( $from_name, $from_email ) {
		// Sanitize name to prevent header injection.
		$from_name = sanitize_text_field( $from_name );
		$from_name = str_replace( array( "\r", "\n", '%0a', '%0d' ), '', $from_name );

		// Sanitize email to prevent header injection.
		$from_email = sanitize_email( $from_email );
		$from_email = str_replace( array( "\r", "\n", '%0a', '%0d' ), '', $from_email );

		// Validate email address.
		if ( ! is_email( $from_email ) ) {
			$from_email = get_option( 'admin_email' );
		}

		return array( $from_name, $from_email );
	}
}
