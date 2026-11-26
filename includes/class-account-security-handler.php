<?php
/**
 * Account Security Handler
 *
 * Handles account locking and security event logging.
 *
 * @package Quick_2FA
 * @since   0.4.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * Account Security Handler Class
 *
 * Manages account security features including:
 * - Account locking after failed attempts
 * - Security event logging
 * - IP address tracking
 * - Rate limiting
 *
 * @since 0.4.0
 */
class Account_Security_Handler {

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
	 * @param int $user_id User ID to handle security for.
	 */
	public function __construct( int $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Check if user account is locked.
	 *
	 * @since 0.4.0
	 * @return bool True if account is locked.
	 */
	public function is_locked(): bool {
		$stored_value = get_user_meta( $this->user_id, META_LOCKED_UNTIL, true );
		$locked_until = (int) $stored_value;
		$is_locked    = false;

		if ( $locked_until > 0 ) {
			if ( time() >= $locked_until ) {
				// Lock has elapsed — clear it, so the check is self-healing. Only the value
				// read here is deleted; if it has changed, a newer lock was written, so read
				// that instead. The failed-attempt count is left as it was; see docs/account-locking.md.
				if ( ! $this->clear_expired_lock( (string) $stored_value ) ) {
					$is_locked = $this->read_locked_until_from_database() > time();
				}
			} else {
				$is_locked = true;
			}
		}

		return $is_locked;
	}

	/**
	 * Lock the account, log one event, and fire quick2fa_account_locked.
	 *
	 * @since 0.4.0
	 * @since 1.6.0 Added $context, which is logged and passed to the action.
	 * @since 1.8.1 Returns whether the lock was saved; on failure nothing is logged as an event and the action doesn't fire.
	 * @param int|null $duration Lock duration in seconds, or null to use the OPTION_LOCKOUT_DURATION setting.
	 * @param array    $context  Who or what caused the lock: 'source' and 'reason', plus 'admin_id' from the admin UI.
	 * @return bool True if the lock is stored.
	 */
	public function lock_account( ?int $duration = null, array $context = array() ): bool {
		if ( null === $duration ) {
			$duration = Plugin::instance()->get_settings()->get_int( OPTION_LOCKOUT_DURATION ) * MINUTE_IN_SECONDS;
		}

		$locked_until = time() + $duration;

		update_user_meta( $this->user_id, META_LOCKED_UNTIL, $locked_until );
		delete_transient( TRANSIENT_LOCKED_COUNT );

		// update_user_meta() returns false for an unchanged value as well as a failure, so read it back.
		$is_stored = (int) get_user_meta( $this->user_id, META_LOCKED_UNTIL, true ) === $locked_until;

		if ( $is_stored ) {
			$this->log_event( LOG_ACCOUNT_LOCKED, array_merge( $context, array( 'locked_until' => $locked_until ) ) );

			/**
			 * Fires after an account is locked, automatically or by hand.
			 *
			 * @since 1.6.0
			 *
			 * @param int   $user_id      The locked user.
			 * @param int   $locked_until Unix timestamp the lock ends. A manual lock is set about 200 years ahead.
			 * @param array $context      Who or what caused the lock: 'source' and 'reason', plus 'admin_id' from the admin UI.
			 */
			do_action( 'quick2fa_account_locked', $this->user_id, $locked_until, $context );
		} else {
			$this->log_error( sprintf( 'the lock for user %d could not be saved', $this->user_id ) );
		}

		return $is_stored;
	}

	/**
	 * Unlock the account by hand, reset its failed-attempt count, log it, and fire quick2fa_account_unlocked.
	 *
	 * A lock that simply runs out is cleared by is_locked() instead, without this.
	 *
	 * @since 0.4.0
	 * @since 1.6.0 Added $context; now also resets the failed-attempt count, logs the event and fires the action.
	 * @since 1.8.1 Returns whether the lock was removed; on failure nothing is logged as an event and the action doesn't fire.
	 * @param array $context Who unlocked it: 'source' and 'reason', plus 'admin_id' from the admin UI.
	 * @return bool True if no lock remains.
	 */
	public function unlock_account( array $context = array() ): bool {
		$this->clear_lock();

		$is_removed = '' === get_user_meta( $this->user_id, META_LOCKED_UNTIL, true );

		if ( $is_removed ) {
			update_user_meta( $this->user_id, META_CODE_ATTEMPTS, 0 );

			$this->log_event( LOG_ACCOUNT_UNLOCKED, $context );

			/**
			 * Fires after an account is unlocked by hand, from the admin UI or WP-CLI.
			 *
			 * Doesn't fire when a timed lock runs out.
			 *
			 * @since 1.6.0
			 *
			 * @param int   $user_id The unlocked user.
			 * @param array $context Who unlocked it: 'source' and 'reason', plus 'admin_id' from the admin UI.
			 */
			do_action( 'quick2fa_account_unlocked', $this->user_id, $context );
		} else {
			$this->log_error( sprintf( 'the lock for user %d could not be removed', $this->user_id ) );
		}

		return $is_removed;
	}

	/**
	 * Write an error to the PHP error log, whatever WP_DEBUG says.
	 *
	 * @since 1.8.1
	 * @param string $message Message.
	 */
	private function log_error( string $message ): void {
		error_log( __NAMESPACE__ . ' Account_Security_Handler [error]: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Failed lock changes log unconditionally.
	}

	/**
	 * Remove the lock timestamp, whatever it holds, and the cached locked-user count.
	 *
	 * @since 1.6.0
	 */
	private function clear_lock(): void {
		delete_user_meta( $this->user_id, META_LOCKED_UNTIL );
		delete_transient( TRANSIENT_LOCKED_COUNT );
	}

	/**
	 * Remove the lock timestamp only if it still holds the expired value read, and the cached locked-user count.
	 *
	 * Direct, because delete_user_meta() selects the row by value and then deletes it by ID,
	 * which removes a lock written to the same row in between.
	 *
	 * @since 1.9.0
	 * @param string $expected_value The expired value read.
	 * @return bool True if this call removed it.
	 */
	private function clear_expired_lock( string $expected_value ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete; the cache is cleared below.
		$rows_deleted = $wpdb->delete(
			$wpdb->usermeta,
			array(
				'user_id'    => $this->user_id,
				'meta_key'   => META_LOCKED_UNTIL, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Keyed by user ID.
				'meta_value' => $expected_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The compare in compare-and-delete.
			),
			array( '%d', '%s', '%s' )
		);
		wp_cache_delete( $this->user_id, 'user_meta' );
		delete_transient( TRANSIENT_LOCKED_COUNT );

		return is_int( $rows_deleted ) && $rows_deleted > 0;
	}

	/**
	 * Read the lock timestamp from the database, not the object cache.
	 *
	 * @since 1.9.0
	 * @return int Unix timestamp, or 0 for no lock.
	 */
	private function read_locked_until_from_database(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses the cache on purpose; see clear_expired_lock().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1", $this->user_id, META_LOCKED_UNTIL ) );
	}

	/**
	 * Get time remaining until account unlocks.
	 *
	 * @since 0.4.0
	 * @return int Seconds remaining, or 0 if not locked.
	 */
	public function get_lock_time_remaining(): int {
		$remaining = 0;

		if ( $this->is_locked() ) {
			$locked_until = (int) get_user_meta( $this->user_id, META_LOCKED_UNTIL, true );
			$remaining    = max( 0, $locked_until - time() );
		}

		return $remaining;
	}

	/**
	 * Log security event.
	 *
	 * @since 0.4.0
	 * @param string $event_type Event type constant.
	 * @param array  $data       Additional event data.
	 */
	public function log_event( string $event_type, array $data = array() ): void {
		$log_entry = array(
			'event_type' => $event_type,
			'timestamp'  => time(),
			'ip'         => get_ip_address(),
			'user_agent' => get_user_agent(),
			'data'       => $data,
		);

		$logs = get_user_meta( $this->user_id, META_LOGS, true );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		array_unshift( $logs, $log_entry );
		$logs = array_slice( $logs, 0, LOG_MAX_ENTRIES );
		update_user_meta( $this->user_id, META_LOGS, $logs );
	}

	/**
	 * Get security event log.
	 *
	 * @since 0.4.0
	 * @param int $limit Maximum number of entries to return.
	 * @return array Array of log entries.
	 */
	public function get_event_log( int $limit = LOG_MAX_ENTRIES ): array {
		$logs = get_user_meta( $this->user_id, META_LOGS, true );

		return is_array( $logs ) ? array_slice( $logs, 0, $limit ) : array();
	}

	/**
	 * Clear security event log.
	 *
	 * @since 0.4.0
	 */
	public function clear_event_log(): void {
		delete_user_meta( $this->user_id, META_LOGS );
	}

	/**
	 * Get the installation-scoped name of the device-trust cookie.
	 *
	 * COOKIEHASH ties the cookie to this specific install, mirroring how core
	 * names its auth cookies.
	 *
	 * @since 1.2.0
	 * @return string Cookie name.
	 */
	private function get_device_cookie_name(): string {
		$suffix = defined( 'COOKIEHASH' ) ? COOKIEHASH : '';
		return COOKIE_DEVICE_TOKEN . '_' . $suffix;
	}

	/**
	 * Get the storage key identifying the current device, derived from its cookie.
	 *
	 * The browser holds a high-entropy random token in the device cookie; we
	 * never store that raw token, only its SHA-256 hash, which is the key used
	 * in META_TRUSTED_DEVICES. Returns an empty string when no usable cookie is
	 * present (cleared, disabled, or a device we have never trusted).
	 *
	 * @since 1.2.0
	 * @return string SHA-256 of the cookie token, or '' if no token is present.
	 */
	public function get_current_device_key(): string {
		$name = $this->get_device_cookie_name();
		$key  = '';

		if ( ! empty( $_COOKIE[ $name ] ) ) {
			// Tokens are bin2hex( random_bytes() ), so a hex-only value is expected;
			// stripping to [a-f0-9] is the sanitization (phpcs doesn't recognise it).
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the hex-only preg_replace below.
			$token = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) wp_unslash( $_COOKIE[ $name ] ) ) );

			if ( '' !== $token ) {
				$key = hash( 'sha256', $token );
			}
		}

		return $key;
	}

	/**
	 * Check if current device is trusted for this user.
	 *
	 * Trust is determined entirely by the device-trust cookie token (since
	 * 1.2.0): identity no longer depends on the client IP or User-Agent, both of
	 * which churn on modern connections (multi-WAN egress, CGNAT, mobile, IPv6
	 * privacy addressing) and caused spurious re-verification.
	 *
	 * @since 0.6.0
	 * @return bool True if device is trusted and not expired.
	 */
	public function is_device_trusted(): bool {
		$key        = $this->get_current_device_key();
		$is_trusted = false;

		if ( '' !== $key ) {
			$trusted_devices = $this->read_trusted_devices()['devices'];

			if ( isset( $trusted_devices[ $key ] ) ) {
				$expiry = (int) $trusted_devices[ $key ];

				if ( $expiry > time() ) {
					$is_trusted = true;
				} else {
					// Trust expired, clean it up.
					$this->change_trusted_devices(
						static function ( array $devices ) use ( $key ): array {
							unset( $devices[ $key ] );
							return $devices;
						}
					);
				}
			}
		}

		return $is_trusted;
	}

	/**
	 * Trust the current device for this user.
	 *
	 * Mints a fresh random token, sends it to the browser as a secure cookie,
	 * and records the token's hash (never the raw token) against an expiry in the
	 * user's trusted-device list. Must be called before any output is sent, as it
	 * sets a cookie header.
	 *
	 * @since 0.6.0
	 * @param int $expiry_seconds How long the trust should last, in seconds.
	 * @return bool True on success.
	 */
	public function trust_device( int $expiry_seconds ): bool {
		$token  = bin2hex( random_bytes( DEVICE_TOKEN_BYTES ) );
		$key    = hash( 'sha256', $token );
		$expiry = time() + $expiry_seconds;

		// Expired entries are pruned in the same change, so none is written back.
		$is_stored = $this->change_trusted_devices(
			static function ( array $devices ) use ( $key, $expiry ): array {
				$current_time = time();
				$devices      = array_filter( $devices, static fn( mixed $device_expiry ): bool => (int) $device_expiry >= $current_time );

				$devices[ $key ] = $expiry;

				return $devices;
			}
		);

		if ( $is_stored ) {
			$this->set_device_cookie( $token, $expiry );
		}

		return $is_stored;
	}

	/**
	 * Send the device-trust cookie to the browser.
	 *
	 * Scoped to SITECOOKIEPATH (which covers both wp-login.php and wp-admin,
	 * where the trust check runs) and marked HttpOnly + SameSite=Lax, and Secure
	 * whenever the request is over HTTPS.
	 *
	 * @since 1.2.0
	 * @param string $token  Raw token to store in the cookie.
	 * @param int    $expiry Absolute expiry timestamp.
	 */
	private function set_device_cookie( string $token, int $expiry ): void {
		$path        = defined( 'SITECOOKIEPATH' ) ? SITECOOKIEPATH : '/';
		$cookie_name = $this->get_device_cookie_name();

		setcookie(
			$cookie_name,
			$token,
			array(
				'expires'  => $expiry,
				'path'     => $path,
				'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		// Keep the current request consistent with what the browser will send back.
		$_COOKIE[ $cookie_name ] = $token;
	}

	/**
	 * Remove expired devices from user's trusted devices list.
	 *
	 * @since 0.6.0
	 * @return int Number of devices removed.
	 */
	public function cleanup_expired_devices(): int {
		$removed = 0;

		$this->change_trusted_devices(
			static function ( array $devices ) use ( &$removed ): array {
				$current_time = time();
				$kept         = array_filter( $devices, static fn( mixed $device_expiry ): bool => (int) $device_expiry >= $current_time );
				$removed      = count( $devices ) - count( $kept );

				return $kept;
			}
		);

		return $removed;
	}

	/**
	 * Revoke one trusted device by its key.
	 *
	 * @since 1.8.1
	 * @param string $device_key SHA-256 of the device's token.
	 * @return bool True if the device was in the list and has been removed.
	 */
	public function revoke_device( string $device_key ): bool {
		$was_listed = false;

		$is_saved = $this->change_trusted_devices(
			static function ( array $devices ) use ( $device_key, &$was_listed ): array {
				$was_listed = isset( $devices[ $device_key ] );
				unset( $devices[ $device_key ] );

				return $devices;
			}
		);

		return $is_saved && $was_listed;
	}

	/**
	 * Read the trusted-device list from the database, not the object cache.
	 *
	 * A persistent object cache can briefly hold a list from before a revocation, and
	 * change_trusted_devices() must compare against the stored row.
	 *
	 * @since 1.8.1
	 * @return array{raw: string, devices: array<string, int>} The stored row ('' for none) and its devices.
	 */
	private function read_trusted_devices(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bypasses the cache on purpose; see above.
		$raw_value = $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 1", $this->user_id, META_TRUSTED_DEVICES ) );
		$raw_value = is_string( $raw_value ) ? $raw_value : '';
		$devices   = maybe_unserialize( $raw_value );

		return array(
			'raw'     => $raw_value,
			'devices' => is_array( $devices ) ? $devices : array(),
		);
	}

	/**
	 * Apply a change to the trusted-device list, writing it only over the row it was read from.
	 *
	 * Another request may revoke or add devices between the read and the write. The write
	 * compares against the row read, and on a mismatch the change is applied again to the
	 * current list, so a stale copy can never restore a revoked device.
	 *
	 * @since 1.8.1
	 * @param callable $change Receives the current devices, returns the new devices.
	 * @return bool True once the change is stored.
	 */
	private function change_trusted_devices( callable $change ): bool {
		global $wpdb;

		$is_saved = false;

		for ( $try_count = 0; $try_count < DEVICE_LIST_WRITE_TRIES; ++$try_count ) {
			$stored      = $this->read_trusted_devices();
			$new_devices = $change( $stored['devices'] );

			if ( $new_devices === $stored['devices'] ) {
				$is_saved = true;
				break;
			}

			if ( '' === $stored['raw'] ) {
				$rows_changed = false === add_user_meta( $this->user_id, META_TRUSTED_DEVICES, $new_devices, true ) ? 0 : 1;
			} elseif ( empty( $new_devices ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete; the cache is cleared below.
				$rows_changed = $wpdb->delete(
					$wpdb->usermeta,
					array(
						'user_id'    => $this->user_id,
						'meta_key'   => META_TRUSTED_DEVICES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Keyed by user ID.
						'meta_value' => $stored['raw'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The compare in compare-and-delete.
					),
					array( '%d', '%s', '%s' )
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-swap; the cache is cleared below.
				$rows_changed = $wpdb->update(
					$wpdb->usermeta,
					array( 'meta_value' => maybe_serialize( $new_devices ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Keyed by user ID and meta key.
					array(
						'user_id'    => $this->user_id,
						'meta_key'   => META_TRUSTED_DEVICES, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Keyed by user ID.
						'meta_value' => $stored['raw'], // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- The compare in compare-and-swap.
					),
					array( '%s' ),
					array( '%d', '%s', '%s' )
				);
			}

			wp_cache_delete( $this->user_id, 'user_meta' );

			if ( 1 === $rows_changed ) {
				$is_saved = true;
				break;
			}
		}

		if ( ! $is_saved ) {
			$this->log_error( sprintf( 'the trusted-device list for user %d could not be saved', $this->user_id ) );
		}

		return $is_saved;
	}

	/**
	 * Remove all trusted devices for this user, logging the revocation when there were any.
	 *
	 * @since 0.6.0
	 * @since 1.6.0 Added $context, logs the event itself, and returns the number of devices removed.
	 * @param array $context Who or what revoked them: 'source' and 'reason', plus 'admin_id' from the admin UI.
	 * @return int Number of devices removed.
	 */
	public function clear_trusted_devices( array $context = array() ): int {
		$device_count = count( $this->read_trusted_devices()['devices'] );

		// An unconditional delete: a revocation wins over any change in flight.
		delete_user_meta( $this->user_id, META_TRUSTED_DEVICES );

		if ( $device_count > 0 ) {
			$this->log_event( LOG_ALL_DEVICES_REVOKED, array_merge( $context, array( 'device_count' => $device_count ) ) );
		}

		return $device_count;
	}

	/**
	 * Record that the current login session has passed verification.
	 *
	 * @since 1.4.0
	 */
	public function mark_current_session_verified(): void {
		$token = wp_get_session_token();

		if ( '' !== $token ) {
			$sessions = \WP_Session_Tokens::get_instance( $this->user_id );
			$session  = $sessions->get( $token );

			// get() returns null for an expired session or a token belonging to another user.
			if ( is_array( $session ) ) {
				$session[ SESSION_KEY_VERIFIED ] = time();
				$sessions->update( $token, $session );
			}
		}
	}

	/**
	 * Check whether the current login session has passed verification.
	 *
	 * @since 1.4.0
	 * @return bool True when the session carries a verification timestamp.
	 */
	public function is_current_session_verified(): bool {
		return $this->get_current_session_verified_time() > 0;
	}

	/**
	 * Get when the current login session passed verification.
	 *
	 * @since 1.6.0
	 * @return int Unix timestamp, or 0 if the session hasn't verified or there is no session.
	 */
	public function get_current_session_verified_time(): int {
		$token         = wp_get_session_token();
		$verified_time = 0;

		if ( '' !== $token ) {
			$session = \WP_Session_Tokens::get_instance( $this->user_id )->get( $token );

			if ( is_array( $session ) ) {
				$verified_time = (int) ( $session[ SESSION_KEY_VERIFIED ] ?? 0 );
			}
		}

		return $verified_time;
	}
}
