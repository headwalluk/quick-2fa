<?php
/**
 * Main Plugin Class
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined('ABSPATH') || die();

/**
 * Main Plugin Class.
 *
 * @since 1.0.0
 */
class Plugin
{
    /**
     * Single instance of the class.
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Get single instance of the class.
     *
     * @since 1.0.0
     * @return Plugin
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     */
    public function run()
    {
        // Check verification on admin init.
        add_action('admin_init', [$this, 'check_verification'], 1);

        // Handle 2FA pages on login init.
        add_action('login_init', [$this, 'handle_login_actions']);

        // Admin notices.
        add_action('admin_notices', [$this, 'admin_notices']);

        // Load text domain.
        add_action('init', [$this, 'load_textdomain']);
    }

    /**
     * Load plugin text domain for translations.
     *
     * @since 1.0.0
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('quick-2fa', false, dirname(QUICK_2FA_BASENAME) . '/languages');
    }

    /**
     * Check if user needs verification on admin access.
     *
     * @since 1.0.0
     */
    public function check_verification()
    {
        // Bail early if we should skip checks.
        if (should_skip_check()) {
            return;
        }

        // Check if user needs verification.
        if ($this->user_needs_verification()) {
            $this->redirect_to_verification();
        }

        // Check if user needs password reminder.
        if ($this->user_needs_password_reminder()) {
            $this->redirect_to_password_reminder();
        }
    }

    /**
     * Check if current user needs verification.
     *
     * @since 1.0.0
     * @return bool True if verification is needed.
     */
    private function user_needs_verification()
    {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return false;
        }

        // Check if user's role requires 2FA.
        if (!$this->user_role_requires_2fa($user_id)) {
            return false;
        }

        // Get last verification timestamp.
        $last_verified = get_user_meta($user_id, META_LAST_VERIFIED, true);

        // If never verified, needs verification.
        if (empty($last_verified)) {
            return true;
        }

        // Get verification period (in days).
        $period_days = get_option(OPTION_VERIFICATION_PERIOD, DEFAULT_VERIFICATION_PERIOD);
        $period_seconds = $period_days * DAY_IN_SECONDS;

        // Check if verification has expired.
        $time_since_verified = time() - $last_verified;

        return $time_since_verified > $period_seconds;
    }

    /**
     * Check if user's role requires 2FA.
     *
     * @since 1.0.0
     * @param int $user_id User ID.
     * @return bool True if user's role requires 2FA.
     */
    private function user_role_requires_2fa($user_id)
    {
        $mode = get_option(OPTION_MODE, DEFAULT_MODE);

        // If mode is "all", everyone requires 2FA.
        if (MODE_ALL === $mode) {
            return true;
        }

        // If mode is "roles", check if user has a protected role.
        if (MODE_ROLES === $mode) {
            $protected_roles = get_option(OPTION_PROTECTED_ROLES, []);

            if (empty($protected_roles)) {
                return false;
            }

            $user = get_userdata($user_id);

            if (!$user) {
                return false;
            }

            // Check if user has any protected role.
            $user_roles = (array) $user->roles;

            return !empty(array_intersect($user_roles, $protected_roles));
        }

        return false;
    }

    /**
     * Check if current user needs password reminder.
     *
     * @since 1.0.0
     * @return bool True if password reminder is needed.
     */
    private function user_needs_password_reminder()
    {
        // Check if feature is enabled.
        if (!get_option(OPTION_PASSWORD_REMINDERS_ENABLED, DEFAULT_PASSWORD_REMINDERS_ENABLED)) {
            return false;
        }

        $user_id = get_current_user_id();
        $user = get_userdata($user_id);

        if (!$user) {
            return false;
        }

        // Get last password change time.
        // Note: user_pass_modified doesn't exist by default, we'll need to track this ourselves.
        // For now, we'll use a meta key or skip if we can't determine.
        $last_pass_change = get_user_meta($user_id, '_password_last_changed', true);

        if (empty($last_pass_change)) {
            // Set it now for future checks.
            update_user_meta($user_id, '_password_last_changed', time());
            return false;
        }

        // Get reminder period (in days).
        $period_days = get_option(OPTION_PASSWORD_REMINDER_PERIOD, DEFAULT_PASSWORD_REMINDER_PERIOD);
        $period_seconds = $period_days * DAY_IN_SECONDS;

        // Check if password is old enough.
        $time_since_change = time() - $last_pass_change;
        if ($time_since_change <= $period_seconds) {
            return false;
        }

        // Check when reminder was last shown.
        $last_reminder = get_user_meta($user_id, META_LAST_PASSWORD_REMINDER, true);

        if (!empty($last_reminder)) {
            $cooldown_days = get_option(OPTION_PASSWORD_REMINDER_COOLDOWN, DEFAULT_PASSWORD_REMINDER_COOLDOWN);
            $cooldown_seconds = $cooldown_days * DAY_IN_SECONDS;

            $time_since_reminder = time() - $last_reminder;

            if ($time_since_reminder < $cooldown_seconds) {
                return false;
            }
        }

        return true;
    }

    /**
     * Redirect user to verification page.
     *
     * @since 1.0.0
     */
    private function redirect_to_verification()
    {
        $user_id = get_current_user_id();

        // Store where user was trying to go.
        store_return_url($user_id);

        // Redirect to verification page.
        wp_safe_redirect(\quick_2fa_get_verify_url());
        exit();
    }

    /**
     * Redirect user to password reminder page.
     *
     * @since 1.0.0
     */
    private function redirect_to_password_reminder()
    {
        $user_id = get_current_user_id();

        // Store where user was trying to go.
        store_return_url($user_id);

        // Redirect to password reminder page.
        wp_safe_redirect(\quick_2fa_get_password_url());
        exit();
    }

    /**
     * Handle login actions for 2FA pages.
     *
     * @since 1.0.0
     */
    public function handle_login_actions()
    {
        // Check for our query parameter.
        if (!isset($_GET[QUERY_PARAM])) {
            return;
        }

        // Security: Ensure user is logged in.
        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url());
            exit();
        }

        // Route to appropriate handler.
        $action = sanitize_key($_GET[QUERY_PARAM]);

        switch ($action) {
            case ACTION_VERIFY:
                $this->handle_verification_page();
                break;

            case ACTION_PASSWORD:
                $this->handle_password_page();
                break;

            default:
                wp_die(esc_html__('Invalid action', 'quick-2fa'), esc_html__('Error', 'quick-2fa'), ['response' => 400]);
        }

        exit();
    }

    /**
     * Handle verification page.
     *
     * @since 1.0.0
     */
    private function handle_verification_page()
    {
        $user_id = get_current_user_id();
        $user = get_userdata($user_id);
        $error = null;
        $message = null;

        // Handle form submission.
        if ('POST' === $_SERVER['REQUEST_METHOD']) {
            if (isset($_POST['q2fa_verify'])) {
                // Verify nonce.
                if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'quick2fa_verify')) {
                    $error = new \WP_Error('invalid_nonce', __('Security check failed. Please try again.', 'quick-2fa'));
                } else {
                    // Get submitted code.
                    $code = isset($_POST['q2fa_code']) ? sanitize_text_field($_POST['q2fa_code']) : '';

                    if (empty($code)) {
                        $error = new \WP_Error('empty_code', __('Please enter the verification code.', 'quick-2fa'));
                    } else {
                        // Verify code.
                        $result = verify_code($user_id, $code);

                        if (is_wp_error($result)) {
                            $error = $result;
                        } else {
                            // Success! Redirect to return URL.
                            $return_url = get_return_url($user_id);
                            wp_safe_redirect($return_url);
                            exit;
                        }
                    }
                }
            } elseif (isset($_POST['q2fa_resend'])) {
                // Resend code.
                $result = send_verification_code($user_id);
                if (is_wp_error($result)) {
                    $error = $result;
                } else {
                    $message = __('A new verification code has been sent to your email.', 'quick-2fa');
                }
            }
        } else {
            // Initial page load - send code.
            $result = send_verification_code($user_id);
            if (is_wp_error($result)) {
                $error = $result;
            }
        }

        // Load verification page template.
        require QUICK_2FA_PATH . 'views/verification-page.php';
    }

    /**
     * Handle password reminder page.
     *
     * @since 1.0.0
     */
    private function handle_password_page()
    {
        // TODO: Implement password reminder page logic.
        echo '<h1>Password Reminder Page</h1>';
        echo '<p>Coming soon...</p>';
    }

    /**
     * Display admin notices.
     *
     * @since 1.0.0
     */
    public function admin_notices()
    {
        // Only show to users with manage_options capability.
        if (!current_user_can('manage_options')) {
            return;
        }

        $mode = get_option(OPTION_MODE, DEFAULT_MODE);

        // Show warning if 2FA is disabled.
        if (MODE_DISABLED === $mode) {
            $settings_url = admin_url('options-general.php?page=quick-2fa');

            printf(
                '<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
                esc_html__('Quick 2FA is currently disabled.', 'quick-2fa'),
                esc_html__('Your admin area is not protected by two-factor authentication.', 'quick-2fa'),
                esc_url($settings_url),
                esc_html__('Enable 2FA', 'quick-2fa')
            );
        }
    }
}
