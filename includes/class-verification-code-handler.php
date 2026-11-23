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
	private int $user_id;

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
		$length = Plugin::instance()->get_settings()->get_int( OPTION_CODE_LENGTH );

		$max  = (int) str_repeat( '9', $length );
		$code = random_int( 0, $max );

		return str_pad( (string) $code, $length, '0', STR_PAD_LEFT );
	}

	/**
	 * Store hashed verification code.
	 *
	 * @since 0.4.0
	 * @since 1.8.1 Returns the stored hash, which identifies this code to discard_code().
	 * @param string $code Plain text code.
	 * @return string The stored hash.
	 */
	public function store( string $code ): string {
		$hash = wp_hash_password( $code );

		// The hash is written last: it is what makes the code live.
		update_user_meta( $this->user_id, META_CODE_TIMESTAMP, time() );
		update_user_meta( $this->user_id, META_CODE_ATTEMPTS, 0 );
		update_user_meta( $this->user_id, META_CODE_HASH, $hash );

		$security = new Account_Security_Handler( $this->user_id );
		$security->log_event(
			LOG_CODE_GENERATED,
			array(
				'timestamp' => time(),
				'ip'        => get_ip_address(),
			)
		);

		return $hash;
	}

	/**
	 * Check rate limiting for code generation.
	 *
	 * @since 0.4.0
	 * @return true|\WP_Error True if allowed, WP_Error if rate limited.
	 */
	public function check_rate_limit(): true|\WP_Error {
		$cache_key  = TRANSIENT_RATE_LIMIT . 'code_gen_' . $this->user_id;
		$limit_data = get_transient( $cache_key );

		// Determine if we need to start a fresh window: no prior data, or the previous window has elapsed.
		// Anything that is not our own array shape counts as "no window" rather than being indexed into.
		$is_window_missing = ! is_array( $limit_data ) || ! isset( $limit_data['window_start'], $limit_data['count'] );
		$elapsed           = $is_window_missing ? PHP_INT_MAX : ( time() - $limit_data['window_start'] );
		$is_new_window     = $is_window_missing || ( $elapsed > RATE_LIMIT_CODE_GENERATION_WINDOW );

		$result = true;

		if ( $is_new_window ) {
			$limit_data = array(
				'count'        => 1,
				'window_start' => time(),
			);
			set_transient( $cache_key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW );
		} elseif ( $limit_data['count'] >= RATE_LIMIT_CODE_GENERATION_MAX ) {
			$wait_time = (int) ceil( ( RATE_LIMIT_CODE_GENERATION_WINDOW - $elapsed ) / MINUTE_IN_SECONDS );

			if ( $wait_time < 1 ) {
				$result = new \WP_Error( 'rate_limited', __( 'Too many verification codes requested. Please wait a few more seconds before requesting another code.', 'quick-2fa' ) );
			} else {
				$result = new \WP_Error(
					'rate_limited',
					sprintf(
						/* translators: %d: number of minutes to wait */
						_n(
							'Too many verification codes requested. Please wait %d minute before requesting another code.',
							'Too many verification codes requested. Please wait %d minutes before requesting another code.',
							$wait_time,
							'quick-2fa'
						),
						$wait_time
					)
				);
			}
		} else {
			// Within the active window and under the limit — record this request.
			++$limit_data['count'];
			set_transient( $cache_key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW );
		}

		return $result;
	}

	/**
	 * Check if code has expired.
	 *
	 * @since 0.4.0
	 * @return bool True if expired, false otherwise.
	 */
	public function is_expired(): bool {
		// Default to expired: a code we cannot read the age of must not be accepted.
		$code_timestamp = (int) get_user_meta( $this->user_id, META_CODE_TIMESTAMP, true );
		$is_expired     = true;

		if ( $code_timestamp > 0 ) {
			$expiry_seconds = Plugin::instance()->get_settings()->get_int( OPTION_CODE_EXPIRY ) * MINUTE_IN_SECONDS;
			$is_expired     = ( time() - $code_timestamp ) > $expiry_seconds;
		}

		return $is_expired;
	}

	/**
	 * Check whether a live (stored and unexpired) code already exists.
	 *
	 * Used to keep page-load code delivery idempotent: a plain GET of the
	 * verification page should only email a new code when there isn't already
	 * a valid one outstanding. Reloads, duplicate tabs, and back-navigation
	 * then reuse the existing code instead of generating and emailing another.
	 *
	 * @since 1.2.0
	 * @return bool True if a stored code exists and has not expired.
	 */
	public function has_valid_code(): bool {
		$hash = get_user_meta( $this->user_id, META_CODE_HASH, true );

		return ! empty( $hash ) && ! $this->is_expired();
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
		$result = $this->check_rate_limit();

		if ( true === $result ) {
			// Resolve the user before generating anything: bailing out after
			// store() would leave a fresh code behind that nobody was sent.
			$user = get_userdata( $this->user_id );

			if ( ! $user instanceof \WP_User ) {
				$result = new \WP_Error( 'user_not_found', __( 'User not found.', 'quick-2fa' ) );
			} else {
				$code = $this->generate();
				$hash = $this->store( $code );

				$email_handler = new Email_Handler();
				$send_result   = $email_handler->send_verification_code( $user, $code );

				$security = new Account_Security_Handler( $this->user_id );
				$security->log_event(
					LOG_CODE_SENT,
					array(
						'timestamp' => time(),
						'email'     => $user->user_email,
						'success'   => $send_result['success'],
					)
				);

				if ( ! $send_result['success'] ) {
					// Drop the undelivered code, so the next page load sends a fresh one.
					$this->discard_code( $hash );

					$result = new \WP_Error( 'email_failed', $send_result['error'] );
				}
			}
		}

		return $result;
	}

	/**
	 * Lock the account and build the matching error.
	 *
	 * Both exhausted-attempt paths in verify() did this identically, so the
	 * message and the lock could drift apart.
	 *
	 * @since 1.3.0
	 * @param Account_Security_Handler $security Handler for this user.
	 * @return \WP_Error
	 */
	private function lock_and_build_error( Account_Security_Handler $security ): \WP_Error {
		$security->lock_account(
			null,
			array(
				'source' => 'verification',
				'reason' => 'failed_attempts',
			)
		);

		return new \WP_Error(
			'too_many_attempts',
			__( 'Too many failed verification attempts. Your account has been temporarily locked for security.', 'quick-2fa' )
		);
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

		// Top guards: account locked, no stored code, code expired, attempts exhausted.
		if ( $security->is_locked() ) {
			$wait_time = (int) ceil( $security->get_lock_time_remaining() / MINUTE_IN_SECONDS );

			return new \WP_Error(
				'account_locked',
				sprintf(
					/* translators: %d: number of minutes until unlock */
					_n(
						'Your account has been temporarily locked due to too many failed attempts. Please try again in %d minute.',
						'Your account has been temporarily locked due to too many failed attempts. Please try again in %d minutes.',
						$wait_time,
						'quick-2fa'
					),
					$wait_time
				)
			);
		}

		$hash = $this->read_from_database( META_CODE_HASH );

		if ( '' === $hash ) {
			return new \WP_Error( 'no_code', __( 'No verification code found. Please request a new code.', 'quick-2fa' ) );
		}

		if ( $this->is_expired() ) {
			$this->discard_code( $hash );

			$expiry_minutes = Plugin::instance()->get_settings()->get_int( OPTION_CODE_EXPIRY );

			return new \WP_Error(
				'expired',
				sprintf(
					/* translators: %d: number of minutes until expiry */
					_n(
						'Your verification code has expired. Codes are valid for %d minute. Please request a new code.',
						'Your verification code has expired. Codes are valid for %d minutes. Please request a new code.',
						$expiry_minutes,
						'quick-2fa'
					),
					$expiry_minutes
				)
			);
		}

		// Every guess, right or wrong, takes an attempt before the hash is checked.
		$attempt_number = $this->reserve_attempt();

		if ( 0 === $attempt_number ) {
			return $this->lock_and_build_error( $security );
		}

		// Main check: compare submitted code against the stored hash.
		$result = true;

		if ( ! wp_check_password( $code, $hash ) ) {
			$security->log_event(
				LOG_VERIFICATION_FAILED,
				array(
					'timestamp' => time(),
					'ip'        => get_ip_address(),
					'attempts'  => $attempt_number,
				)
			);

			$remaining = RATE_LIMIT_VERIFICATION_MAX - $attempt_number;

			if ( $remaining > 0 ) {
				$result = new \WP_Error(
					'invalid_code',
					sprintf(
						/* translators: %d: number of attempts remaining */
						_n( 'Invalid verification code. You have %d attempt remaining.', 'Invalid verification code. You have %d attempts remaining.', $remaining, 'quick-2fa' ),
						$remaining
					)
				);
			} else {
				$result = $this->lock_and_build_error( $security );
			}
		} elseif ( ! $this->discard_code( $hash ) ) {
			// A simultaneous request with the same code consumed it first.
			$result = new \WP_Error( 'no_code', __( 'No verification code found. Please request a new code.', 'quick-2fa' ) );
		} else {
			update_user_meta( $this->user_id, META_LAST_VERIFIED, time() );
			$security->mark_current_session_verified();

			$security->log_event(
				LOG_VERIFICATION_SUCCESS,
				array(
					'timestamp' => time(),
					'ip'        => get_ip_address(),
				)
			);
		}

		return $result;
	}

	/**
	 * Take the next verification attempt for the current code.
	 *
	 * The count is advanced with a compare-and-swap, so simultaneous guesses each take
	 * their own attempt instead of sharing one.
	 *
	 * @since 1.8.1
	 * @return int The attempt number taken (1 to RATE_LIMIT_VERIFICATION_MAX), or 0 if none is left.
	 */
	private function reserve_attempt(): int {
		$attempt_number = 0;

		for ( $try_count = 0; $try_count < ATTEMPT_RESERVE_TRIES; ++$try_count ) {
			$stored_count = $this->read_from_database( META_CODE_ATTEMPTS );
			$attempts     = (int) $stored_count;

			if ( $attempts >= RATE_LIMIT_VERIFICATION_MAX ) {
				break;
			}

			if ( $this->compare_and_set_attempts( $stored_count, $attempts + 1 ) ) {
				$attempt_number = $attempts + 1;
				break;
			}
		}

		return $attempt_number;
	}

	/**
	 * Set the attempt count only if it still holds the value read.
	 *
	 * Direct, because update_user_meta() skips the comparison for a previous value PHP
	 * considers empty, which includes '0'.
	 *
	 * @since 1.8.1
	 * @param string $expected_value The stored value read by read_from_database(), '' for none.
	 * @param int    $new_count      Count to store.
	 * @return bool True if this call changed it.
	 */
	private function compare_and_set_attempts( string $expected_value, int $new_count ): bool {
		global $wpdb;

		$is_set = false;

		if ( '' === $expected_value ) {
			// No row; store() always writes one, so this is state from an older version.
			$is_set = false !== add_user_meta( $this->user_id, META_CODE_ATTEMPTS, $new_count, true );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap; the cache is cleared below.
			$rows_changed = $wpdb->update(
				$wpdb->usermeta,
				array( 'meta_value' => (string) $new_count ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Keyed by user ID and meta key.
				array(
					'user_id'    => $this->user_id,
					'meta_key'   => META_CODE_ATTEMPTS, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Keyed by user ID.
					'meta_value' => $expected_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The compare in compare-and-swap.
				),
				array( '%s' ),
				array( '%d', '%s', '%s' )
			);
			wp_cache_delete( $this->user_id, 'user_meta' );
			$is_set = 1 === $rows_changed;
		}

		return $is_set;
	}

	/**
	 * Read one of this user's meta values from the database, not the object cache.
	 *
	 * A persistent object cache can briefly hold a value another request has since
	 * changed, and a compare-and-swap must compare against the stored one.
	 *
	 * @since 1.8.1
	 * @param string $meta_key Meta key.
	 * @return string The value, or '' if there is none.
	 */
	private function read_from_database( string $meta_key ): string {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses the cache on purpose; see above.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1", $this->user_id, $meta_key ) );

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Remove a code, if it is still the stored one, with its timestamp and attempt count.
	 *
	 * Deleting by value means only one request can remove a given code, and a newer code
	 * stored meanwhile is left alone.
	 *
	 * @since 1.8.1
	 * @param string $hash The hash of the code to remove.
	 * @return bool True if this call removed it.
	 */
	private function discard_code( string $hash ): bool {
		$is_removed = '' !== $hash && delete_user_meta( $this->user_id, META_CODE_HASH, $hash );

		if ( $is_removed ) {
			delete_user_meta( $this->user_id, META_CODE_TIMESTAMP );
			delete_user_meta( $this->user_id, META_CODE_ATTEMPTS );
		} else {
			// Already removed, or replaced by a newer code; nothing of this code is left.
		}

		return $is_removed;
	}
}
