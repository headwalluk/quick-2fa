<?php
/**
 * Password Reminder Handler
 *
 * Handles password age checking and reminder functionality.
 *
 * @package Quick_2FA
 * @since   0.4.0
 */

namespace Quick_2FA;

// Block direct access.
defined( 'ABSPATH' ) || die();

/**
 * Password Reminder Handler Class
 *
 * Manages password reminders and updates including:
 * - Password age calculation
 * - Reminder cooldown tracking
 * - Password updates with session management
 * - Strong password generation
 *
 * @since 0.4.0
 */
class Password_Reminder_Handler {

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
	 * @param int $user_id User ID to handle password reminders for.
	 */
	public function __construct( int $user_id ) {
		$this->user_id = $user_id;
	}

	/**
	 * Check if user needs a password reminder.
	 *
	 * @since 0.4.0
	 * @return bool True if password reminder is needed.
	 */
	public function needs_reminder(): bool {
		// Check if feature is enabled.
		if ( ! get_option( OPTION_PASSWORD_REMINDERS_ENABLED, DEFAULT_PASSWORD_REMINDERS_ENABLED ) ) {
			return false;
		}

		$user = get_userdata( $this->user_id );
		if ( ! $user ) {
			return false;
		}

		// Get password age in days.
		$password_age_days = $this->get_password_age();

		// Get reminder period (in days).
		$period_days = get_option( OPTION_PASSWORD_REMINDER_PERIOD, DEFAULT_PASSWORD_REMINDER_PERIOD );

		// Check if password is old enough.
		if ( $password_age_days <= $period_days ) {
			return false;
		}

		// Check if in cooldown period.
		if ( $this->is_in_cooldown() ) {
			return false;
		}

		return true;
	}

	/**
	 * Get password age in days.
	 *
	 * @since 0.4.0
	 * @return int Password age in days.
	 */
	public function get_password_age(): int {
		$last_pass_change = get_user_meta( $this->user_id, '_password_last_changed', true );

		if ( empty( $last_pass_change ) ) {
			// Fall back to user registration date as baseline.
			$user = get_userdata( $this->user_id );
			if ( ! $user ) {
				return 0;
			}

			$reg_timestamp = strtotime( $user->user_registered );

			// Handle invalid/zero registration dates (e.g., '0000-00-00 00:00:00').
			if ( false === $reg_timestamp || 0 > $reg_timestamp ) {
				$reg_timestamp = time();
			}

			$last_pass_change = $reg_timestamp;
			// Set it now for future tracking.
			update_user_meta( $this->user_id, '_password_last_changed', $last_pass_change );
		}

		// Calculate days since last password change.
		$time_since_change = time() - $last_pass_change;
		return floor( $time_since_change / DAY_IN_SECONDS );
	}

	/**
	 * Check if reminder is in cooldown period.
	 *
	 * @since 0.4.0
	 * @return bool True if in cooldown period.
	 */
	public function is_in_cooldown(): bool {
		$last_reminder = get_user_meta( $this->user_id, META_LAST_PASSWORD_REMINDER, true );

		if ( empty( $last_reminder ) ) {
			return false;
		}

		$cooldown_days    = get_option( OPTION_PASSWORD_REMINDER_COOLDOWN, DEFAULT_PASSWORD_REMINDER_COOLDOWN );
		$cooldown_seconds = $cooldown_days * DAY_IN_SECONDS;

		$time_since_reminder = time() - $last_reminder;

		return $time_since_reminder < $cooldown_seconds;
	}

	/**
	 * Update user password with session management.
	 *
	 * Updates the user's password while maintaining their current session
	 * and destroying all other sessions.
	 *
	 * @since 0.4.0
	 * @param string $new_password New password.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function update_password( string $new_password ): true|\WP_Error {
		// Validate password.
		if ( empty( $new_password ) ) {
			return new \WP_Error( 'empty_password', __( 'Please enter a password.', 'quick-2fa' ) );
		}

		if ( strlen( $new_password ) < 8 ) {
			return new \WP_Error( 'weak_password', __( 'Password must be at least 8 characters long.', 'quick-2fa' ) );
		}

		// Get current session info before password change.
		$sessions      = \WP_Session_Tokens::get_instance( $this->user_id );
		$current_token = wp_get_session_token();

		// Clear other sessions first.
		$sessions->destroy_others( $current_token );

		// Update user password.
		wp_set_password( $new_password, $this->user_id );

		// Maintain current session.
		$this->maintain_session( $sessions, $current_token );

		// Update last password changed timestamp.
		update_user_meta( $this->user_id, '_password_last_changed', time() );

		// Log the event.
		$security = new Account_Security_Handler( $this->user_id );
		$security->log_event( LOG_PASSWORD_CHANGED );

		return true;
	}

	/**
	 * Dismiss password reminder.
	 *
	 * @since 0.4.0
	 */
	public function dismiss_reminder(): void {
		update_user_meta( $this->user_id, META_LAST_PASSWORD_REMINDER, time() );
		$security = new Account_Security_Handler( $this->user_id );
		$security->log_event( 'password_reminder_dismissed' );
	}

	/**
	 * Generate a strong password.
	 *
	 * @since 0.4.0
	 * @param int $length Password length (default 16).
	 * @return string Generated password.
	 */
	public function generate_strong_password( int $length = 16 ): string {
		return wp_generate_password( $length, true, true );
	}

	/**
	 * Maintain user session after password change.
	 *
	 * WordPress invalidates sessions on password change, so we need to restore it.
	 *
	 * @since 0.4.0
	 * @param \WP_Session_Tokens $sessions      Session tokens instance.
	 * @param string             $current_token Current session token.
	 */
	private function maintain_session( \WP_Session_Tokens $sessions, string $current_token ): void {
		// Force user to stay logged in by updating the session token.
		$current_session = $sessions->get( $current_token );
		if ( $current_session ) {
			// Update the session with the new password hash verification.
			$sessions->update( $current_token, $current_session );
		}

		// Log the user back in to create a fresh session.
		wp_set_auth_cookie( $this->user_id, true );
	}
}
