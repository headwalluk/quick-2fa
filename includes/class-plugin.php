<?php
/**
 * Main Plugin Class
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * Main Plugin Class.
 *
 * @since 1.0.0
 */
class Plugin {

	/**
	 * Single instance of the class.
	 *
	 * @since 1.0.0
	 * @var ?Plugin
	 */
	private static $instance = null;

	/**
	 * Plugin settings instance.
	 *
	 * @since 1.0.0
	 * @var Settings
	 */
	private $settings;

	/**
	 * Get single instance of the class.
	 *
	 * @since 1.0.0
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public function run(): void {
		$this->settings = new Settings();
		$this->settings->run();

		$user_management = new User_Management();
		$user_management->run();

		// Supports MU plugin installation where activation hooks don't fire.
		add_action( 'admin_init', array( $this, 'check_first_run' ), 1 );
		add_action( 'admin_init', array( $this, 'check_verification' ), 1 );
		add_filter( 'wp_authenticate_user', array( $this, 'check_lockout_on_login' ), 10, 1 );
		add_action( 'login_init', array( $this, 'handle_login_actions' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_assets' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin text domain for translations.
	 *
	 * WordPress auto-loads translations from wordpress.org's language packs since 4.6,
	 * but this is still needed for bundled translations shipped in the plugin's own
	 * languages/ directory (e.g. MU plugin installs, pre-approval distribution).
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'quick-2fa', false, dirname( QUICK_2FA_BASENAME ) . '/languages' );
	}

	/**
	 * Enqueue styles and scripts for 2FA login pages.
	 *
	 * @since 0.9.3
	 */
	public function enqueue_login_assets(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Public login page, no nonce needed.
		if ( ! isset( $_GET[ QUERY_PARAM ] ) ) {
			return;
		}

		$action = sanitize_key( $_GET[ QUERY_PARAM ] );
		// phpcs:enable

		wp_enqueue_style(
			'quick-2fa-login',
			QUICK_2FA_URL . 'assets/css/login-pages.css',
			array( 'login' ),
			QUICK_2FA_VERSION
		);

		// Password reminder page needs the show/hide-password toggle script.
		if ( ACTION_PASSWORD === $action ) {
			wp_enqueue_script(
				'quick-2fa-password-page',
				QUICK_2FA_URL . 'assets/js/password-page.js',
				array(),
				QUICK_2FA_VERSION,
				true
			);
		}
	}

	/**
	 * Check if this is the first run and initialize default options.
	 *
	 * This ensures defaults are set even when installed as MU plugin
	 * (where activation hooks don't fire).
	 *
	 * @since 0.6.0
	 */
	public function check_first_run(): void {
		if ( false === get_option( OPTION_VERSION ) ) {
			$defaults = get_default_settings();

			foreach ( $defaults as $key => $value ) {
				if ( false === get_option( $key ) ) {
					add_option( $key, $value, '', 'yes' );
				}
			}

			add_option( OPTION_VERSION, QUICK_2FA_VERSION, '', 'yes' );
		} elseif ( get_option( OPTION_VERSION ) !== QUICK_2FA_VERSION ) {
			update_option( OPTION_VERSION, QUICK_2FA_VERSION );
		}
	}

	/**
	 * Check if user is locked out during login.
	 *
	 * Blocks locked users from logging in (front-end or admin).
	 *
	 * @since 0.6.0
	 * @param \WP_User|\WP_Error $user     WP_User or WP_Error object if previous filter failed.
	 * @return \WP_User|\WP_Error WP_User on success, WP_Error if locked.
	 */
	public function check_lockout_on_login( \WP_User|\WP_Error $user ): \WP_User|\WP_Error {
        // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Required by wp_authenticate_user filter.
		if ( is_wp_error( $user ) ) {
			return $user;
		}

		$security = new Account_Security_Handler( $user->ID );
		if ( $security->is_locked() ) {
			$time_remaining = $security->get_lock_time_remaining();
			$locked_until   = get_user_meta( $user->ID, META_LOCKED_UNTIL, true );

			// Permanent locks use a timestamp far in the future.
			if ( $locked_until > time() + 100 * YEAR_IN_SECONDS ) {
				$error_message = __( 'Your account has been locked. Please contact your site administrator.', 'quick-2fa' );
			} else {
				$error_message = sprintf(
					/* translators: %s: Time remaining until unlock */
					__( 'Your account has been locked. Please try again in %s.', 'quick-2fa' ),
					human_time_diff( time(), time() + $time_remaining )
				);
			}

			return new \WP_Error( 'account_locked', $error_message );
		}

		return $user;
	}

	/**
	 * Check if user needs verification on admin access.
	 *
	 * @since 1.0.0
	 */
	public function check_verification(): void {
		if ( should_skip_check() ) {
			return;
		}

		// Lockout applies to ALL users regardless of 2FA settings.
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$security = new Account_Security_Handler( $user_id );
			if ( $security->is_locked() ) {
				wp_logout();

				$locked_until = get_user_meta( $user_id, META_LOCKED_UNTIL, true );

				if ( $locked_until > time() + 100 * YEAR_IN_SECONDS ) {
					$error_message = __( 'Your account has been locked. Please contact your site administrator.', 'quick-2fa' );
				} else {
					$error_message = sprintf(
						/* translators: %s: Time remaining until unlock */
						__( 'Your account has been locked. Please try again in %s.', 'quick-2fa' ),
						human_time_diff( time(), time() + $security->get_lock_time_remaining() )
					);
				}

				wp_die( esc_html( $error_message ), esc_html__( 'Account Locked', 'quick-2fa' ), array( 'response' => 403 ) );
			}
		}

		if ( $this->user_needs_verification() ) {
			$this->redirect_to_verification();
			return;
		}

		if ( $this->user_needs_password_reminder() ) {
			$this->redirect_to_password_reminder();
			return;
		}
	}

	/**
	 * Check if current user needs verification.
	 *
	 * Security model (when trusted devices enabled):
	 * 1. Unknown devices always require verification (device-based security)
	 * 2. Trusted devices skip verification until their trust expires (e.g., 30 days)
	 *
	 * When trusted devices are disabled, falls back to time-based verification period.
	 *
	 * This prevents the security hole where anyone with the password can access
	 * an account from an unknown device.
	 *
	 * @since 1.0.0
	 * @return bool True if verification is needed.
	 */
	private function user_needs_verification(): bool {
		$user_id = get_current_user_id();

		if ( ! $user_id ) {
			return false;
		}

		if ( ! $this->user_role_requires_2fa( $user_id ) ) {
			return false;
		}

		$last_verified = (int) get_user_meta( $user_id, META_LAST_VERIFIED, true );

		if ( empty( $last_verified ) ) {
			return true;
		}

		$trusted_devices_enabled = ! get_option( OPTION_DISABLE_TRUSTED_DEVICES, DEFAULT_DISABLE_TRUSTED_DEVICES );
		$needs_verification      = false;

		if ( $trusted_devices_enabled ) {
			// If trusted devices feature is enabled, device trust is the primary check.
			// Each trusted device entry carries its own expiry (e.g., 30 days), so the
			// time-based verification period is not needed here. Unknown devices will
			// fail the trust check and require verification regardless.
			$security_handler   = new Account_Security_Handler( $user_id );
			$needs_verification = ! $security_handler->is_device_trusted();
		} else {
			// Trusted devices disabled — fall back to time-based verification period.
			$period_days         = get_option( OPTION_VERIFICATION_PERIOD, DEFAULT_VERIFICATION_PERIOD );
			$period_seconds      = $period_days * DAY_IN_SECONDS;
			$time_since_verified = time() - $last_verified;
			$needs_verification  = $time_since_verified > $period_seconds;
		}

		return $needs_verification;
	}

	/**
	 * Check if user's role requires 2FA.
	 *
	 * @since 1.0.0
	 * @param int $user_id User ID.
	 * @return bool True if user's role requires 2FA.
	 */
	private function user_role_requires_2fa( int $user_id ): bool {
		$mode = get_option( OPTION_MODE, DEFAULT_MODE );

		if ( MODE_ALL === $mode ) {
			return true;
		}

		if ( MODE_ROLES !== $mode ) {
			return false;
		}

		$protected_roles = get_option( OPTION_PROTECTED_ROLES, array() );
		$user            = get_userdata( $user_id );
		$requires        = false;

		if ( ! empty( $protected_roles ) && $user ) {
			$user_roles = (array) $user->roles;
			$requires   = ! empty( array_intersect( $user_roles, $protected_roles ) );
		}

		return $requires;
	}

	/**
	 * Check if current user needs password reminder.
	 *
	 * @since 1.0.0
	 * @return bool True if password reminder is needed.
	 */
	private function user_needs_password_reminder(): bool {
		if ( ! get_option( OPTION_PASSWORD_REMINDERS_ENABLED, DEFAULT_PASSWORD_REMINDERS_ENABLED ) ) {
			return false;
		}

		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		$last_pass_change = get_user_meta( $user_id, '_password_last_changed', true );

		if ( empty( $last_pass_change ) ) {
			// Fall back to user registration date as baseline.
			$reg_timestamp = strtotime( $user->user_registered );

			// Handle invalid/zero registration dates (e.g., '0000-00-00 00:00:00').
			if ( false === $reg_timestamp || 0 > $reg_timestamp ) {
				$reg_timestamp = time();
			}
			$last_pass_change = $reg_timestamp;
			// Set it now for future tracking.
			update_user_meta( $user_id, '_password_last_changed', $last_pass_change );
		}

		$period_days       = get_option( OPTION_PASSWORD_REMINDER_PERIOD, DEFAULT_PASSWORD_REMINDER_PERIOD );
		$period_seconds    = $period_days * DAY_IN_SECONDS;
		$time_since_change = time() - $last_pass_change;

		$needs_reminder = false;

		if ( $time_since_change > $period_seconds ) {
			$needs_reminder = true;

			// Respect cooldown — don't nag the user every page load.
			$last_reminder = get_user_meta( $user_id, META_LAST_PASSWORD_REMINDER, true );

			if ( ! empty( $last_reminder ) ) {
				$cooldown_days       = get_option( OPTION_PASSWORD_REMINDER_COOLDOWN, DEFAULT_PASSWORD_REMINDER_COOLDOWN );
				$cooldown_seconds    = $cooldown_days * DAY_IN_SECONDS;
				$time_since_reminder = time() - $last_reminder;

				if ( $time_since_reminder < $cooldown_seconds ) {
					$needs_reminder = false;
				}
			}
		}

		return $needs_reminder;
	}

	/**
	 * Redirect user to verification page.
	 *
	 * @since 1.0.0
	 */
	private function redirect_to_verification(): void {
		$user_id = get_current_user_id();
		store_return_url( $user_id );
		wp_safe_redirect( get_verify_url() );
		exit();
	}

	/**
	 * Redirect user to password reminder page.
	 *
	 * @since 1.0.0
	 */
	private function redirect_to_password_reminder(): void {
		$user_id = get_current_user_id();
		store_return_url( $user_id );
		wp_safe_redirect( get_password_url() );
		exit();
	}

	/**
	 * Handle login actions for 2FA pages.
	 *
	 * @since 1.0.0
	 */
	public function handle_login_actions(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public page identifier.
		if ( ! isset( $_GET[ QUERY_PARAM ] ) ) {
			return;
		}

		// Disable Query Monitor output for security (prevents debug info leakage on login pages).
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Query Monitor hook.
		do_action( 'qm/cease' );

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url() );
			exit();
		}

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public page identifier.
		$action = sanitize_key( $_GET[ QUERY_PARAM ] );

		switch ( $action ) {
			case ACTION_VERIFY:
				$this->handle_verification_page();
				break;

			case ACTION_PASSWORD:
				$this->handle_password_page();
				break;

			default:
				wp_die( esc_html__( 'Invalid action', 'quick-2fa' ), esc_html__( 'Error', 'quick-2fa' ), array( 'response' => 400 ) );
		}

		exit();
	}

	/**
	 * Handle verification page.
	 *
	 * @since 1.0.0
	 */
	private function handle_verification_page(): void {
		$user_id = get_current_user_id();
		$error   = null;
		$message = null;

		$is_post          = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$is_verify_submit = $is_post && isset( $_POST['q2fa_verify'] );
		$is_resend_submit = $is_post && isset( $_POST['q2fa_resend'] );

		if ( $is_verify_submit && ( ! array_key_exists( '_wpnonce', $_POST ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'quick2fa_verify' ) ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce validation.
			$error = new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'quick-2fa' ) );
		} elseif ( $is_verify_submit ) {
			$code = isset( $_POST['q2fa_code'] ) ? sanitize_text_field( wp_unslash( $_POST['q2fa_code'] ) ) : '';

			if ( empty( $code ) ) {
				$error = new \WP_Error( 'empty_code', __( 'Please enter the verification code.', 'quick-2fa' ) );
			} else {
				$code_handler = new Verification_Code_Handler( $user_id );
				$result       = $code_handler->verify( $code );

				if ( is_wp_error( $result ) ) {
					$error = $result;
				} else {
					if ( ! get_option( OPTION_DISABLE_TRUSTED_DEVICES, DEFAULT_DISABLE_TRUSTED_DEVICES ) ) {
						$security_handler = new Account_Security_Handler( $user_id );

						$trust_device = isset( $_POST['q2fa_trust_device'] ) && '1' === $_POST['q2fa_trust_device'];
						if ( $trust_device ) {
							$security_handler->trust_device();
						} else {
							// Short-term trust matching verification period instead of full device trust duration.
							$verification_period_days = get_option( OPTION_VERIFICATION_PERIOD, DEFAULT_VERIFICATION_PERIOD );
							$expiry                   = time() + $verification_period_days * DAY_IN_SECONDS;

							$trusted_devices = get_user_meta( $user_id, META_TRUSTED_DEVICES, true );
							if ( ! is_array( $trusted_devices ) ) {
								$trusted_devices = array();
							}

							$fingerprint                     = $security_handler->get_device_fingerprint();
							$trusted_devices[ $fingerprint ] = $expiry;
							update_user_meta( $user_id, META_TRUSTED_DEVICES, $trusted_devices );
						}
					}

					$return_url = get_return_url( $user_id );
					wp_safe_redirect( $return_url );
					exit();
				}
			}
		} elseif ( $is_resend_submit && ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'quick2fa_resend' ) ) ) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce validation.
			$error = new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'quick-2fa' ) );
		} elseif ( $is_resend_submit ) {
			$code_handler = new Verification_Code_Handler( $user_id );
			$result       = $code_handler->send_via_email();
			if ( is_wp_error( $result ) ) {
				$error = $result;
			} else {
				$message = __( 'A new verification code has been sent to your email.', 'quick-2fa' );
			}
		} elseif ( ! $is_post ) {
			$code_handler = new Verification_Code_Handler( $user_id );
			$result       = $code_handler->send_via_email();
			if ( is_wp_error( $result ) ) {
				$error = $result;
			}
		}

		$trusted_devices_enabled = ! get_option( OPTION_DISABLE_TRUSTED_DEVICES, DEFAULT_DISABLE_TRUSTED_DEVICES );
		$trusted_device_expiry   = get_option( OPTION_TRUSTED_DEVICE_EXPIRY, DEFAULT_TRUSTED_DEVICE_EXPIRY );
		$verify_intro            = get_verify_intro();

		$user = get_userdata( $user_id );

		require QUICK_2FA_PATH . 'views/verification-page.php';
	}

	/**
	 * Handle password reminder page.
	 *
	 * @since 1.0.0
	 */
	private function handle_password_page(): void {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$error   = null;
		$message = null;

		$handler        = new Password_Reminder_Handler( $user_id );
		$days_since     = $handler->get_password_age();
		$new_password   = $handler->generate_strong_password();
		$password_intro = get_password_intro();

		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			if ( isset( $_POST['q2fa_update_password'] ) ) {
				if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'quick2fa_password' ) ) {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce validation.
					$error = new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'quick-2fa' ) );
				} else {
					$password = isset( $_POST['password'] ) ? sanitize_text_field( wp_unslash( $_POST['password'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Password needs special chars.
					$result   = $handler->update_password( $password );

					if ( is_wp_error( $result ) ) {
						$error = $result;
					} else {
						$return_url = get_return_url( $user_id );
						wp_safe_redirect( $return_url );
						exit();
					}
				}
			} elseif ( isset( $_POST['q2fa_remind_later'] ) ) {
				if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'quick2fa_remind_later' ) ) {
                    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce validation.
					$error = new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'quick-2fa' ) );
				} else {
					$handler->dismiss_reminder();

					$return_url = get_return_url( $user_id );
					wp_safe_redirect( $return_url );
					exit();
				}
			}
		}

		require QUICK_2FA_PATH . 'views/password-page.php';
	}

	/**
	 * Display admin notices.
	 *
	 * @since 1.0.0
	 */
	public function admin_notices(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$mode = get_option( OPTION_MODE, DEFAULT_MODE );

		if ( MODE_DISABLED === $mode ) {
			$settings_url = admin_url( 'options-general.php?page=quick-2fa' );

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				esc_html__( 'Quick 2FA is currently disabled.', 'quick-2fa' ),
				esc_html__( 'Your admin area is not protected by two-factor authentication.', 'quick-2fa' ),
				esc_url( $settings_url ),
				esc_html__( 'Enable 2FA', 'quick-2fa' )
			);
		}
	}
}
