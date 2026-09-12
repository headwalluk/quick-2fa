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
		$locked_until = (int) get_user_meta( $this->user_id, META_LOCKED_UNTIL, true );
		$is_locked    = false;

		if ( $locked_until > 0 ) {
			if ( time() >= $locked_until ) {
				// Lock has elapsed — clear it, so the check is self-healing.
				$this->unlock_account();
			} else {
				$is_locked = true;
			}
		}

		return $is_locked;
	}

	/**
	 * Lock user account.
	 *
	 * @since 0.4.0
	 * @param int|null $duration Lock duration in seconds, or null to use the OPTION_LOCKOUT_DURATION setting.
	 */
	public function lock_account( ?int $duration = null ): void {
		if ( null === $duration ) {
			$duration = (int) get_option( OPTION_LOCKOUT_DURATION, DEFAULT_LOCKOUT_DURATION ) * MINUTE_IN_SECONDS;
		}

		$lock_until = time() + $duration;

		update_user_meta( $this->user_id, META_LOCKED_UNTIL, $lock_until );
		delete_transient( TRANSIENT_LOCKED_COUNT );

		$this->log_event(
			LOG_ACCOUNT_LOCKED,
			array(
				'timestamp'    => time(),
				'locked_until' => $lock_until,
				'ip'           => get_ip_address(),
			)
		);
	}

	/**
	 * Unlock user account.
	 *
	 * @since 0.4.0
	 */
	public function unlock_account(): void {
		delete_user_meta( $this->user_id, META_LOCKED_UNTIL );
		delete_transient( TRANSIENT_LOCKED_COUNT );
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
			$trusted_devices = get_user_meta( $this->user_id, META_TRUSTED_DEVICES, true );

			if ( is_array( $trusted_devices ) && isset( $trusted_devices[ $key ] ) ) {
				$expiry = (int) $trusted_devices[ $key ];

				if ( $expiry > time() ) {
					$is_trusted = true;
				} else {
					// Trust expired, clean it up.
					unset( $trusted_devices[ $key ] );
					update_user_meta( $this->user_id, META_TRUSTED_DEVICES, $trusted_devices );
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
		// Prune first, then read: adding to a pre-cleanup snapshot could
		// resurrect an entry that had just expired.
		$this->cleanup_expired_devices();

		$trusted_devices = get_user_meta( $this->user_id, META_TRUSTED_DEVICES, true );

		if ( ! is_array( $trusted_devices ) ) {
			$trusted_devices = array();
		}

		$token  = bin2hex( random_bytes( DEVICE_TOKEN_BYTES ) );
		$key    = hash( 'sha256', $token );
		$expiry = time() + $expiry_seconds;

		$trusted_devices[ $key ] = $expiry;
		$stored                  = update_user_meta( $this->user_id, META_TRUSTED_DEVICES, $trusted_devices ) !== false;

		if ( $stored ) {
			$this->set_device_cookie( $token, $expiry );
		}

		return $stored;
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
		$trusted_devices = get_user_meta( $this->user_id, META_TRUSTED_DEVICES, true );
		$removed         = 0;

		if ( is_array( $trusted_devices ) && ! empty( $trusted_devices ) ) {
			$current_time = time();

			foreach ( $trusted_devices as $device_key => $expiry ) {
				if ( (int) $expiry < $current_time ) {
					unset( $trusted_devices[ $device_key ] );
					++$removed;
				}
			}

			if ( $removed > 0 ) {
				update_user_meta( $this->user_id, META_TRUSTED_DEVICES, $trusted_devices );
			}
		}

		return $removed;
	}

	/**
	 * Remove all trusted devices for this user.
	 *
	 * @since 0.6.0
	 * @return bool True on success.
	 */
	public function clear_trusted_devices(): bool {
		return delete_user_meta( $this->user_id, META_TRUSTED_DEVICES );
	}
}
