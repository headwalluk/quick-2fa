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
	private static ?Plugin $instance = null;

	/**
	 * Plugin settings instance; created on first use by get_settings().
	 *
	 * @since 1.0.0
	 * @var ?Settings
	 */
	private ?Settings $settings = null;

	/**
	 * Users table and profile screen instance.
	 *
	 * @since 1.7.0
	 * @var User_Management
	 */
	private User_Management $user_management;

	/**
	 * Abilities API registration and callbacks.
	 *
	 * @since 1.8.0
	 * @var Abilities
	 */
	private Abilities $abilities;

	/**
	 * Users whose wp_set_password action has fired this request, for revoke_devices_on_profile_update().
	 *
	 * @since 1.8.0
	 * @var array<int,bool>
	 */
	private array $password_set_user_ids = array();

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
	 * Get the settings instance, creating it on first use.
	 *
	 * @since 1.7.0
	 * @return Settings
	 */
	public function get_settings(): Settings {
		if ( is_null( $this->settings ) ) {
			$this->settings = new Settings();
		}

		return $this->settings;
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public function run(): void {
		$settings              = $this->get_settings();
		$this->user_management = new User_Management();
		$this->abilities       = new Abilities();

		// Settings: reload validated values after any write, and the settings page.
		add_action( 'added_option', array( $settings, 'flush_cache' ) );
		add_action( 'updated_option', array( $settings, 'flush_cache' ) );
		add_action( 'deleted_option', array( $settings, 'flush_cache' ) );
		add_action( 'admin_menu', array( $settings, 'add_settings_page' ) );
		add_action( 'admin_init', array( $settings, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $settings, 'enqueue_scripts' ) );

		// Users table: lockout column, sorting, status views and row actions.
		add_filter( 'manage_users_columns', array( $this->user_management, 'add_lockout_column' ) );
		add_action( 'manage_users_custom_column', array( $this->user_management, 'render_lockout_column' ), 10, 3 );
		add_filter( 'manage_users_sortable_columns', array( $this->user_management, 'make_column_sortable' ) );
		add_filter( 'users_list_table_query_args', array( $this->user_management, 'apply_lock_status_query' ) );
		add_filter( 'views_users', array( $this->user_management, 'add_lockout_filters' ) );
		add_filter( 'user_row_actions', array( $this->user_management, 'add_lockout_actions' ), 10, 2 );
		add_action( 'admin_action_quick2fa_lock', array( $this->user_management, 'handle_lock_user' ) );
		add_action( 'admin_action_quick2fa_unlock', array( $this->user_management, 'handle_unlock_user' ) );
		add_action( 'admin_notices', array( $this->user_management, 'render_queued_notice' ) );

		// User profile screen: security section and device revocation.
		add_action( 'show_user_profile', array( $this->user_management, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( $this->user_management, 'render_profile_section' ) );
		add_action( 'admin_enqueue_scripts', array( $this->user_management, 'enqueue_profile_styles' ) );
		add_action( 'admin_action_quick2fa_revoke_device', array( $this->user_management, 'handle_revoke_device' ) );
		add_action( 'admin_action_quick2fa_revoke_all_devices', array( $this->user_management, 'handle_revoke_all_devices' ) );

		// Abilities API (WordPress 6.9+): read-only queries, registered only when enabled.
		add_action( 'wp_abilities_api_categories_init', array( $this->abilities, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->abilities, 'register_abilities' ) );

		// Supports MU plugin installation where activation hooks don't fire.
		add_action( 'admin_init', array( $this, 'check_first_run' ), 1 );
		add_action( 'admin_init', array( $this, 'check_verification' ), 1 );
		add_filter( 'wp_authenticate_user', array( $this, 'check_lockout_on_login' ), 10, 1 );
		add_action( 'wp_login', array( $this, 'record_last_login' ), 10, 2 );
		add_action( 'wp_set_password', array( $this, 'revoke_devices_on_password_set' ), 10, 3 );
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

		$should_record = false;

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
			$should_record = (bool) filter_var( apply_filters( 'quick2fa_record_last_login', true, $user ), FILTER_VALIDATE_BOOLEAN );
		}

		if ( $should_record ) {
			$now = time();

			// The first recorded login stamps the site's recording epoch; add_option()
			// is a no-op once it exists. See docs/developers/extending.md.
			add_option( OPTION_LAST_LOGIN_SINCE, $now, '', 'yes' );

			update_user_meta( $user->ID, META_LAST_LOGIN, $now );
		}
	}

	/**
	 * Record a password change made through wp_set_password(), unless the password is unchanged.
	 *
	 * Covers password resets, the reminder page and, on newer WordPress, wp_update_user().
	 * Core also fires the action on user creation and when it rehashes a password at login;
	 * the new password then still matches the old hash, so nothing is recorded.
	 *
	 * @since 1.6.0
	 * @since 1.8.0 Ignores an unchanged password, using $old_user_data (WordPress 6.7+).
	 *
	 * @param mixed $password      The plaintext password just set.
	 * @param mixed $user_id       The user whose password was set.
	 * @param mixed $old_user_data The user before the update; absent before WordPress 6.7.
	 */
	public function revoke_devices_on_password_set( #[\SensitiveParameter] mixed $password, mixed $user_id, mixed $old_user_data = null ): void {
		if ( is_numeric( $user_id ) && (int) $user_id > 0 ) {
			$user_id = (int) $user_id;

			$this->password_set_user_ids[ $user_id ] = true;

			// Called without a user ID: given one, older wp_check_password() rehashes, firing this action again.
			$is_password_unchanged = is_string( $password ) && $old_user_data instanceof \WP_User && wp_check_password( $password, $old_user_data->user_pass );

			if ( ! $is_password_unchanged ) {
				$this->record_password_change( $user_id );
			}
		}
	}

	/**
	 * Record a password change made through a profile update, unless wp_set_password already has.
	 *
	 * Older WordPress has wp_update_user() store the new hash without firing wp_set_password, so the
	 * profile page and `wp user update --user_pass` reach only this hook. Newer WordPress fires both.
	 *
	 * @since 1.6.0
	 * @since 1.8.0 Skips a change the wp_set_password action has already handled.
	 *
	 * @param mixed $user_id       The updated user.
	 * @param mixed $old_user_data The user as it was before the update.
	 */
	public function revoke_devices_on_profile_update( mixed $user_id, mixed $old_user_data ): void {
		$user = is_numeric( $user_id ) ? get_userdata( (int) $user_id ) : false;

		if ( $user instanceof \WP_User && $old_user_data instanceof \WP_User && $user->user_pass !== $old_user_data->user_pass ) {
			if ( isset( $this->password_set_user_ids[ $user->ID ] ) ) {
				unset( $this->password_set_user_ids[ $user->ID ] );
			} else {
				$this->record_password_change( $user->ID );
			}
		}
	}

	/**
	 * Stamp the password-change time, log the change and revoke every trusted device.
	 *
	 * @since 1.6.0
	 * @since 1.8.0 Stamps and logs the change as well, for every route.
	 *
	 * @param int $user_id The user whose password changed.
	 */
	private function record_password_change( int $user_id ): void {
		update_user_meta( $user_id, META_PASSWORD_LAST_CHANGED, time() );

		$security = new Account_Security_Handler( $user_id );
		$security->log_event( LOG_PASSWORD_CHANGED );

		// A revocation that fails is logged as an error by clear_trusted_devices().
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
	 * @since 1.8.0 Public, for Abilities::check_permission().
	 * @return bool True if verification is needed.
	 */
	public function user_needs_verification(): bool {
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
				$verification_period = $this->get_settings()->get_int( OPTION_VERIFICATION_PERIOD ) * DAY_IN_SECONDS;
				$is_session_current  = $session_verified_time > 0 && ( time() - $session_verified_time ) < $verification_period;
				$needs_verification  = ! $is_session_current && ! $security_handler->is_device_trusted();
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
	 * @since 1.8.0 Public, for the Abilities API.
	 * @param int $user_id User ID.
	 * @return bool True if user's role requires 2FA.
	 */
	public function user_role_requires_2fa( int $user_id ): bool {
		$mode        = $this->get_settings()->get_string( OPTION_MODE );
		$is_required = false;

		if ( MODE_ALL === $mode ) {
			$is_required = true;
		} elseif ( MODE_ROLES === $mode ) {
			$protected_roles = $this->get_settings()->get_array( OPTION_PROTECTED_ROLES );
			$user            = get_userdata( $user_id );

			if ( ! empty( $protected_roles ) && $user instanceof \WP_User ) {
				$is_required = ! empty( array_intersect( (array) $user->roles, $protected_roles ) );
			}
		} else {
			// MODE_DISABLED, or an unrecognised mode; $is_required stays false.
		}

		return $is_required;
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
	 * @param bool $should_trust_device Whether the user ticked "trust this device".
	 */
	private function apply_device_trust( int $user_id, bool $should_trust_device ): void {
		if ( ! are_trusted_devices_enabled() ) {
			return;
		}

		if ( $should_trust_device ) {
			$expiry_days = $this->get_settings()->get_int( OPTION_TRUSTED_DEVICE_EXPIRY );
		} else {
			$expiry_days = $this->get_settings()->get_int( OPTION_VERIFICATION_PERIOD );
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
					$should_trust_device = isset( $_POST['q2fa_trust_device'] ) && '1' === $_POST['q2fa_trust_device'];

					$this->apply_device_trust( $user_id, $should_trust_device );

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
		$trusted_device_expiry   = $this->get_settings()->get_int( OPTION_TRUSTED_DEVICE_EXPIRY );
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

		$security = new Account_Security_Handler( $user_id );
		$handler  = new Password_Reminder_Handler( $user_id );

		// This page is a step after verification: it applies the admin gate itself,
		// since admin_init doesn't fire on wp-login.php.
		if ( $security->is_locked() ) {
			wp_logout();
			wp_die(
				esc_html( $this->get_lockout_message( $user_id, $security ) ),
				esc_html__( 'Account Locked', 'quick-2fa' ),
				array( 'response' => 403 )
			);
		} elseif ( $this->user_needs_verification() ) {
			wp_safe_redirect( get_verify_url() );
			exit();
		} elseif ( ! $handler->needs_reminder() ) {
			wp_safe_redirect( get_return_url( $user_id ) );
			exit();
		} else {
			// Verified, unlocked and due a reminder; render the page.
		}

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
			$password = isset( $_POST['password'] ) && is_string( $_POST['password'] ) ? wp_unslash( $_POST['password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Kept as typed; update_password() trims it, as WordPress does.
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

		$mode = $this->get_settings()->get_string( OPTION_MODE );

		if ( MODE_DISABLED === $mode ) {
			$settings_url = admin_url( 'options-general.php?page=' . SETTINGS_PAGE_SLUG );

			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
				/* translators: %s is the plugin name, Quick 2FA */
				esc_html( sprintf( __( '%s is currently disabled.', 'quick-2fa' ), PLUGIN_NAME ) ),
				esc_html__( 'Your admin area is not protected by two-factor authentication.', 'quick-2fa' ),
				esc_url( $settings_url ),
				esc_html__( 'Enable 2FA', 'quick-2fa' )
			);
		}
	}
}
