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
	public function __construct( $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Check if user account is locked.
	 *
	 * @since 0.4.0
	 * @return bool True if account is locked.
	 */
	public function is_locked() {
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
	 * @param int $duration Lock duration in seconds (default: RATE_LIMIT_ACCOUNT_LOCK_DURATION).
	 */
	public function lock_account( $duration = null ) {
		if ( null === $duration ) {
			$duration = RATE_LIMIT_ACCOUNT_LOCK_DURATION;
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
	public function unlock_account() {
		delete_user_meta( $this->user_id, META_LOCKED_UNTIL );
	}

	/**
	 * Get time remaining until account unlocks.
	 *
	 * @since 0.4.0
	 * @return int Seconds remaining, or 0 if not locked.
	 */
	public function get_lock_time_remaining() {
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
	public function log_event( $event_type, $data = array() ) {
		$log_entry = array(
			'event_type' => $event_type,
			'timestamp'  => time(),
			'ip'         => get_ip_address(),
			'user_agent' => get_user_agent(),
			'data'       => $data,
		);

		// Get existing logs.
		$logs = get_user_meta( $this->user_id, META_LOGS, true );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		// Add new log.
		array_unshift( $logs, $log_entry );

		// Keep only last 50 entries per user.
		$logs = array_slice( $logs, 0, 50 );

		// Store updated logs.
		update_user_meta( $this->user_id, META_LOGS, $logs );
	}

	/**
	 * Get security event log.
	 *
	 * @since 0.4.0
	 * @param int $limit Maximum number of entries to return (default: 50).
	 * @return array Array of log entries.
	 */
	public function get_event_log( $limit = 50 ) {
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
	public function clear_event_log() {
		delete_user_meta( $this->user_id, META_LOGS );
	}

	/**
	 * Get client IP address.
	 *
	 * @since 0.4.0
	 * @return string IP address.
	 */
	public static function get_client_ip() {
		return get_ip_address();
	}

	/**
	 * Get client user agent.
	 *
	 * @since 0.4.0
	 * @return string User agent.
	 */
	public static function get_client_user_agent() {
		return get_user_agent();
	}
}
