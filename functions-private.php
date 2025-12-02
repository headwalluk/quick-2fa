<?php
/**
 * Private Functions
 *
 * Internal helper functions (plugin-scoped via namespace).
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit();
}

/**
 * Get client IP address.
 *
 * @since 1.0.0
 * @return string IP address.
 */
function get_ip_address()
{
    // Use WordPress function if available (WP 5.9+).
    if (function_exists('wp_get_user_ip')) {
        return wp_get_user_ip();
    }

    // Fallback.
    $ip = '';
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }

    return sanitize_text_field($ip);
}

/**
 * Get client user agent.
 *
 * @since 1.0.0
 * @return string User agent.
 */
function get_user_agent()
{
    return !empty($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
}

/**
 * Get current admin URL.
 *
 * @since 1.0.0
 * @return string Current admin URL.
 */
function get_current_admin_url()
{
    $url = admin_url();

    if (!empty($_SERVER['REQUEST_URI'])) {
        $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

        // Parse the request URI to get just the path and query.
        $parsed = wp_parse_url($request_uri);
        $path = isset($parsed['path']) ? $parsed['path'] : '';
        $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';

        // Build full URL.
        $url = home_url($path . $query);
    }

    return $url;
}

/**
 * Store return URL for redirect after verification.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 */
function store_return_url($user_id)
{
    $return_url = get_current_admin_url();
    set_transient(TRANSIENT_RETURN_URL . $user_id, $return_url, 5 * MINUTE_IN_SECONDS);
}

/**
 * Get and delete return URL.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 * @return string Return URL (defaults to admin_url if not found).
 */
function get_return_url($user_id)
{
    $return_url = get_transient(TRANSIENT_RETURN_URL . $user_id);

    // Clean up transient.
    delete_transient(TRANSIENT_RETURN_URL . $user_id);

    // Default to main admin page if no return URL.
    if (empty($return_url)) {
        $return_url = admin_url();
    }

    // Validate return URL is safe.
    $return_url = wp_validate_redirect($return_url, admin_url());

    return $return_url;
}

/**
 * Check if 2FA should be skipped for current request.
 *
 * @since 1.0.0
 * @return bool True if 2FA should be skipped.
 */
function should_skip_check()
{
    // WP-CLI.
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }

    // AJAX requests.
    if (wp_doing_ajax()) {
        return true;
    }

    // REST API requests.
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }

    // Cron jobs.
    if (wp_doing_cron()) {
        return true;
    }

    // XML-RPC.
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        return true;
    }

    // Application Password authentication.
    if (did_action('application_password_did_authenticate')) {
        return true;
    }

    // Already on a 2FA page (prevents redirect loops).
    if (\quick_2fa_is_2fa_page()) {
        return true;
    }

    // Plugin is disabled.
    $mode = get_option(OPTION_MODE, DEFAULT_MODE);
    if (MODE_DISABLED === $mode) {
        return true;
    }

    return false;
}

/**
 * Log an event.
 *
 * @since 1.0.0
 * @param int    $user_id    User ID.
 * @param string $event_type Event type constant.
 * @param array  $data       Additional event data.
 */
function log_event($user_id, $event_type, $data = [])
{
    $log_entry = [
        'event_type' => $event_type,
        'timestamp' => time(),
        'ip' => get_ip_address(),
        'user_agent' => get_user_agent(),
        'data' => $data
    ];

    // Get existing logs.
    $logs = get_user_meta($user_id, META_LOGS, true);
    if (!is_array($logs)) {
        $logs = [];
    }

    // Add new log.
    array_unshift($logs, $log_entry);

    // Keep only last 50 entries per user.
    $logs = array_slice($logs, 0, 50);

    // Store updated logs.
    update_user_meta($user_id, META_LOGS, $logs);
}

/**
 * Generate a cryptographically secure verification code.
 *
 * @since 1.0.0
 * @return string Verification code.
 */
function generate_code()
{
    $length = get_option(OPTION_CODE_LENGTH, DEFAULT_CODE_LENGTH);

    // Generate cryptographically secure random code.
    $max = (int) str_repeat('9', $length);
    $code = random_int(0, $max);

    // Pad with leading zeros.
    return str_pad($code, $length, '0', STR_PAD_LEFT);
}

/**
 * Store hashed verification code.
 *
 * @since 1.0.0
 * @param int    $user_id User ID.
 * @param string $code    Plain text code.
 */
function store_code($user_id, $code)
{
    // Hash the code.
    $hash = wp_hash_password($code);

    // Store hash and timestamp.
    update_user_meta($user_id, META_CODE_HASH, $hash);
    update_user_meta($user_id, META_CODE_TIMESTAMP, time());

    // Reset attempt counter.
    update_user_meta($user_id, META_CODE_ATTEMPTS, 0);

    // Log code generation.
    log_event($user_id, LOG_CODE_GENERATED, [
        'timestamp' => time(),
        'ip' => get_ip_address(),
    ]);
}

/**
 * Check rate limiting for code generation.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 * @return true|\WP_Error True if allowed, WP_Error if rate limited.
 */
function check_code_generation_rate_limit($user_id)
{
    $key = TRANSIENT_RATE_LIMIT . 'code_gen_' . $user_id;
    $limit_data = get_transient($key);

    if (false === $limit_data) {
        // No existing limit, start new window.
        $limit_data = [
            'count' => 1,
            'window_start' => time(),
        ];
        set_transient($key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW);
        return true;
    }

    // Check if we're still in the same window.
    $elapsed = time() - $limit_data['window_start'];

    if ($elapsed > RATE_LIMIT_CODE_GENERATION_WINDOW) {
        // New window.
        $limit_data = [
            'count' => 1,
            'window_start' => time(),
        ];
        set_transient($key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW);
        return true;
    }

    // Check if limit exceeded.
    if ($limit_data['count'] >= RATE_LIMIT_CODE_GENERATION_MAX) {
        $wait_time = ceil((RATE_LIMIT_CODE_GENERATION_WINDOW - $elapsed) / 60);
        return new \WP_Error(
            'rate_limited',
            sprintf(
                /* translators: %d: number of minutes to wait */
                __('Too many verification codes requested. Please wait %d minutes before requesting another code.', 'quick-2fa'),
                $wait_time
            )
        );
    }

    // Increment counter.
    $limit_data['count']++;
    set_transient($key, $limit_data, RATE_LIMIT_CODE_GENERATION_WINDOW);

    return true;
}

/**
 * Send verification code via email.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 * @return true|\WP_Error True on success, WP_Error on failure.
 */
function send_verification_code($user_id)
{
    // Check rate limiting.
    $rate_check = check_code_generation_rate_limit($user_id);
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }

    // Generate code.
    $code = generate_code();

    // Store hashed code.
    store_code($user_id, $code);

    // Get user email.
    $user = get_userdata($user_id);
    if (!$user) {
        return new \WP_Error('user_not_found', __('User not found.', 'quick-2fa'));
    }

    $to = $user->user_email;

    // Prepare email.
    $subject = get_option(OPTION_EMAIL_SUBJECT, __('Your verification code', 'quick-2fa'));
    $message = get_email_message($code, $user);
    $headers = get_email_headers();

    // Send email.
    $sent = wp_mail($to, $subject, $message, $headers);

    // Log result.
    log_event($user_id, LOG_CODE_SENT, [
        'timestamp' => time(),
        'email' => $to,
        'success' => $sent,
    ]);

    if (!$sent) {
        return new \WP_Error('email_failed', __('Failed to send verification email. Please contact your site administrator.', 'quick-2fa'));
    }

    return true;
}

/**
 * Get formatted email message.
 *
 * @since 1.0.0
 * @param string   $code Verification code.
 * @param \WP_User $user User object.
 * @return string Formatted email message.
 */
function get_email_message($code, $user)
{
    $template = get_option(OPTION_EMAIL_TEMPLATE, \quick_2fa_default_email_template());

    // Replace placeholders.
    $message = str_replace(
        ['{code}', '{name}', '{site_name}', '{site_url}'],
        [
            $code,
            $user->display_name,
            get_bloginfo('name'),
            home_url(),
        ],
        $template
    );

    return $message;
}

/**
 * Get email headers.
 *
 * @since 1.0.0
 * @return array Email headers.
 */
function get_email_headers()
{
    $from_name = get_option(OPTION_EMAIL_FROM_NAME, get_bloginfo('name'));
    $from_email = get_option(OPTION_EMAIL_FROM_EMAIL, get_option('admin_email'));

    $headers = [
        'Content-Type: text/plain; charset=UTF-8',
        sprintf('From: %s <%s>', $from_name, $from_email),
    ];

    return $headers;
}

/**
 * Check if user account is locked.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 * @return bool True if account is locked.
 */
function is_account_locked($user_id)
{
    $locked_until = get_user_meta($user_id, META_LOCKED_UNTIL, true);

    if (empty($locked_until)) {
        return false;
    }

    if (time() >= $locked_until) {
        // Lock expired, clean up.
        delete_user_meta($user_id, META_LOCKED_UNTIL);
        return false;
    }

    return true;
}

/**
 * Lock user account.
 *
 * @since 1.0.0
 * @param int $user_id User ID.
 */
function lock_account($user_id)
{
    $lock_until = time() + RATE_LIMIT_ACCOUNT_LOCK_DURATION;

    update_user_meta($user_id, META_LOCKED_UNTIL, $lock_until);

    log_event($user_id, LOG_ACCOUNT_LOCKED, [
        'timestamp' => time(),
        'locked_until' => $lock_until,
        'ip' => get_ip_address(),
    ]);
}

/**
 * Verify submitted code against stored hash.
 *
 * @since 1.0.0
 * @param int    $user_id User ID.
 * @param string $code    Submitted code.
 * @return true|\WP_Error True on success, WP_Error on failure.
 */
function verify_code($user_id, $code)
{
    // Check if account is locked.
    if (is_account_locked($user_id)) {
        $locked_until = get_user_meta($user_id, META_LOCKED_UNTIL, true);
        $wait_time = ceil(($locked_until - time()) / 60);
        
        return new \WP_Error(
            'account_locked',
            sprintf(
                /* translators: %d: number of minutes until unlock */
                __('Your account has been temporarily locked due to too many failed attempts. Please try again in %d minutes.', 'quick-2fa'),
                $wait_time
            )
        );
    }

    // Get stored hash.
    $hash = get_user_meta($user_id, META_CODE_HASH, true);

    if (empty($hash)) {
        return new \WP_Error('no_code', __('No verification code found. Please request a new code.', 'quick-2fa'));
    }

    // Check if code has expired.
    $code_timestamp = get_user_meta($user_id, META_CODE_TIMESTAMP, true);
    $expiry_minutes = get_option(OPTION_CODE_EXPIRY, DEFAULT_CODE_EXPIRY);
    $expiry_seconds = $expiry_minutes * MINUTE_IN_SECONDS;

    if ((time() - $code_timestamp) > $expiry_seconds) {
        // Clean up expired code.
        delete_user_meta($user_id, META_CODE_HASH);
        delete_user_meta($user_id, META_CODE_TIMESTAMP);
        delete_user_meta($user_id, META_CODE_ATTEMPTS);
        
        return new \WP_Error(
            'expired',
            sprintf(
                /* translators: %d: number of minutes until expiry */
                __('Your verification code has expired. Codes are valid for %d minutes. Please request a new code.', 'quick-2fa'),
                $expiry_minutes
            )
        );
    }

    // Check rate limiting on verification attempts.
    $attempts = (int) get_user_meta($user_id, META_CODE_ATTEMPTS, true);

    if ($attempts >= RATE_LIMIT_VERIFICATION_MAX) {
        lock_account($user_id);
        
        return new \WP_Error(
            'too_many_attempts',
            __('Too many failed verification attempts. Your account has been temporarily locked for security.', 'quick-2fa')
        );
    }

    // Verify code against hash.
    if (!wp_check_password($code, $hash)) {
        // Increment failure counter.
        update_user_meta($user_id, META_CODE_ATTEMPTS, $attempts + 1);

        log_event($user_id, LOG_VERIFICATION_FAILED, [
            'timestamp' => time(),
            'ip' => get_ip_address(),
            'attempts' => $attempts + 1,
        ]);

        $remaining = RATE_LIMIT_VERIFICATION_MAX - ($attempts + 1);
        
        if ($remaining > 0) {
            return new \WP_Error(
                'invalid_code',
                sprintf(
                    /* translators: %d: number of attempts remaining */
                    _n(
                        'Invalid verification code. You have %d attempt remaining.',
                        'Invalid verification code. You have %d attempts remaining.',
                        $remaining,
                        'quick-2fa'
                    ),
                    $remaining
                )
            );
        } else {
            lock_account($user_id);
            return new \WP_Error(
                'too_many_attempts',
                __('Too many failed verification attempts. Your account has been temporarily locked for security.', 'quick-2fa')
            );
        }
    }

    // Success! Update verification timestamp and clean up.
    update_user_meta($user_id, META_LAST_VERIFIED, time());
    delete_user_meta($user_id, META_CODE_HASH);
    delete_user_meta($user_id, META_CODE_TIMESTAMP);
    delete_user_meta($user_id, META_CODE_ATTEMPTS);

    log_event($user_id, LOG_VERIFICATION_SUCCESS, [
        'timestamp' => time(),
        'ip' => get_ip_address(),
    ]);

    return true;
}
