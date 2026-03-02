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
		if ( ! get_option( OPTION_PASSWORD_REMINDERS_ENABLED, DEFAULT_PASSWORD_REMINDERS_ENABLED ) ) {
			return false;
		}

		$user = get_userdata( $this->user_id );
		if ( ! $user ) {
			return false;
		}

		$password_age_days = $this->get_password_age();
		$period_days       = get_option( OPTION_PASSWORD_REMINDER_PERIOD, DEFAULT_PASSWORD_REMINDER_PERIOD );

		if ( $password_age_days <= $period_days ) {
			return false;
		}

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
		if ( empty( $new_password ) ) {
			return new \WP_Error( 'empty_password', __( 'Please enter a password.', 'quick-2fa' ) );
		}

		if ( strlen( $new_password ) < 8 ) {
			return new \WP_Error( 'weak_password', __( 'Password must be at least 8 characters long.', 'quick-2fa' ) );
		}

		$sessions      = \WP_Session_Tokens::get_instance( $this->user_id );
		$current_token = wp_get_session_token();
		$sessions->destroy_others( $current_token );

		wp_set_password( $new_password, $this->user_id );
		$this->maintain_session( $sessions, $current_token );
		update_user_meta( $this->user_id, '_password_last_changed', time() );

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
	 * Allows customization via 'quick2fa_password_parameters' filter.
	 * Falls back to secure defaults if filter returns invalid data.
	 *
	 * @since 0.4.0
	 * @return string Generated password.
	 */
	public function generate_strong_password(): string {
		$defaults = array(
			'length'              => random_int( DEFAULT_PASSWORD_LENGTH_MIN, DEFAULT_PASSWORD_LENGTH_MAX ),
			'special_chars'       => DEFAULT_PASSWORD_SPECIAL_CHARS,
			'extra_special_chars' => DEFAULT_PASSWORD_EXTRA_SPECIAL,
		);

		/**
		 * Filter password generation parameters.
		 *
		 * @since 0.8.0
		 *
		 * @param array $parameters {
		 *     Password generation parameters.
		 *
		 *     @type int  $length              Password length (8-64 characters).
		 *     @type bool $special_chars       Include special characters (!@#$%).
		 *     @type bool $extra_special_chars Include extra special characters (\-_ []{}<>~`+=,.;:/?|).
		 * }
		 */
		$parameters = apply_filters( 'quick2fa_password_parameters', $defaults );

		// Validate filtered parameters to prevent security issues.
		if ( ! is_array( $parameters ) || empty( $parameters ) ) {
			$parameters = $defaults;
		}

		// Validate and sanitize length (enforce minimum 8, maximum 64).
		$length = isset( $parameters['length'] ) && is_int( $parameters['length'] )
			? $parameters['length']
			: $defaults['length'];

		$length = max( 8, min( 64, $length ) );

		// Validate boolean flags.
		$special_chars = isset( $parameters['special_chars'] ) && is_bool( $parameters['special_chars'] )
			? $parameters['special_chars']
			: $defaults['special_chars'];

		$extra_special_chars = isset( $parameters['extra_special_chars'] ) && is_bool( $parameters['extra_special_chars'] )
			? $parameters['extra_special_chars']
			: $defaults['extra_special_chars'];

		return wp_generate_password( $length, $special_chars, $extra_special_chars );
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
