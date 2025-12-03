<?php
/**
 * Verification Code Handler
 *
 * Handles generation, storage, and verification of 2FA codes.
 *
 * @package Quick_2FA
 * @since   0.4.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * Verification Code Handler Class
 *
 * Manages the complete lifecycle of verification codes including:
 * - Cryptographically secure code generation
 * - Secure storage using password hashing
 * - Code verification with rate limiting
 * - Automatic expiry and cleanup
 *
 * @since 0.4.0
 */
class Verification_Code_Handler {

	/**
	 * User ID for this handler instance.
	 *
	 * @var int
	 */
	private $user_id;

	/**
	 * Constructor.
	 *
	 * @since 0.4.0
	 * @param int $user_id User ID to handle codes for.
	 */
	public function __construct( int $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Generate a cryptographically secure verification code.
	 *
	 * @since 0.4.0
	 * @return string Verification code.
	 */
	public function generate(): string {
		$length = get_option( OPTION_CODE_LENGTH, DEFAULT_CODE_LENGTH );

		// Generate cryptographically secure random code.
		$max  = (int) str_repeat( '9', $length );
		$code = random_int( 0, $max );

		// Pad with leading zeros.
		return str_pad( $code, $length, '0', STR_PAD_LEFT );
	}

	/**
	 * Store hashed verification code.
	 *
	 * @since 0.4.0
	 * @param string $code Plain text code.
	 */
	public function store( string $code ): void {
		// Hash the code.
		$hash = wp_hash_password( $code );

		// Store hash and timestamp.
		update_user_meta( $this->user_id, META_CODE_HASH, $hash );
		update_user_meta( $this->user_id, META_CODE_TIMESTAMP, time() );

		// Reset attempt counter.
		update_user_meta( $this->user_id, META_CODE_ATTEMPTS, 0 );

		// Log code generation.
		$security = new Account_Security_Handler( $this->user_id );
		$security->log_event(
			LOG_CODE_GENERATED,
			array(
				'timestamp' => time(),
				'ip'        => get_ip_address(),
			)
		);
	}

	/**
	 * Check rate limiting for code generation.
	 *
	 * @since 0.4.0
	 * @return true|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit(): true|\WP_Error {
		$key        = TRANSIENT_RATE_LIMIT . 'code_gen_' . $this->user_id;
		$limit_data = get_transient( $key );

		if ( false === $limit_data ) {
			// No existing limit, start new window.
			$limit_data = array(
				'count'        => 1,
				'window_start' => time(),
			);
			set_transient( $key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW );
			return true;
		}

		// Check if we're still in the same window.
		$elapsed = time() - $limit_data['window_start'];

		if ( $elapsed > RATE_LIMIT_CODE_GENERATION_WINDOW ) {
			// New window.
			$limit_data = array(
				'count'        => 1,
				'window_start' => time(),
			);
			set_transient( $key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW );
			return true;
		}

		// Check if limit exceeded.
		if ( $limit_data['count'] >= RATE_LIMIT_CODE_GENERATION_MAX ) {
			$wait_time = ceil( ( RATE_LIMIT_CODE_GENERATION_WINDOW - $elapsed ) / 60 );

			if ( $wait_time < 1 ) {
				return new \WP_Error( 'rate_limited', __( 'Too many verification codes requested. Please wait a few more seconds before requesting another code.', 'quick-2fa' ) );
			}

			return new \WP_Error(
				'rate_limited',
				sprintf(
					/* translators: %d: number of minutes to wait */
					__( 'Too many verification codes requested. Please wait %d minutes before requesting another code.', 'quick-2fa' ),
					$wait_time
				)
			);
		}

		// Increment counter.
		++$limit_data['count'];
		set_transient( $key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW );

		return true;
	}

	/**
	 * Check if code has expired.
	 *
	 * @since 0.4.0
	 * @return bool True if expired, false otherwise.
	 */
	public function is_expired(): bool {
		$code_timestamp = get_user_meta( $this->user_id, META_CODE_TIMESTAMP, true );

		if ( empty( $code_timestamp ) ) {
			return true;
		}

		$expiry_minutes = get_option( OPTION_CODE_EXPIRY, DEFAULT_CODE_EXPIRY );
		$expiry_seconds = $expiry_minutes * MINUTE_IN_SECONDS;

		return time() - $code_timestamp > $expiry_seconds;
	}

	/**
	 * Get number of remaining verification attempts.
	 *
	 * @since 0.4.0
	 * @return int Number of attempts remaining.
	 */
	public function get_remaining_attempts(): int {
		$attempts = (int) get_user_meta( $this->user_id, META_CODE_ATTEMPTS, true );
		return max( 0, RATE_LIMIT_VERIFICATION_MAX - $attempts );
	}

	/**
	 * Send verification code via email.
	 *
	 * Generates a new code, stores it securely, and sends it via email.
	 * Includes rate limiting to prevent abuse.
	 *
	 * @since 0.4.0
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function send_via_email(): true|\WP_Error {
		// Check rate limiting.
		$rate_check = $this->check_rate_limit();
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		// Generate code.
		$code = $this->generate();

		// Store hashed code.
		$this->store( $code );

		// Get user.
		$user = get_userdata( $this->user_id );
		if ( ! $user ) {
			return new \WP_Error( 'user_not_found', __( 'User not found.', 'quick-2fa' ) );
		}

		// Send email via Email_Handler.
		$email_handler = new Email_Handler();
		$result        = $email_handler->send_verification_code( $user, $code );

		// Log result.
		$security = new Account_Security_Handler( $this->user_id );
		$security->log_event(
			LOG_CODE_SENT,
			array(
				'timestamp' => time(),
				'email'     => $user->user_email,
				'success'   => $result['success'],
			)
		);

		if ( ! $result['success'] ) {
			return new \WP_Error( 'email_failed', $result['error'] );
		}

		return true;
	}

	/**
	 * Verify submitted code against stored hash.
	 *
	 * Checks for account locks, code expiry, and rate limiting before
	 * verifying the submitted code. Updates user metadata on success.
	 *
	 * @since 0.4.0
	 * @param string $code Submitted code.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function verify( string $code ): true|\WP_Error {
		$security = new Account_Security_Handler( $this->user_id );

		// Check if account is locked.
		if ( $security->is_locked() ) {
			$wait_time = ceil( $security->get_lock_time_remaining() / 60 );

			return new \WP_Error(
				'account_locked',
				sprintf(
					/* translators: %d: number of minutes until unlock */
					__( 'Your account has been temporarily locked due to too many failed attempts. Please try again in %d minutes.', 'quick-2fa' ),
					$wait_time
				)
			);
		}

		// Get stored hash.
		$hash = get_user_meta( $this->user_id, META_CODE_HASH, true );

		if ( empty( $hash ) ) {
			return new \WP_Error( 'no_code', __( 'No verification code found. Please request a new code.', 'quick-2fa' ) );
		}

		// Check if code has expired.
		if ( $this->is_expired() ) {
			// Clean up expired code.
			$this->cleanup();

			$expiry_minutes = get_option( OPTION_CODE_EXPIRY, DEFAULT_CODE_EXPIRY );
			return new \WP_Error(
				'expired',
				sprintf(
					/* translators: %d: number of minutes until expiry */
					__( 'Your verification code has expired. Codes are valid for %d minutes. Please request a new code.', 'quick-2fa' ),
					$expiry_minutes
				)
			);
		}

		// Check rate limiting on verification attempts.
		$attempts = (int) get_user_meta( $this->user_id, META_CODE_ATTEMPTS, true );

		if ( $attempts >= RATE_LIMIT_VERIFICATION_MAX ) {
			$security->lock_account();

			return new \WP_Error( 'too_many_attempts', __( 'Too many failed verification attempts. Your account has been temporarily locked for security.', 'quick-2fa' ) );
		}

		// Verify code against hash.
		if ( ! wp_check_password( $code, $hash ) ) {
			// Increment failure counter.
			update_user_meta( $this->user_id, META_CODE_ATTEMPTS, $attempts + 1 );

			$security->log_event(
				LOG_VERIFICATION_FAILED,
				array(
					'timestamp' => time(),
					'ip'        => get_ip_address(),
					'attempts'  => $attempts + 1,
				)
			);

			$remaining = RATE_LIMIT_VERIFICATION_MAX - ( $attempts + 1 );

			if ( $remaining > 0 ) {
				return new \WP_Error(
					'invalid_code',
					sprintf(
						/* translators: %d: number of attempts remaining */
						_n( 'Invalid verification code. You have %d attempt remaining.', 'Invalid verification code. You have %d attempts remaining.', $remaining, 'quick-2fa' ),
						$remaining
					)
				);
			} else {
				$security->lock_account();
				return new \WP_Error( 'too_many_attempts', __( 'Too many failed verification attempts. Your account has been temporarily locked for security.', 'quick-2fa' ) );
			}
		} // Success! Update verification timestamp and clean up.
		update_user_meta( $this->user_id, META_LAST_VERIFIED, time() );
		$this->cleanup();

		$security->log_event(
			LOG_VERIFICATION_SUCCESS,
			array(
				'timestamp' => time(),
				'ip'        => get_ip_address(),
			)
		);

		return true;
	}

	/**
	 * Clean up verification code metadata.
	 *
	 * @since 0.4.0
	 */
	private function cleanup(): void {
		delete_user_meta( $this->user_id, META_CODE_HASH );
		delete_user_meta( $this->user_id, META_CODE_TIMESTAMP );
		delete_user_meta( $this->user_id, META_CODE_ATTEMPTS );
	}
}
