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
		add_action( 'wp_login', array( $this, 'record_last_login' ), 10, 2 );
		add_action( 'wp_set_password', array( $this, 'revoke_devices_on_password_set' ), 10, 2 );
		add_action( 'profile_update', array( $this, 'revoke_devices_on_profile_update' ), 10, 2 );
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
		} else {
			// Stored version is already current.
		}
	}

	/**
	 * Record the time of a successful login on wp_login, for every user.
	 *
	 * See docs/how-it-works.md, "Last-login recording".
	 *
	 * @since 1.3.0
	 *
	 * @param mixed $user_login The user's login name.
	 * @param mixed $user       The user who logged in, when the caller supplies it.
	 */
	public function record_last_login( mixed $user_login, mixed $user = null ): void {
		// Some plugins fire wp_login with only the login name, so $user is optional.
		if ( ! $user instanceof \WP_User && is_string( $user_login ) ) {
			$user = get_user_by( 'login', $user_login );
		}

		$record = false;

		if ( $user instanceof \WP_User ) {
			/**
			 * Filter whether Quick 2FA records a last-login timestamp for this user.
			 *
			 * Return false to skip recording — for a service account, or to opt the
			 * site out of last-login recording entirely.
			 *
			 * @since 1.3.0
			 *
			 * @param bool     $record Whether to record the login. Default true.
			 * @param \WP_User $user   The user who logged in.
			 */
			$record = (bool) filter_var( apply_filters( 'quick2fa_record_last_login', true, $user ), FILTER_VALIDATE_BOOLEAN );
		}

		if ( $record ) {
			$now = time();

			// The first recorded login stamps the site's recording epoch; add_option()
			// is a no-op once it exists. See docs/developers/extending.md.
			add_option( OPTION_LAST_LOGIN_SINCE, $now, '', 'yes' );

			update_user_meta( $user->ID, META_LAST_LOGIN, $now );
		}
	}

	/**
	 * Revoke a user's trusted devices after wp_set_password() changes their password.
	 *
	 * Covers password resets and the password reminder page.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $password The new plaintext password (unused).
	 * @param mixed $user_id  The user whose password was set.
	 */
	public function revoke_devices_on_password_set( mixed $password, mixed $user_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Required by the wp_set_password signature.
		if ( is_numeric( $user_id ) && (int) $user_id > 0 ) {
			$this->revoke_devices_after_password_change( (int) $user_id );
		}
	}

	/**
	 * Revoke a user's trusted devices when a profile update changed their password.
	 *
	 * Core's wp_update_user() stores the new hash itself rather than calling wp_set_password(),
	 * so the profile page and `wp user update --user_pass` only reach this hook.
	 *
	 * @since 1.6.0
	 *
	 * @param mixed $user_id       The updated user.
	 * @param mixed $old_user_data The user as it was before the update.
	 */
	public function revoke_devices_on_profile_update( mixed $user_id, mixed $old_user_data ): void {
		$user = is_numeric( $user_id ) ? get_userdata( (int) $user_id ) : false;

		if ( $user instanceof \WP_User && $old_user_data instanceof \WP_User && $user->user_pass !== $old_user_data->user_pass ) {
			$this->revoke_devices_after_password_change( $user->ID );
		}
	}

	/**
	 * Revoke every trusted device for a user whose password has changed.
	 *
	 * @since 1.6.0
	 *
	 * @param int $user_id The user whose password changed.
	 */
	private function revoke_devices_after_password_change( int $user_id ): void {
		$security = new Account_Security_Handler( $user_id );
		$security->clear_trusted_devices(
			array(
				'source' => 'password_change',
				'reason' => 'password_changed',
			)
		);
	}

	/**
	 * Check if user is locked out during login.
	 *
	 * Blocks locked users from logging in (front-end or admin).
	 *
	 * @since 0.6.0
	 * @param mixed $user WP_User, or a WP_Error if an earlier filter failed.
	 * @return mixed WP_Error if the user is locked, otherwise $user unchanged.
	 */
	public function check_lockout_on_login( mixed $user ): mixed {
		$result = $user;

		// Anything but a WP_User passes through untouched, so an earlier filter's
		// WP_Error keeps its reason.
		if ( $user instanceof \WP_User ) {
			$security = new Account_Security_Handler( $user->ID );

			if ( $security->is_locked() ) {
				$result = new \WP_Error( 'account_locked', $this->get_lockout_message( $user->ID, $security ) );
			}
		}

		return $result;
	}

	/**
	 * Build the locked-account message for the login filter and the admin-area check.
	 *
	 * @since 1.3.0
	 * @param int                      $user_id  User whose lock is being described.
	 * @param Account_Security_Handler $security Handler already constructed for that user.
	 * @return string Translated message, unescaped.
	 */
	private function get_lockout_message( int $user_id, Account_Security_Handler $security ): string {
		$locked_until = (int) get_user_meta( $user_id, META_LOCKED_UNTIL, true );

		if ( $locked_until > time() + PERMANENT_LOCK_THRESHOLD ) {
			$message = __( 'Your account has been locked. Please contact your site administrator.', 'quick-2fa' );
		} else {
			$message = sprintf(
				/* translators: %s: Time remaining until unlock */
				__( 'Your account has been locked. Please try again in %s.', 'quick-2fa' ),
				human_time_diff( time(), time() + $security->get_lock_time_remaining() )
			);
		}

		return $message;
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
				wp_die(
					esc_html( $this->get_lockout_message( $user_id, $security ) ),
					esc_html__( 'Account Locked', 'quick-2fa' ),
					array( 'response' => 403 )
				);
			}
		}

		// Both redirect helpers exit(), so at most one of these runs and nothing
		// follows the chain.
		if ( $this->user_needs_verification() ) {
			$this->redirect_to_verification();
		} elseif ( $this->user_needs_password_reminder() ) {
			$this->redirect_to_password_reminder();
		} else {
			// Verified, and no password reminder due.
		}
	}

	/**
	 * Check whether the current user must verify before reaching the admin area.
	 *
	 * Trusted devices on: an untrusted device is challenged. Trusted devices off: each
	 * login session is challenged once. See docs/trusted-devices.md.
	 *
	 * @since 1.0.0
	 * @return bool True if verification is needed.
	 */
	private function user_needs_verification(): bool {
		$user_id = get_current_user_id();

		if ( ! $user_id || ! $this->user_role_requires_2fa( $user_id ) ) {
			return false;
		}

		// Default to requiring verification: a user who has never verified, or
		// whose state we cannot read, must be challenged.
		$last_verified      = (int) get_user_meta( $user_id, META_LAST_VERIFIED, true );
		$needs_verification = true;

		if ( $last_verified > 0 ) {
			$security_handler      = new Account_Security_Handler( $user_id );
			$session_verified_time = $security_handler->get_current_session_verified_time();

			if ( are_trusted_devices_enabled() ) {
				// A session that verified within the verification period stays verified, so
				// revoking devices (a password change, for one) doesn't re-challenge it.
				// Otherwise the device must be trusted; each entry carries its own expiry.
				$verification_period = (int) get_option( OPTION_VERIFICATION_PERIOD, DEFAULT_VERIFICATION_PERIOD ) * DAY_IN_SECONDS;
				$session_is_current  = $session_verified_time > 0 && ( time() - $session_verified_time ) < $verification_period;
				$needs_verification  = ! $session_is_current && ! $security_handler->is_device_trusted();
			} else {
				// Trusted devices disabled: every login session must verify once.
				$needs_verification = 0 === $session_verified_time;
			}
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
		$mode     = (string) get_option( OPTION_MODE, DEFAULT_MODE );
		$requires = false;

		if ( MODE_ALL === $mode ) {
			$requires = true;
		} elseif ( MODE_ROLES === $mode ) {
			$protected_roles = (array) get_option( OPTION_PROTECTED_ROLES, array() );
			$user            = get_userdata( $user_id );

			if ( ! empty( $protected_roles ) && $user instanceof \WP_User ) {
				$requires = ! empty( array_intersect( (array) $user->roles, $protected_roles ) );
			}
		} else {
			// MODE_DISABLED, or an unrecognised mode; $requires stays false.
		}

		return $requires;
	}

	/**
	 * Check if the current user is due a password reminder.
	 *
	 * @since 1.0.0
	 * @return bool True if password reminder is needed.
	 */
	private function user_needs_password_reminder(): bool {
		$user_id = get_current_user_id();

		return $user_id > 0 && ( new Password_Reminder_Handler( $user_id ) )->needs_reminder();
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
				wp_die( esc_html__( 'Invalid action', 'quick-2fa' ), esc_html_x( 'Error', 'error page title', 'quick-2fa' ), array( 'response' => 400 ) );
		}

		exit();
	}

	/**
	 * Grant device trust after a successful verification.
	 *
	 * Ticking the box trusts the device for the full configured expiry; leaving
	 * it unticked grants short-term trust matching the verification period, so
	 * the user isn't re-prompted on every login within that window. Either way
	 * the trust is carried by a secure cookie token set inside trust_device().
	 *
	 * @since 1.3.0
	 * @param int  $user_id      User who just verified.
	 * @param bool $trust_device Whether the user ticked "trust this device".
	 */
	private function apply_device_trust( int $user_id, bool $trust_device ): void {
		if ( ! are_trusted_devices_enabled() ) {
			return;
		}

		if ( $trust_device ) {
			$expiry_days = (int) get_option( OPTION_TRUSTED_DEVICE_EXPIRY, DEFAULT_TRUSTED_DEVICE_EXPIRY );
		} else {
			$expiry_days = (int) get_option( OPTION_VERIFICATION_PERIOD, DEFAULT_VERIFICATION_PERIOD );
		}

		$security_handler = new Account_Security_Handler( $user_id );
		$security_handler->trust_device( $expiry_days * DAY_IN_SECONDS );
	}

	/**
	 * Handle verification page.
	 *
	 * @since 1.0.0
	 */
	private function handle_verification_page(): void {
		$user_id = get_current_user_id();
		$user    = get_userdata( $user_id );
		$error   = null;
		$message = null;

		// The view dereferences $user directly. A logged-in session whose user
		// record has gone would otherwise fatal on the one page a locked-out
		// admin needs to reach.
		if ( ! $user instanceof \WP_User ) {
			wp_die(
				esc_html__( 'Your user account could not be loaded. Please sign in again.', 'quick-2fa' ),
				esc_html_x( 'Error', 'error page title', 'quick-2fa' ),
				array( 'response' => 403 )
			);
		}

		$is_post          = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$is_verify_submit = $is_post && isset( $_POST['q2fa_verify'] );
		$is_resend_submit = $is_post && isset( $_POST['q2fa_resend'] );

		if ( $is_verify_submit && ! verify_page_nonce( 'quick2fa_verify' ) ) {
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
					$trust_device = isset( $_POST['q2fa_trust_device'] ) && '1' === $_POST['q2fa_trust_device'];

					$this->apply_device_trust( $user_id, $trust_device );

					wp_safe_redirect( get_return_url( $user_id ) );
					exit();
				}
			}
		} elseif ( $is_resend_submit && ! verify_page_nonce( 'quick2fa_resend' ) ) {
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
			// Plain page load. Reloads, duplicate tabs and back-navigation reuse an
			// outstanding code; the Resend button above is what forces a fresh one.
			$code_handler = new Verification_Code_Handler( $user_id );

			if ( $code_handler->has_valid_code() ) {
				// Existing code still valid; nothing to send.
			} else {
				$result = $code_handler->send_via_email();

				if ( is_wp_error( $result ) ) {
					$error = $result;
				} else {
					// Sent.
				}
			}
		} else {
			// POST with no recognised submit button; render the form.
		}

		$trusted_devices_enabled = are_trusted_devices_enabled();
		$trusted_device_expiry   = (int) get_option( OPTION_TRUSTED_DEVICE_EXPIRY, DEFAULT_TRUSTED_DEVICE_EXPIRY );
		$verify_intro            = get_verify_intro();

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

		// The view dereferences $user directly — see handle_verification_page().
		if ( ! $user instanceof \WP_User ) {
			wp_die(
				esc_html__( 'Your user account could not be loaded. Please sign in again.', 'quick-2fa' ),
				esc_html_x( 'Error', 'error page title', 'quick-2fa' ),
				array( 'response' => 403 )
			);
		}

		$handler        = new Password_Reminder_Handler( $user_id );
		$days_since     = $handler->get_password_age();
		$new_password   = $handler->generate_strong_password();
		$password_intro = get_password_intro();

		$is_post          = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$is_update_submit = $is_post && isset( $_POST['q2fa_update_password'] );
		$is_later_submit  = $is_post && isset( $_POST['q2fa_remind_later'] );

		if ( $is_update_submit && ! verify_page_nonce( 'quick2fa_password' ) ) {
			$error = new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'quick-2fa' ) );
		} elseif ( $is_update_submit ) {
			// Unslashed only: sanitising would strip tags, %XX octets and repeated whitespace from the password.
			$password = isset( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Saved exactly as typed.
			$result   = $handler->update_password( $password );

			if ( is_wp_error( $result ) ) {
				$error = $result;
			} else {
				wp_safe_redirect( get_return_url( $user_id ) );
				exit();
			}
		} elseif ( $is_later_submit && ! verify_page_nonce( 'quick2fa_remind_later' ) ) {
			$error = new \WP_Error( 'invalid_nonce', __( 'Security check failed. Please try again.', 'quick-2fa' ) );
		} elseif ( $is_later_submit ) {
			$handler->dismiss_reminder();

			wp_safe_redirect( get_return_url( $user_id ) );
			exit();
		} else {
			// Plain page load, or a POST with no recognised submit button; render the page.
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

		$mode = (string) get_option( OPTION_MODE, DEFAULT_MODE );

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
