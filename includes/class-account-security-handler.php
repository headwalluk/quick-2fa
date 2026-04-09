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
	private $user_id;

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
		$locked_until = get_user_meta( $this->user_id, META_LOCKED_UNTIL, true );

		if ( empty( $locked_until ) ) {
			return false;
		}

		if ( time() >= $locked_until ) {
			// Lock expired, clean up.
			$this->unlock_account();
			return false;
		}

		return true;
	}

	/**
	 * Lock user account.
	 *
	 * @since 0.4.0
	 * @param int $duration Lock duration in seconds (default: from OPTION_LOCKOUT_DURATION setting).
	 */
	public function lock_account( ?int $duration = null ): void {
		if ( null === $duration ) {
			$lockout_minutes = get_option( OPTION_LOCKOUT_DURATION, DEFAULT_LOCKOUT_DURATION );
			$duration        = $lockout_minutes * MINUTE_IN_SECONDS;
		}

		$lock_until = time() + $duration;

		update_user_meta( $this->user_id, META_LOCKED_UNTIL, $lock_until );

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
	}

	/**
	 * Get time remaining until account unlocks.
	 *
	 * @since 0.4.0
	 * @return int Seconds remaining, or 0 if not locked.
	 */
	public function get_lock_time_remaining(): int {
		if ( ! $this->is_locked() ) {
			return 0;
		}

		$locked_until = get_user_meta( $this->user_id, META_LOCKED_UNTIL, true );
		return max( 0, $locked_until - time() );
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
		$logs = array_slice( $logs, 0, 50 );
		update_user_meta( $this->user_id, META_LOGS, $logs );
	}

	/**
	 * Get security event log.
	 *
	 * @since 0.4.0
	 * @param int $limit Maximum number of entries to return (default: 50).
	 * @return array Array of log entries.
	 */
	public function get_event_log( int $limit = 50 ): array {
		$logs = get_user_meta( $this->user_id, META_LOGS, true );
		if ( ! is_array( $logs ) ) {
			return array();
		}

		return array_slice( $logs, 0, $limit );
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
	 * Get client IP address.
	 *
	 * @since 0.4.0
	 * @return string IP address.
	 */
	public static function get_client_ip(): string {
		return get_ip_address();
	}

	/**
	 * Get client user agent.
	 *
	 * @since 0.4.0
	 * @return string User agent.
	 */
	public static function get_client_user_agent(): string {
		return get_user_agent();
	}

	/**
	 * Get device fingerprint for current request.
	 *
	 * Creates a unique identifier for the current device based on
	 * IP address and user agent. This is used for trusted device tracking.
	 *
	 * @since 0.6.0
	 * @return string Device fingerprint hash.
	 */
	public function get_device_fingerprint(): string {
		$ip         = self::get_client_ip();
		$user_agent = self::get_client_user_agent();

		$fingerprint = $ip . '|' . $user_agent;
		return hash( 'sha256', $fingerprint );
	}

	/**
	 * Check if current device is trusted for this user.
	 *
	 * @since 0.6.0
	 * @return bool True if device is trusted and not expired.
	 */
	public function is_device_trusted(): bool {
		$current_fingerprint = $this->get_device_fingerprint();
		$trusted_devices     = get_user_meta( $this->user_id, META_TRUSTED_DEVICES, true );

		$is_trusted = false;

		if ( is_array( $trusted_devices ) && isset( $trusted_devices[ $current_fingerprint ] ) ) {
			$expiry = $trusted_devices[ $current_fingerprint ];

			if ( $expiry > time() ) {
				$is_trusted = true;
			} else {
				// Device expired, clean it up.
				unset( $trusted_devices[ $current_fingerprint ] );
				update_user_meta( $this->user_id, META_TRUSTED_DEVICES, $trusted_devices );
			}
		}

		return $is_trusted;
	}

	/**
	 * Trust current device for this user.
	 *
	 * Adds current device fingerprint to user's trusted devices list
	 * with an expiration timestamp.
	 *
	 * @since 0.6.0
	 * @return bool True on success.
	 */
	public function trust_device(): bool {
		$fingerprint     = $this->get_device_fingerprint();
		$trusted_devices = get_user_meta( $this->user_id, META_TRUSTED_DEVICES, true );

		if ( ! is_array( $trusted_devices ) ) {
			$trusted_devices = array();
		}

		$this->cleanup_expired_devices();

		$expiry_days                     = get_option( OPTION_TRUSTED_DEVICE_EXPIRY, DEFAULT_TRUSTED_DEVICE_EXPIRY );
		$expiry                          = time() + $expiry_days * DAY_IN_SECONDS;
		$trusted_devices[ $fingerprint ] = $expiry;

		return update_user_meta( $this->user_id, META_TRUSTED_DEVICES, $trusted_devices ) !== false;
	}

	/**
	 * Remove expired devices from user's trusted devices list.
	 *
	 * @since 0.6.0
	 * @return int Number of devices removed.
	 */
	public function cleanup_expired_devices(): int {
		$trusted_devices = get_user_meta( $this->user_id, META_TRUSTED_DEVICES, true );

		if ( ! is_array( $trusted_devices ) || empty( $trusted_devices ) ) {
			return 0;
		}

		$removed      = 0;
		$current_time = time();

		foreach ( $trusted_devices as $fingerprint => $expiry ) {
			if ( $expiry < $current_time ) {
				unset( $trusted_devices[ $fingerprint ] );
				++$removed;
			}
		}

		if ( $removed > 0 ) {
			update_user_meta( $this->user_id, META_TRUSTED_DEVICES, $trusted_devices );
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
