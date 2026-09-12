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
	private int $user_id;

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
		$reminders_enabled = (bool) filter_var(
			get_option( OPTION_PASSWORD_REMINDERS_ENABLED, DEFAULT_PASSWORD_REMINDERS_ENABLED ),
			FILTER_VALIDATE_BOOLEAN
		);

		$needs_reminder = false;

		if ( $reminders_enabled && get_userdata( $this->user_id ) instanceof \WP_User ) {
			$period_days = (int) get_option( OPTION_PASSWORD_REMINDER_PERIOD, DEFAULT_PASSWORD_REMINDER_PERIOD );

			$needs_reminder = $this->get_password_age() > $period_days && ! $this->is_in_cooldown();
		}

		return $needs_reminder;
	}

	/**
	 * Get password age in days.
	 *
	 * @since 0.4.0
	 * @return int Password age in days.
	 */
	public function get_password_age(): int {
		$last_pass_change = (int) get_user_meta( $this->user_id, META_PASSWORD_LAST_CHANGED, true );
		$age_days         = 0;

		if ( $last_pass_change <= 0 ) {
			// No baseline recorded yet — seed it from the registration date so age
			// is measured from something real, and store it for future reads.
			$user = get_userdata( $this->user_id );

			if ( $user instanceof \WP_User ) {
				$registered_at = strtotime( $user->user_registered );

				// An invalid or zero registration date ('0000-00-00 00:00:00')
				// would otherwise read as an infinitely old password.
				$last_pass_change = ( false === $registered_at || $registered_at < 0 ) ? time() : $registered_at;

				update_user_meta( $this->user_id, META_PASSWORD_LAST_CHANGED, $last_pass_change );
			}
		}

		if ( $last_pass_change > 0 ) {
			$age_days = (int) floor( ( time() - $last_pass_change ) / DAY_IN_SECONDS );
		}

		return $age_days;
	}

	/**
	 * Check if reminder is in cooldown period.
	 *
	 * @since 0.4.0
	 * @return bool True if in cooldown period.
	 */
	public function is_in_cooldown(): bool {
		$last_reminder = (int) get_user_meta( $this->user_id, META_LAST_PASSWORD_REMINDER, true );
		$in_cooldown   = false;

		if ( $last_reminder > 0 ) {
			$cooldown_seconds = (int) get_option( OPTION_PASSWORD_REMINDER_COOLDOWN, DEFAULT_PASSWORD_REMINDER_COOLDOWN ) * DAY_IN_SECONDS;
			$in_cooldown      = ( time() - $last_reminder ) < $cooldown_seconds;
		}

		return $in_cooldown;
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
		$result = true;

		if ( '' === $new_password ) {
			$result = new \WP_Error( 'empty_password', __( 'Please enter a password.', 'quick-2fa' ) );
		} elseif ( mb_strlen( $new_password ) < PASSWORD_MIN_LENGTH ) {
			// Counted in characters, not bytes, to match what the message promises.
			$result = new \WP_Error(
				'weak_password',
				sprintf(
					/* translators: %d: minimum number of characters required in a password */
					__( 'Password must be at least %d characters long.', 'quick-2fa' ),
					PASSWORD_MIN_LENGTH
				)
			);
		} else {
			$sessions      = \WP_Session_Tokens::get_instance( $this->user_id );
			$current_token = wp_get_session_token();
			$sessions->destroy_others( $current_token );

			wp_set_password( $new_password, $this->user_id );
			$this->maintain_session( $sessions, $current_token );
			update_user_meta( $this->user_id, META_PASSWORD_LAST_CHANGED, time() );

			$security = new Account_Security_Handler( $this->user_id );
			$security->log_event( LOG_PASSWORD_CHANGED );
		}

		return $result;
	}

	/**
	 * Dismiss password reminder.
	 *
	 * @since 0.4.0
	 */
	public function dismiss_reminder(): void {
		update_user_meta( $this->user_id, META_LAST_PASSWORD_REMINDER, time() );
		$security = new Account_Security_Handler( $this->user_id );
		$security->log_event( LOG_PASSWORD_REMINDER_DISMISSED );
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

		$length = max( PASSWORD_MIN_LENGTH, min( PASSWORD_MAX_LENGTH, $length ) );

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

		if ( is_array( $current_session ) ) {
			// Update the session with the new password hash verification.
			$sessions->update( $current_token, $current_session );
		}

		// Log the user back in to create a fresh session.
		wp_set_auth_cookie( $this->user_id, true );
	}
}
