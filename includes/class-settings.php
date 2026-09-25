<?php
/**
 * Settings Page Class
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Settings Page Class.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public function run(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Add settings page to WordPress admin menu.
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		add_options_page( __( 'Quick 2FA Settings', 'quick-2fa' ), __( 'Quick 2FA', 'quick-2fa' ), 'manage_options', 'quick-2fa', array( $this, 'render_settings_page' ) );
	}

	/**
	 * Register plugin settings.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		// Mode setting.
		register_setting(
			'quick2fa_settings',
			OPTION_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_mode' ),
				'default'           => DEFAULT_MODE,
			)
		);

		// Protected roles setting.
		register_setting(
			'quick2fa_settings',
			OPTION_PROTECTED_ROLES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_protected_roles' ),
				'default'           => array(),
			)
		);

		// Verification period setting.
		register_setting(
			'quick2fa_settings',
			OPTION_VERIFICATION_PERIOD,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_verification_period' ),
				'default'           => DEFAULT_VERIFICATION_PERIOD,
			)
		);

		// Code length setting.
		register_setting(
			'quick2fa_settings',
			OPTION_CODE_LENGTH,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_code_length' ),
				'default'           => DEFAULT_CODE_LENGTH,
			)
		);

		// Code expiry setting.
		register_setting(
			'quick2fa_settings',
			OPTION_CODE_EXPIRY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_code_expiry' ),
				'default'           => DEFAULT_CODE_EXPIRY,
			)
		);

		// Email from name setting.
		register_setting(
			'quick2fa_settings',
			OPTION_EMAIL_FROM_NAME,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		// Email from address setting.
		register_setting(
			'quick2fa_settings',
			OPTION_EMAIL_FROM_ADDRESS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_email',
				'default'           => '',
			)
		);

		// Email subject setting.
		register_setting(
			'quick2fa_settings',
			OPTION_EMAIL_SUBJECT,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		// Password reminders enabled setting.
		register_setting(
			'quick2fa_settings',
			OPTION_PASSWORD_REMINDERS_ENABLED,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => DEFAULT_PASSWORD_REMINDERS_ENABLED,
			)
		);

		// Password reminder period setting.
		register_setting(
			'quick2fa_settings',
			OPTION_PASSWORD_REMINDER_PERIOD,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_reminder_period' ),
				'default'           => DEFAULT_PASSWORD_REMINDER_PERIOD,
			)
		);

		// Password reminder cooldown setting.
		register_setting(
			'quick2fa_settings',
			OPTION_PASSWORD_REMINDER_COOLDOWN,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_reminder_cooldown' ),
				'default'           => DEFAULT_PASSWORD_REMINDER_COOLDOWN,
			)
		);

		// Trusted device expiry setting.
		register_setting(
			'quick2fa_settings',
			OPTION_TRUSTED_DEVICE_EXPIRY,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_trusted_device_expiry' ),
				'default'           => DEFAULT_TRUSTED_DEVICE_EXPIRY,
			)
		);

		// Lockout duration setting.
		register_setting(
			'quick2fa_settings',
			OPTION_LOCKOUT_DURATION,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_lockout_duration' ),
				'default'           => DEFAULT_LOCKOUT_DURATION,
			)
		);

		// Delete all plugin data on uninstall.
		register_setting(
			'quick2fa_settings',
			OPTION_DELETE_DATA_ON_UNINSTALL,
			array(
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => DEFAULT_DELETE_DATA_ON_UNINSTALL,
			)
		);
	}

	/**
	 * Sanitize mode setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return string Sanitized mode value.
	 */
	public function sanitize_mode( mixed $value ): string {
		$valid = array( MODE_ALL, MODE_ROLES, MODE_DISABLED );
		return in_array( $value, $valid, true ) ? $value : DEFAULT_MODE;
	}

	/**
	 * Clamp an integer setting to its allowed range.
	 *
	 * Every numeric setting validated the same way, as its own two-line copy
	 * with the bounds written inline. A value outside the range falls back to
	 * the setting's default rather than being silently clamped to an edge — an
	 * out-of-range submission is a mistake, not a preference.
	 *
	 * @since 1.3.0
	 * @param mixed $value    Submitted value.
	 * @param int   $minimum  Lowest accepted value.
	 * @param int   $maximum  Highest accepted value.
	 * @param int   $fallback Value to use when out of range.
	 * @return int
	 */
	private function sanitize_range( mixed $value, int $minimum, int $maximum, int $fallback ): int {
		$number = (int) $value;

		return ( $number >= $minimum && $number <= $maximum ) ? $number : $fallback;
	}

	/**
	 * Sanitize protected roles setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return array Sanitized roles array.
	 */
	public function sanitize_protected_roles( mixed $value ): array {
		$roles = array();

		if ( is_array( $value ) ) {
			$valid_roles = array_keys( wp_roles()->get_names() );

			// array_values(): array_intersect() preserves the original keys, which
			// would store a sparse array whenever a submitted role was dropped.
			$roles = array_values( array_intersect( $value, $valid_roles ) );
		}

		return $roles;
	}

	/**
	 * Sanitize verification period setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized period value (1-365 days).
	 */
	public function sanitize_verification_period( mixed $value ): int {
		return $this->sanitize_range( $value, VERIFICATION_PERIOD_MIN, VERIFICATION_PERIOD_MAX, DEFAULT_VERIFICATION_PERIOD );
	}

	/**
	 * Sanitize code length setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized code length (4-10 digits).
	 */
	public function sanitize_code_length( mixed $value ): int {
		return $this->sanitize_range( $value, CODE_LENGTH_MIN, CODE_LENGTH_MAX, DEFAULT_CODE_LENGTH );
	}

	/**
	 * Sanitize code expiry setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized expiry value (5-60 minutes).
	 */
	public function sanitize_code_expiry( mixed $value ): int {
		return $this->sanitize_range( $value, CODE_EXPIRY_MIN, CODE_EXPIRY_MAX, DEFAULT_CODE_EXPIRY );
	}

	/**
	 * Sanitize reminder period setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized period value (1-365 days).
	 */
	public function sanitize_reminder_period( mixed $value ): int {
		return $this->sanitize_range( $value, PASSWORD_REMINDER_PERIOD_MIN, PASSWORD_REMINDER_PERIOD_MAX, DEFAULT_PASSWORD_REMINDER_PERIOD );
	}

	/**
	 * Sanitize reminder cooldown setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized cooldown value (1-90 days).
	 */
	public function sanitize_reminder_cooldown( mixed $value ): int {
		return $this->sanitize_range( $value, PASSWORD_REMINDER_COOLDOWN_MIN, PASSWORD_REMINDER_COOLDOWN_MAX, DEFAULT_PASSWORD_REMINDER_COOLDOWN );
	}

	/**
	 * Sanitize trusted device expiry setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized expiry value (1-365 days).
	 */
	public function sanitize_trusted_device_expiry( mixed $value ): int {
		return $this->sanitize_range( $value, TRUSTED_DEVICE_EXPIRY_MIN, TRUSTED_DEVICE_EXPIRY_MAX, DEFAULT_TRUSTED_DEVICE_EXPIRY );
	}

	/**
	 * Sanitize lockout duration setting.
	 *
	 * @since 0.6.0
	 * @param mixed $value Input value.
	 * @return int Sanitized duration value (1-1440 minutes = 1 min to 24 hours).
	 */
	public function sanitize_lockout_duration( mixed $value ): int {
		return $this->sanitize_range( $value, LOCKOUT_DURATION_MIN, LOCKOUT_DURATION_MAX, DEFAULT_LOCKOUT_DURATION );
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param mixed $hook Current admin page hook.
	 */
	public function enqueue_scripts( mixed $hook ): void {
		if ( 'settings_page_quick-2fa' !== $hook ) {
			return;
		}

		wp_enqueue_style( 'q2fa-select2', QUICK_2FA_URL . 'assets/select2/select2.min.css', array(), ASSET_SELECT2_VERSION );
		wp_enqueue_script( 'q2fa-select2', QUICK_2FA_URL . 'assets/select2/select2.min.js', array( 'jquery' ), ASSET_SELECT2_VERSION, true );
		wp_enqueue_script(
			'quick-2fa-settings',
			QUICK_2FA_URL . 'assets/admin/settings.js',
			array( 'jquery', 'q2fa-select2' ),
			QUICK_2FA_VERSION,
			true
		);

		wp_localize_script(
			'quick-2fa-settings',
			'quick2faSettings',
			array(
				'selectRolesPlaceholder' => __( 'Select roles...', 'quick-2fa' ),
				'optionMode'             => OPTION_MODE,
				'modeRoles'              => MODE_ROLES,
			)
		);
	}

	/**
	 * Render settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mode                       = (string) get_option( OPTION_MODE, DEFAULT_MODE );
		$protected_roles            = (array) get_option( OPTION_PROTECTED_ROLES, get_default_protected_roles() );
		$verification_period        = (int) get_option( OPTION_VERIFICATION_PERIOD, DEFAULT_VERIFICATION_PERIOD );
		$code_length                = (int) get_option( OPTION_CODE_LENGTH, DEFAULT_CODE_LENGTH );
		$code_expiry                = (int) get_option( OPTION_CODE_EXPIRY, DEFAULT_CODE_EXPIRY );
		$email_from_name            = (string) get_option( OPTION_EMAIL_FROM_NAME, get_bloginfo( 'name' ) );
		$email_from_address         = (string) get_option( OPTION_EMAIL_FROM_ADDRESS, get_option( 'admin_email' ) );
		$email_subject              = (string) get_option( OPTION_EMAIL_SUBJECT, __( 'Your verification code', 'quick-2fa' ) );
		$password_reminders_enabled = (bool) filter_var( get_option( OPTION_PASSWORD_REMINDERS_ENABLED, DEFAULT_PASSWORD_REMINDERS_ENABLED ), FILTER_VALIDATE_BOOLEAN );
		$password_reminder_period   = (int) get_option( OPTION_PASSWORD_REMINDER_PERIOD, DEFAULT_PASSWORD_REMINDER_PERIOD );
		$password_reminder_cooldown = (int) get_option( OPTION_PASSWORD_REMINDER_COOLDOWN, DEFAULT_PASSWORD_REMINDER_COOLDOWN );
		$trusted_device_expiry      = (int) get_option( OPTION_TRUSTED_DEVICE_EXPIRY, DEFAULT_TRUSTED_DEVICE_EXPIRY );
		$lockout_duration           = (int) get_option( OPTION_LOCKOUT_DURATION, DEFAULT_LOCKOUT_DURATION );
		$delete_data_on_uninstall   = (bool) filter_var( get_option( OPTION_DELETE_DATA_ON_UNINSTALL, DEFAULT_DELETE_DATA_ON_UNINSTALL ), FILTER_VALIDATE_BOOLEAN );

		$wp_roles  = wp_roles();
		$all_roles = $wp_roles->get_names();

		// Constants passed to template for use outside namespace context.
		$const_option_mode                       = OPTION_MODE;
		$const_option_protected_roles            = OPTION_PROTECTED_ROLES;
		$const_option_verification_period        = OPTION_VERIFICATION_PERIOD;
		$const_option_code_length                = OPTION_CODE_LENGTH;
		$const_option_code_expiry                = OPTION_CODE_EXPIRY;
		$const_option_email_from_name            = OPTION_EMAIL_FROM_NAME;
		$const_option_email_from_address         = OPTION_EMAIL_FROM_ADDRESS;
		$const_option_email_subject              = OPTION_EMAIL_SUBJECT;
		$const_option_password_reminders_enabled = OPTION_PASSWORD_REMINDERS_ENABLED;
		$const_option_password_reminder_period   = OPTION_PASSWORD_REMINDER_PERIOD;
		$const_option_password_reminder_cooldown = OPTION_PASSWORD_REMINDER_COOLDOWN;
		$const_option_trusted_device_expiry      = OPTION_TRUSTED_DEVICE_EXPIRY;
		$const_option_lockout_duration           = OPTION_LOCKOUT_DURATION;
		$const_option_delete_data_on_uninstall   = OPTION_DELETE_DATA_ON_UNINSTALL;
		$const_mode_all                          = MODE_ALL;
		$const_mode_roles                        = MODE_ROLES;
		$const_mode_disabled                     = MODE_DISABLED;

		require QUICK_2FA_PATH . 'views/settings-page.php';
	}
}
