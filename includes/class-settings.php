<?php
/**
 * Settings Class
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Plugin settings: typed, validated reads, plus the settings page.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Validated settings for this request, keyed by option name; null until loaded.
	 *
	 * @since 1.7.0
	 * @var ?array<string,mixed>
	 */
	private ?array $values = null;

	/**
	 * Validation rules, keyed by option name; null until first built.
	 *
	 * @since 1.7.0
	 * @var ?array<string,array{type: string, validate: callable, sanitize: ?string}>
	 */
	private ?array $rules = null;

	/**
	 * Invalid-value messages already logged this request, as keys; survives flush_cache().
	 *
	 * @since 1.7.1
	 * @var array<string,true>
	 */
	private array $logged_errors = array();

	/**
	 * Get a string setting.
	 *
	 * @since 1.7.0
	 * @param string $key Option name, an OPTION_* constant.
	 * @return string
	 * @throws \LogicException When $key is not a string setting.
	 */
	public function get_string( string $key ): string {
		$value = $this->get_value( $key );

		if ( ! is_string( $value ) ) {
			throw new \LogicException( sprintf( 'Quick 2FA: %s is not a string setting.', esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Get an integer setting.
	 *
	 * @since 1.7.0
	 * @param string $key Option name, an OPTION_* constant.
	 * @return int
	 * @throws \LogicException When $key is not an integer setting.
	 */
	public function get_int( string $key ): int {
		$value = $this->get_value( $key );

		if ( ! is_int( $value ) ) {
			throw new \LogicException( sprintf( 'Quick 2FA: %s is not an integer setting.', esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Get a boolean setting.
	 *
	 * @since 1.7.0
	 * @param string $key Option name, an OPTION_* constant.
	 * @return bool
	 * @throws \LogicException When $key is not a boolean setting.
	 */
	public function get_bool( string $key ): bool {
		$value = $this->get_value( $key );

		if ( ! is_bool( $value ) ) {
			throw new \LogicException( sprintf( 'Quick 2FA: %s is not a boolean setting.', esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Get an array setting.
	 *
	 * @since 1.7.0
	 * @param string $key Option name, an OPTION_* constant.
	 * @return array
	 * @throws \LogicException When $key is not an array setting.
	 */
	public function get_array( string $key ): array {
		$value = $this->get_value( $key );

		if ( ! is_array( $value ) ) {
			throw new \LogicException( sprintf( 'Quick 2FA: %s is not an array setting.', esc_html( $key ) ) );
		}

		return $value;
	}

	/**
	 * Drop the loaded settings when one of them is added, updated or deleted.
	 *
	 * @since 1.7.0
	 * @param mixed $option_name Name of the option that changed.
	 */
	public function flush_cache( mixed $option_name ): void {
		if ( is_string( $option_name ) && array_key_exists( $option_name, $this->get_rules() ) ) {
			$this->values = null;
		}
	}

	/**
	 * Get one validated setting, loading them all on first use.
	 *
	 * @since 1.7.0
	 * @param string $key Option name.
	 * @return mixed
	 * @throws \LogicException When $key is not a setting.
	 */
	private function get_value( string $key ): mixed {
		$values = $this->load();

		if ( ! array_key_exists( $key, $values ) ) {
			throw new \LogicException( sprintf( 'Quick 2FA: %s is not a setting.', esc_html( $key ) ) );
		}

		return $values[ $key ];
	}

	/**
	 * Read and validate every setting, logging each stored value that fails validation.
	 *
	 * A missing option or a blank string takes the default silently; an invalid
	 * stored value takes the default and is logged.
	 *
	 * @since 1.7.0
	 * @return array<string,mixed>
	 */
	private function load(): array {
		if ( null === $this->values ) {
			$defaults = get_default_settings();
			$values   = array();

			foreach ( $this->get_rules() as $key => $rule ) {
				// A null default marks a missing option: get_option() never returns null for a stored one.
				$stored = get_option( $key, null );
				$value  = $defaults[ $key ];

				if ( null !== $stored ) {
					$validated = ( $rule['validate'] )( $stored );

					if ( null === $validated ) {
						$this->log_error_once(
							sprintf(
								'%s: stored value %s is not valid; using the default %s.',
								$key,
								wp_json_encode( $stored ),
								wp_json_encode( $value )
							)
						);
					} elseif ( '' === $validated ) {
						// Blank text setting: the default applies.
					} else {
						$value = $validated;
					}
				}

				$values[ $key ] = $value;
			}

			$this->values = $values;
		}

		return $this->values;
	}

	/**
	 * Get the validation rules for every setting, keyed by option name.
	 *
	 * 'validate' returns the cleaned value, or null when the value is not valid.
	 * 'sanitize' names the method register_setting() calls on save; null for a
	 * setting with no field on the settings page, which must not be registered,
	 * because saving the page writes null to every registered option it lacks.
	 *
	 * @since 1.7.0
	 * @return array<string,array{type: string, validate: callable, sanitize: ?string}>
	 */
	private function get_rules(): array {
		if ( null === $this->rules ) {
			$this->rules = array(
				OPTION_MODE                       => array(
					'type'     => 'string',
					'validate' => fn( mixed $value ): ?string => $this->validate_mode( $value ),
					'sanitize' => 'sanitize_mode',
				),
				OPTION_PROTECTED_ROLES            => array(
					'type'     => 'array',
					'validate' => fn( mixed $value ): ?array => $this->validate_roles( $value ),
					'sanitize' => 'sanitize_protected_roles',
				),
				OPTION_VERIFICATION_PERIOD        => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, VERIFICATION_PERIOD_MIN, VERIFICATION_PERIOD_MAX ),
					'sanitize' => 'sanitize_verification_period',
				),
				OPTION_CODE_LENGTH                => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, CODE_LENGTH_MIN, CODE_LENGTH_MAX ),
					'sanitize' => 'sanitize_code_length',
				),
				OPTION_CODE_EXPIRY                => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, CODE_EXPIRY_MIN, CODE_EXPIRY_MAX ),
					'sanitize' => 'sanitize_code_expiry',
				),
				OPTION_EMAIL_FROM_NAME            => array(
					'type'     => 'string',
					'validate' => fn( mixed $value ): ?string => $this->validate_text( $value ),
					'sanitize' => 'sanitize_text',
				),
				OPTION_EMAIL_FROM_ADDRESS         => array(
					'type'     => 'string',
					'validate' => fn( mixed $value ): ?string => $this->validate_email_address( $value ),
					'sanitize' => 'sanitize_email_address',
				),
				OPTION_EMAIL_SUBJECT              => array(
					'type'     => 'string',
					'validate' => fn( mixed $value ): ?string => $this->validate_text( $value ),
					'sanitize' => 'sanitize_text',
				),
				OPTION_PASSWORD_REMINDERS_ENABLED => array(
					'type'     => 'boolean',
					'validate' => fn( mixed $value ): ?bool => $this->validate_boolean( $value ),
					'sanitize' => 'sanitize_checkbox',
				),
				OPTION_PASSWORD_REMINDER_PERIOD   => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, PASSWORD_REMINDER_PERIOD_MIN, PASSWORD_REMINDER_PERIOD_MAX ),
					'sanitize' => 'sanitize_reminder_period',
				),
				OPTION_PASSWORD_REMINDER_COOLDOWN => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, PASSWORD_REMINDER_COOLDOWN_MIN, PASSWORD_REMINDER_COOLDOWN_MAX ),
					'sanitize' => 'sanitize_reminder_cooldown',
				),
				OPTION_DISABLE_TRUSTED_DEVICES    => array(
					'type'     => 'boolean',
					'validate' => fn( mixed $value ): ?bool => $this->validate_boolean( $value ),
					'sanitize' => null,
				),
				OPTION_TRUSTED_DEVICE_EXPIRY      => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, TRUSTED_DEVICE_EXPIRY_MIN, TRUSTED_DEVICE_EXPIRY_MAX ),
					'sanitize' => 'sanitize_trusted_device_expiry',
				),
				OPTION_LOCKOUT_DURATION           => array(
					'type'     => 'integer',
					'validate' => fn( mixed $value ): ?int => $this->validate_range( $value, LOCKOUT_DURATION_MIN, LOCKOUT_DURATION_MAX ),
					'sanitize' => 'sanitize_lockout_duration',
				),
				OPTION_DELETE_DATA_ON_UNINSTALL   => array(
					'type'     => 'boolean',
					'validate' => fn( mixed $value ): ?bool => $this->validate_boolean( $value ),
					'sanitize' => 'sanitize_checkbox',
				),
				OPTION_ABILITIES_ENABLED          => array(
					'type'     => 'boolean',
					'validate' => fn( mixed $value ): ?bool => $this->validate_boolean( $value ),
					'sanitize' => 'sanitize_checkbox',
				),
			);
		}

		return $this->rules;
	}

	/**
	 * Validate a 2FA mode.
	 *
	 * @since 1.7.0
	 * @param mixed $value Value to check.
	 * @return ?string The mode, or null if it is not one of the MODE_* values.
	 */
	private function validate_mode( mixed $value ): ?string {
		return in_array( $value, array( MODE_ALL, MODE_ROLES, MODE_DISABLED ), true ) ? $value : null;
	}

	/**
	 * Validate a list of role slugs, keeping only roles that exist.
	 *
	 * @since 1.7.0
	 * @param mixed $value Value to check.
	 * @return ?array The surviving roles, or null if the value is not an array.
	 */
	private function validate_roles( mixed $value ): ?array {
		$roles = null;

		if ( is_array( $value ) ) {
			// array_values(): array_intersect() preserves the original keys, which
			// would store a sparse array whenever a submitted role was dropped.
			$roles = array_values( array_intersect( $value, array_keys( wp_roles()->get_names() ) ) );
		}

		return $roles;
	}

	/**
	 * Validate an integer within a range; numeric strings are accepted.
	 *
	 * @since 1.7.0
	 * @param mixed $value   Value to check.
	 * @param int   $minimum Lowest accepted value.
	 * @param int   $maximum Highest accepted value.
	 * @return ?int The integer, or null if it is not an integer in range.
	 */
	private function validate_range( mixed $value, int $minimum, int $maximum ): ?int {
		$number = filter_var(
			$value,
			FILTER_VALIDATE_INT,
			array(
				'options' => array(
					'min_range' => $minimum,
					'max_range' => $maximum,
				),
			)
		);

		return false === $number ? null : $number;
	}

	/**
	 * Validate a boolean; '1', 'yes', 'on', 'true' and their opposites are accepted.
	 *
	 * @since 1.7.0
	 * @param mixed $value Value to check.
	 * @return ?bool The boolean, or null if the value is not recognisably one.
	 */
	private function validate_boolean( mixed $value ): ?bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/**
	 * Validate a single line of text.
	 *
	 * @since 1.7.0
	 * @param mixed $value Value to check.
	 * @return ?string The sanitised text, possibly '', or null if it is not a string.
	 */
	private function validate_text( mixed $value ): ?string {
		return is_string( $value ) ? sanitize_text_field( $value ) : null;
	}

	/**
	 * Validate an email address.
	 *
	 * @since 1.7.0
	 * @param mixed $value Value to check.
	 * @return ?string The address, '' when blank, or null if it is not a valid address.
	 */
	private function validate_email_address( mixed $value ): ?string {
		$address = null;

		if ( is_string( $value ) && '' === trim( $value ) ) {
			$address = '';
		} elseif ( is_string( $value ) && is_email( sanitize_email( $value ) ) ) {
			$address = sanitize_email( $value );
		} else {
			// Not a string, or not a valid address.
		}

		return $address;
	}

	/**
	 * Log a settings problem; always written, whatever WP_DEBUG says.
	 *
	 * @since 1.7.0
	 * @param string $message Message to log.
	 */
	private function log_error( string $message ): void {
		error_log( __NAMESPACE__ . ' Settings [error]: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Invalid stored settings log unconditionally.
	}

	/**
	 * Log a settings problem unless the same message was already logged this request.
	 *
	 * @since 1.7.1
	 * @param string $message Message to log.
	 */
	private function log_error_once( string $message ): void {
		if ( ! array_key_exists( $message, $this->logged_errors ) ) {
			$this->logged_errors[ $message ] = true;
			$this->log_error( $message );
		}
	}

	/**
	 * Add settings page to WordPress admin menu.
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		add_options_page(
			/* translators: %s is the plugin name, Quick 2FA */
			sprintf( __( '%s Settings', 'quick-2fa' ), PLUGIN_NAME ),
			PLUGIN_NAME,
			'manage_options',
			SETTINGS_PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register every setting that has a field on the settings page.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		$defaults = get_default_settings();

		foreach ( $this->get_rules() as $key => $rule ) {
			if ( null === $rule['sanitize'] ) {
				continue;
			}

			register_setting(
				SETTINGS_GROUP,
				$key,
				array(
					'type'              => $rule['type'],
					'sanitize_callback' => array( $this, $rule['sanitize'] ),
					'default'           => $defaults[ $key ],
				)
			);
		}
	}

	/**
	 * Sanitize mode setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return string Sanitized mode value.
	 */
	public function sanitize_mode( mixed $value ): string {
		return $this->validate_mode( $value ) ?? DEFAULT_MODE;
	}

	/**
	 * Sanitize protected roles setting; no roles selected saves an empty list.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return array Sanitized roles array.
	 */
	public function sanitize_protected_roles( mixed $value ): array {
		return $this->validate_roles( $value ) ?? array();
	}

	/**
	 * Sanitize verification period setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized period value (1-365 days).
	 */
	public function sanitize_verification_period( mixed $value ): int {
		return $this->validate_range( $value, VERIFICATION_PERIOD_MIN, VERIFICATION_PERIOD_MAX ) ?? DEFAULT_VERIFICATION_PERIOD;
	}

	/**
	 * Sanitize code length setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized code length (4-10 digits).
	 */
	public function sanitize_code_length( mixed $value ): int {
		return $this->validate_range( $value, CODE_LENGTH_MIN, CODE_LENGTH_MAX ) ?? DEFAULT_CODE_LENGTH;
	}

	/**
	 * Sanitize code expiry setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized expiry value (5-60 minutes).
	 */
	public function sanitize_code_expiry( mixed $value ): int {
		return $this->validate_range( $value, CODE_EXPIRY_MIN, CODE_EXPIRY_MAX ) ?? DEFAULT_CODE_EXPIRY;
	}

	/**
	 * Sanitize reminder period setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized period value (1-365 days).
	 */
	public function sanitize_reminder_period( mixed $value ): int {
		return $this->validate_range( $value, PASSWORD_REMINDER_PERIOD_MIN, PASSWORD_REMINDER_PERIOD_MAX ) ?? DEFAULT_PASSWORD_REMINDER_PERIOD;
	}

	/**
	 * Sanitize reminder cooldown setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized cooldown value (1-90 days).
	 */
	public function sanitize_reminder_cooldown( mixed $value ): int {
		return $this->validate_range( $value, PASSWORD_REMINDER_COOLDOWN_MIN, PASSWORD_REMINDER_COOLDOWN_MAX ) ?? DEFAULT_PASSWORD_REMINDER_COOLDOWN;
	}

	/**
	 * Sanitize trusted device expiry setting.
	 *
	 * @since 1.0.0
	 * @param mixed $value Input value.
	 * @return int Sanitized expiry value (1-365 days).
	 */
	public function sanitize_trusted_device_expiry( mixed $value ): int {
		return $this->validate_range( $value, TRUSTED_DEVICE_EXPIRY_MIN, TRUSTED_DEVICE_EXPIRY_MAX ) ?? DEFAULT_TRUSTED_DEVICE_EXPIRY;
	}

	/**
	 * Sanitize lockout duration setting.
	 *
	 * @since 0.6.0
	 * @param mixed $value Input value.
	 * @return int Sanitized duration value (1-1440 minutes = 1 min to 24 hours).
	 */
	public function sanitize_lockout_duration( mixed $value ): int {
		return $this->validate_range( $value, LOCKOUT_DURATION_MIN, LOCKOUT_DURATION_MAX ) ?? DEFAULT_LOCKOUT_DURATION;
	}

	/**
	 * Sanitize a single-line text setting; a non-string saves as blank.
	 *
	 * @since 1.7.0
	 * @param mixed $value Input value.
	 * @return string
	 */
	public function sanitize_text( mixed $value ): string {
		return $this->validate_text( $value ) ?? '';
	}

	/**
	 * Sanitize an email address setting; an invalid address saves as blank.
	 *
	 * @since 1.7.0
	 * @param mixed $value Input value.
	 * @return string
	 */
	public function sanitize_email_address( mixed $value ): string {
		return $this->validate_email_address( $value ) ?? '';
	}

	/**
	 * Sanitize a checkbox setting; an unticked box is absent from the form, so saves as false.
	 *
	 * @since 1.7.0
	 * @param mixed $value Input value.
	 * @return bool
	 */
	public function sanitize_checkbox( mixed $value ): bool {
		return $this->validate_boolean( $value ) ?? false;
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @since 1.0.0
	 * @param mixed $hook Current admin page hook.
	 */
	public function enqueue_scripts( mixed $hook ): void {
		if ( 'settings_page_' . SETTINGS_PAGE_SLUG !== $hook ) {
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

		$mode                       = $this->get_string( OPTION_MODE );
		$protected_roles            = $this->get_array( OPTION_PROTECTED_ROLES );
		$verification_period        = $this->get_int( OPTION_VERIFICATION_PERIOD );
		$code_length                = $this->get_int( OPTION_CODE_LENGTH );
		$code_expiry                = $this->get_int( OPTION_CODE_EXPIRY );
		$email_from_name            = $this->get_string( OPTION_EMAIL_FROM_NAME );
		$email_from_address         = $this->get_string( OPTION_EMAIL_FROM_ADDRESS );
		$email_subject              = $this->get_string( OPTION_EMAIL_SUBJECT );
		$password_reminders_enabled = $this->get_bool( OPTION_PASSWORD_REMINDERS_ENABLED );
		$password_reminder_period   = $this->get_int( OPTION_PASSWORD_REMINDER_PERIOD );
		$password_reminder_cooldown = $this->get_int( OPTION_PASSWORD_REMINDER_COOLDOWN );
		$trusted_device_expiry      = $this->get_int( OPTION_TRUSTED_DEVICE_EXPIRY );
		$lockout_duration           = $this->get_int( OPTION_LOCKOUT_DURATION );
		$delete_data_on_uninstall   = $this->get_bool( OPTION_DELETE_DATA_ON_UNINSTALL );
		$abilities_enabled          = $this->get_bool( OPTION_ABILITIES_ENABLED );
		$is_abilities_api_available = function_exists( 'wp_register_ability' );

		$wp_roles  = wp_roles();
		$all_roles = $wp_roles->get_names();

		// Constants passed to template for use outside namespace context.
		$const_settings_group                    = SETTINGS_GROUP;
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
		$const_option_abilities_enabled          = OPTION_ABILITIES_ENABLED;
		$const_mode_all                          = MODE_ALL;
		$const_mode_roles                        = MODE_ROLES;
		$const_mode_disabled                     = MODE_DISABLED;

		require QUICK_2FA_PATH . 'views/settings-page.php';
	}
}
