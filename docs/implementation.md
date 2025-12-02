# Quick 2FA - Implementation Document

**Version:** 1.0  
**Date:** 2 December 2025  
**Status:** Draft

---

## Implementation Strategy

This document outlines the technical implementation approach for Quick 2FA, with emphasis on minimizing attack surface and maintaining security.

---

## Core Design Principles

1. **Minimal Attack Surface** - Use existing WordPress infrastructure (`wp-login.php`) rather than custom rewrites or query vars
2. **Early Interception** - Hook as early as possible to prevent admin access before verification
3. **Stateless Where Possible** - Minimize session state; rely on user meta for persistence
4. **No Theme/Plugin Interference** - Render pages in login context where themes don't load
5. **Fail Secure** - If anything goes wrong, deny access rather than allow

---

## URL Structure

All 2FA pages will use `wp-login.php` with custom query parameters to avoid creating custom endpoints:

| Page              | URL                          | Purpose                  |
| ----------------- | ---------------------------- | ------------------------ |
| Verification      | `wp-login.php?q2fa=verify`   | Enter 6-digit code       |
| Password Reminder | `wp-login.php?q2fa=password` | Password change reminder |

**Rationale:**

- `wp-login.php` is already hardened and monitored by security plugins
- No theme loading (clean, simple pages)
- No custom rewrite rules (smaller attack surface)
- Familiar URL pattern for WordPress users
- Query parameter `q2fa` is unlikely to conflict with other plugins

---

## Hook Strategy

### Primary Hooks

#### 1. `admin_init` (Priority 1)

**Purpose:** Check if logged-in user needs 2FA verification before accessing admin area

**Why this hook:**

- Fires on every admin page request
- User is already authenticated (has valid session cookie)
- Runs before most plugin code (priority 1)
- Clean interception point before admin assets load

**Implementation:**

```php
add_action('admin_init', 'q2fa_check_verification', 1);

function q2fa_check_verification()
{
  // Bail early for excluded request types
  if (q2fa_should_skip_check()) {
    return;
  }

  // Check if current user needs verification
  if (q2fa_user_needs_verification()) {
    q2fa_redirect_to_verification();
  }

  // Check if current user needs password reminder
  if (q2fa_user_needs_password_reminder()) {
    q2fa_redirect_to_password_reminder();
  }
}
```

#### 2. `login_init`

**Purpose:** Render 2FA pages when `?q2fa=` parameter is present

**Why this hook:**

- Fires during `wp-login.php` execution
- Before any output is sent
- Perfect place to render custom login-style pages
- No theme interference

**Implementation:**

```php
add_action('login_init', 'q2fa_handle_login_actions');

function q2fa_handle_login_actions()
{
  // Check for our query parameter
  if (!isset($_GET['q2fa'])) {
    return;
  }

  // Security: Ensure user is logged in
  if (!is_user_logged_in()) {
    wp_safe_redirect(wp_login_url());
    exit();
  }

  // Route to appropriate handler
  $action = sanitize_key($_GET['q2fa']);

  switch ($action) {
    case 'verify':
      q2fa_handle_verification_page();
      break;

    case 'password':
      q2fa_handle_password_page();
      break;

    default:
      wp_die('Invalid action', 'Error', ['response' => 400]);
  }

  exit(); // Always exit after rendering
}
```

---

## Request Flow

### Verification Flow

```
1. User logs in successfully via wp-login.php
   ↓
2. User has valid session cookie
   ↓
3. User navigates to /wp-admin/index.php (or any admin page)
   ↓
4. admin_init hook fires (priority 1)
   ↓
5. q2fa_check_verification() runs
   ↓
6. Check: Should we skip? (AJAX, REST, WP-CLI, etc.)
   → YES: Return early, allow request
   → NO: Continue
   ↓
7. Check: Does user need verification?
   → NO: Continue to password reminder check
   → YES: Continue to step 8
   ↓
8. Store intended destination URL in transient
   Key: "q2fa_return_{user_id}"
   Value: $_SERVER['REQUEST_URI']
   Expiry: 300 seconds (5 minutes)
   ↓
9. Redirect to wp-login.php?q2fa=verify
   ↓
10. login_init hook fires
    ↓
11. q2fa_handle_verification_page() renders form
    - Check if code needs to be sent
    - If yes: generate, hash, store, email
    - Render HTML form
    ↓
12. User submits form (POST)
    ↓
13. q2fa_handle_verification_page() processes submission
    - Verify nonce
    - Check rate limits
    - Verify code against hash
    - On success: update last_verified timestamp
    - On failure: increment failure counter
    ↓
14. If successful:
    - Retrieve return URL from transient
    - Delete transient
    - Redirect to return URL (or default to /wp-admin/)
    ↓
15. Back to step 3 (admin page load)
    ↓
16. admin_init fires again
    ↓
17. Verification check passes (recently verified)
    ↓
18. Continue to password reminder check
```

### Password Reminder Flow

```
1. User passes 2FA verification
   ↓
2. q2fa_check_verification() completes
   ↓
3. q2fa_user_needs_password_reminder() runs
   ↓
4. Check: Are password reminders enabled?
   → NO: Return, allow access
   → YES: Continue
   ↓
5. Check: When was password last changed?
   → Less than threshold: Return, allow access
   → More than threshold: Continue
   ↓
6. Check: When was reminder last shown/dismissed?
   → Recently: Return, allow access
   → Not recently: Continue
   ↓
7. Store intended destination URL in transient
   Key: "q2fa_return_{user_id}"
   ↓
8. Redirect to wp-login.php?q2fa=password
   ↓
9. login_init hook fires
   ↓
10. q2fa_handle_password_page() renders form
    - Generate strong random password
    - Display form with pre-filled password
    - Include "Update Password" button
    - Include "Remind Me Later" button
    ↓
11. User clicks button (POST)
    ↓
12. q2fa_handle_password_page() processes submission
    - Verify nonce
    - If "Update Password":
      → Update user password
      → Clear other sessions
      → Update last_changed timestamp
    - If "Remind Me Later":
      → Update last_reminder_shown timestamp
    ↓
13. Retrieve return URL from transient
    ↓
14. Redirect to return URL (or /wp-admin/)
```

---

## Detection Logic

### Skip Check Conditions

The 2FA check should be skipped for:

```php
function q2fa_should_skip_check()
{
  // WP-CLI
  if (defined('WP_CLI') && WP_CLI) {
    return true;
  }

  // AJAX requests
  if (wp_doing_ajax()) {
    return true;
  }

  // REST API requests
  if (defined('REST_REQUEST') && REST_REQUEST) {
    return true;
  }

  // Cron jobs
  if (wp_doing_cron()) {
    return true;
  }

  // XML-RPC
  if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
    return true;
  }

  // Application Password authentication
  // WordPress sets this when App Password is used
  if (did_action('application_password_did_authenticate')) {
    return true;
  }

  // User role below threshold
  $min_role = get_option('q2fa_minimum_role', 'editor');
  if (!q2fa_user_has_minimum_role($min_role)) {
    return true;
  }

  // Plugin is disabled
  if (!get_option('q2fa_enabled', true)) {
    return true;
  }

  // User is on our verification/password pages
  // (prevents redirect loops)
  if (isset($_GET['q2fa'])) {
    return true;
  }

  return false;
}
```

### User Needs Verification

```php
function q2fa_user_needs_verification()
{
  $user_id = get_current_user_id();

  // Get last verification timestamp
  $last_verified = get_user_meta($user_id, '_q2fa_last_verified', true);

  // If never verified, needs verification
  if (empty($last_verified)) {
    return true;
  }

  // Get verification period (in days)
  $period_days = get_option('q2fa_verification_period', 3);
  $period_seconds = $period_days * DAY_IN_SECONDS;

  // Check if verification has expired
  $time_since_verified = time() - $last_verified;

  return $time_since_verified > $period_seconds;
}
```

### User Needs Password Reminder

```php
function q2fa_user_needs_password_reminder()
{
  // Check if feature is enabled
  if (!get_option('q2fa_password_reminders_enabled', true)) {
    return false;
  }

  $user_id = get_current_user_id();

  // Get last password change time
  $user = get_userdata($user_id);
  $last_pass_change = strtotime($user->user_pass_modified);

  // If we can't determine, don't nag
  if (empty($last_pass_change)) {
    return false;
  }

  // Get reminder period (in days)
  $period_days = get_option('q2fa_password_reminder_period', 60);
  $period_seconds = $period_days * DAY_IN_SECONDS;

  // Check if password is old enough
  $time_since_change = time() - $last_pass_change;
  if ($time_since_change <= $period_seconds) {
    return false; // Password is recent enough
  }

  // Check when reminder was last shown
  $last_reminder = get_user_meta($user_id, '_q2fa_last_password_reminder', true);

  if (!empty($last_reminder)) {
    $cooldown_days = get_option('q2fa_password_reminder_cooldown', 1);
    $cooldown_seconds = $cooldown_days * DAY_IN_SECONDS;

    $time_since_reminder = time() - $last_reminder;

    if ($time_since_reminder < $cooldown_seconds) {
      return false; // Reminder shown too recently
    }
  }

  return true;
}
```

---

## Security Implementation

### Code Generation

```php
function q2fa_generate_code()
{
  $length = get_option('q2fa_code_length', 6);

  // Generate cryptographically secure random code
  $max = (int) str_repeat('9', $length);
  $code = random_int(0, $max);

  // Pad with leading zeros
  return str_pad($code, $length, '0', STR_PAD_LEFT);
}
```

### Code Storage

```php
function q2fa_store_code($user_id, $code)
{
  // Hash the code
  $hash = wp_hash_password($code);

  // Store hash and timestamp
  update_user_meta($user_id, '_q2fa_code_hash', $hash);
  update_user_meta($user_id, '_q2fa_code_timestamp', time());

  // Reset attempt counter
  update_user_meta($user_id, '_q2fa_code_attempts', 0);

  // Log code generation
  q2fa_log_event($user_id, 'code_generated', [
    'timestamp' => time(),
    'ip' => q2fa_get_ip_address()
  ]);
}
```

### Code Verification

```php
function q2fa_verify_code($user_id, $submitted_code)
{
  // Get stored hash
  $hash = get_user_meta($user_id, '_q2fa_code_hash', true);

  if (empty($hash)) {
    return new WP_Error('no_code', 'No verification code found');
  }

  // Check if code has expired
  $code_timestamp = get_user_meta($user_id, '_q2fa_code_timestamp', true);
  $expiry_minutes = get_option('q2fa_code_expiry', 15);
  $expiry_seconds = $expiry_minutes * MINUTE_IN_SECONDS;

  if (time() - $code_timestamp > $expiry_seconds) {
    return new WP_Error('expired', 'Verification code has expired');
  }

  // Check rate limiting
  $attempts = (int) get_user_meta($user_id, '_q2fa_code_attempts', true);

  if ($attempts >= 5) {
    q2fa_lock_account($user_id);
    return new WP_Error('too_many_attempts', 'Too many failed attempts');
  }

  // Verify code
  if (wp_check_password($submitted_code, $hash)) {
    // Success!
    update_user_meta($user_id, '_q2fa_last_verified', time());
    delete_user_meta($user_id, '_q2fa_code_hash');
    delete_user_meta($user_id, '_q2fa_code_timestamp');
    delete_user_meta($user_id, '_q2fa_code_attempts');

    q2fa_log_event($user_id, 'verification_success', [
      'timestamp' => time(),
      'ip' => q2fa_get_ip_address()
    ]);

    return true;
  }

  // Failed attempt
  update_user_meta($user_id, '_q2fa_code_attempts', $attempts + 1);

  q2fa_log_event($user_id, 'verification_failed', [
    'timestamp' => time(),
    'ip' => q2fa_get_ip_address(),
    'attempts' => $attempts + 1
  ]);

  return new WP_Error('invalid_code', 'Invalid verification code');
}
```

### Rate Limiting

```php
function q2fa_check_rate_limit($user_id, $action)
{
  $key = "_q2fa_rate_limit_{$action}";
  $limit_data = get_user_meta($user_id, $key, true);

  if (empty($limit_data)) {
    $limit_data = [
      'count' => 0,
      'window_start' => time()
    ];
  }

  // Define rate limits
  $limits = [
    'code_generation' => [
      'max_requests' => 3,
      'window_seconds' => 15 * MINUTE_IN_SECONDS
    ]
  ];

  $limit_config = $limits[$action] ?? null;

  if (!$limit_config) {
    return true; // No limit defined
  }

  // Check if we're in a new window
  $elapsed = time() - $limit_data['window_start'];

  if ($elapsed > $limit_config['window_seconds']) {
    // New window
    $limit_data = [
      'count' => 1,
      'window_start' => time()
    ];
    update_user_meta($user_id, $key, $limit_data);
    return true;
  }

  // Check if limit exceeded
  if ($limit_data['count'] >= $limit_config['max_requests']) {
    return new WP_Error('rate_limited', sprintf('Too many requests. Please wait %d minutes.', ceil(($limit_config['window_seconds'] - $elapsed) / MINUTE_IN_SECONDS)));
  }

  // Increment counter
  $limit_data['count']++;
  update_user_meta($user_id, $key, $limit_data);

  return true;
}
```

### Account Locking

```php
function q2fa_lock_account($user_id)
{
  $lock_duration = 1 * HOUR_IN_SECONDS; // 1 hour
  $lock_until = time() + $lock_duration;

  update_user_meta($user_id, '_q2fa_locked_until', $lock_until);

  q2fa_log_event($user_id, 'account_locked', [
    'timestamp' => time(),
    'locked_until' => $lock_until,
    'ip' => q2fa_get_ip_address()
  ]);
}

function q2fa_is_account_locked($user_id)
{
  $locked_until = get_user_meta($user_id, '_q2fa_locked_until', true);

  if (empty($locked_until)) {
    return false;
  }

  if (time() >= $locked_until) {
    // Lock expired, clean up
    delete_user_meta($user_id, '_q2fa_locked_until');
    return false;
  }

  return true;
}
```

---

## Return URL Management

### Storing Return URL

```php
function q2fa_redirect_to_verification()
{
  $user_id = get_current_user_id();

  // Store where user was trying to go
  $return_url = q2fa_get_current_admin_url();
  set_transient('q2fa_return_' . $user_id, $return_url, 5 * MINUTE_IN_SECONDS);

  // Redirect to verification page
  wp_safe_redirect(q2fa_get_verify_url());
  exit();
}

function q2fa_get_current_admin_url()
{
  $url = admin_url();

  // Get current URI
  if (!empty($_SERVER['REQUEST_URI'])) {
    $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

    // Build full URL
    $url = admin_url() . ltrim($request_uri, '/');
  }

  return $url;
}
```

### Retrieving Return URL

```php
function q2fa_get_return_url()
{
  $user_id = get_current_user_id();
  $return_url = get_transient('q2fa_return_' . $user_id);

  // Clean up transient
  delete_transient('q2fa_return_' . $user_id);

  // Default to main admin page if no return URL
  if (empty($return_url)) {
    $return_url = admin_url();
  }

  // Validate return URL is safe
  $return_url = wp_validate_redirect($return_url, admin_url());

  return $return_url;
}
```

---

## Page Rendering

### Verification Page

```php
function q2fa_handle_verification_page()
{
  $user_id = get_current_user_id();

  // Check if account is locked
  if (q2fa_is_account_locked($user_id)) {
    q2fa_render_locked_page();
    exit();
  }

  $error = null;
  $message = null;

  // Handle form submission
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['q2fa_verify'])) {
      $error = q2fa_process_verification_form();
    } elseif (isset($_POST['q2fa_resend'])) {
      $result = q2fa_resend_code();
      if (is_wp_error($result)) {
        $error = $result;
      } else {
        $message = 'A new verification code has been sent to your email.';
      }
    }
  } else {
    // Initial page load - send code
    $result = q2fa_send_verification_code($user_id);
    if (is_wp_error($result)) {
      $error = $result;
    }
  }

  // Render page
  q2fa_render_verification_form($error, $message);
}

function q2fa_process_verification_form()
{
  // Verify nonce
  if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'q2fa_verify')) {
    return new WP_Error('invalid_nonce', 'Security check failed');
  }

  // Get submitted code
  $code = isset($_POST['q2fa_code']) ? sanitize_text_field($_POST['q2fa_code']) : '';

  if (empty($code)) {
    return new WP_Error('empty_code', 'Please enter the verification code');
  }

  // Verify code
  $user_id = get_current_user_id();
  $result = q2fa_verify_code($user_id, $code);

  if (is_wp_error($result)) {
    return $result;
  }

  // Success! Redirect to return URL
  $return_url = q2fa_get_return_url();
  wp_safe_redirect($return_url);
  exit();
}
```

### Template Structure

Both verification and password pages will use a shared template structure:

```php
function q2fa_render_page($template_file, $data = []) {
    // Extract data for use in template
    extract($data);

    // Get plugin settings
    $logo_url = get_option('q2fa_logo_url', '');
    $site_name = get_bloginfo('name');

    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title><?php echo esc_html($data['page_title'] ?? 'Account Verification'); ?> - <?php bloginfo('name'); ?></title>
        <?php q2fa_output_inline_css(); ?>
    </head>
    <body class="q2fa-page q2fa-<?php echo esc_attr($data['page_type'] ?? 'verify'); ?>">
        <div class="q2fa-container">
            <?php if (!empty($logo_url)) : ?>
                <div class="q2fa-logo">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>">
                </div>
            <?php endif; ?>

            <div class="q2fa-content">
                <?php include $template_file; ?>
            </div>

            <div class="q2fa-footer">
                <p>&larr; <a href="<?php echo esc_url(wp_logout_url()); ?>">Log out</a></p>
            </div>
        </div>
    </body>
    </html>
    <?php
}
```

---

## Email Handling

### Sending Verification Code

```php
function q2fa_send_verification_code($user_id)
{
  // Check rate limiting
  $rate_check = q2fa_check_rate_limit($user_id, 'code_generation');
  if (is_wp_error($rate_check)) {
    return $rate_check;
  }

  // Generate code
  $code = q2fa_generate_code();

  // Store hashed code
  q2fa_store_code($user_id, $code);

  // Get user email
  $user = get_userdata($user_id);
  $to = $user->user_email;

  // Prepare email
  $subject = q2fa_get_email_subject();
  $message = q2fa_get_email_message($code, $user);
  $headers = q2fa_get_email_headers();

  // Send email
  $sent = wp_mail($to, $subject, $message, $headers);

  // Log result
  q2fa_log_event($user_id, 'code_sent', [
    'timestamp' => time(),
    'email' => $to,
    'success' => $sent
  ]);

  if (!$sent) {
    return new WP_Error('email_failed', 'Failed to send verification email');
  }

  return true;
}

function q2fa_get_email_message($code, $user)
{
  $template = get_option('q2fa_email_template', q2fa_default_email_template());

  // Replace placeholders
  $message = str_replace(['{code}', '{name}', '{site_name}', '{site_url}'], [$code, $user->display_name, get_bloginfo('name'), home_url()], $template);

  return $message;
}
```

---

## Password Management

### Generating Strong Password

```php
function q2fa_generate_strong_password()
{
  // Use WordPress built-in function
  return wp_generate_password(24, true, true);
}
```

### Updating Password

```php
function q2fa_update_user_password($user_id, $new_password)
{
  // Validate password strength
  if (strlen($new_password) < 12) {
    return new WP_Error('weak_password', 'Password must be at least 12 characters');
  }

  // Update password
  wp_set_password($new_password, $user_id);

  // Update metadata
  update_user_meta($user_id, '_q2fa_last_password_change', time());

  // Clear password reminder
  delete_user_meta($user_id, '_q2fa_last_password_reminder');

  // Destroy other sessions (keep current one)
  $session_tokens = WP_Session_Tokens::get_instance($user_id);
  $current_token = wp_get_session_token();
  $session_tokens->destroy_others($current_token);

  // Log event
  q2fa_log_event($user_id, 'password_changed', [
    'timestamp' => time(),
    'ip' => q2fa_get_ip_address()
  ]);

  return true;
}
```

---

## Settings Management

### Default Settings

```php
function q2fa_get_default_settings()
{
  return [
    // General
    'q2fa_enabled' => true,
    'q2fa_verification_period' => 3, // days
    'q2fa_code_length' => 6,
    'q2fa_code_expiry' => 15, // minutes
    'q2fa_minimum_role' => 'editor',

    // Email
    'q2fa_email_from_name' => get_bloginfo('name'),
    'q2fa_email_from_email' => get_option('admin_email'),
    'q2fa_email_subject' => 'Your verification code',
    'q2fa_email_template' => q2fa_default_email_template(),

    // Password Reminders
    'q2fa_password_reminders_enabled' => true,
    'q2fa_password_reminder_period' => 60, // days
    'q2fa_password_reminder_cooldown' => 1, // days

    // Customization
    'q2fa_logo_url' => '',
    'q2fa_verify_intro' => q2fa_default_verify_intro(),
    'q2fa_password_intro' => q2fa_default_password_intro()
  ];
}
```

### Settings API Implementation

Use WordPress Settings API for admin settings page:

```php
function q2fa_register_settings()
{
  register_setting('q2fa_settings', 'q2fa_enabled');
  register_setting('q2fa_settings', 'q2fa_verification_period');
  // ... register all settings

  add_settings_section('q2fa_general', 'General Settings', 'q2fa_general_section_callback', 'q2fa_settings');

  add_settings_field('q2fa_enabled', 'Enable 2FA', 'q2fa_enabled_field_callback', 'q2fa_settings', 'q2fa_general');

  // ... add all fields
}
add_action('admin_init', 'q2fa_register_settings');
```

---

## Logging System

### Simple Logging (User Meta)

For v1.0, store recent logs in user meta:

```php
function q2fa_log_event($user_id, $event_type, $data = [])
{
  $log_entry = [
    'event_type' => $event_type,
    'timestamp' => time(),
    'ip' => q2fa_get_ip_address(),
    'user_agent' => q2fa_get_user_agent(),
    'data' => $data
  ];

  // Get existing logs
  $logs = get_user_meta($user_id, '_q2fa_logs', true);
  if (!is_array($logs)) {
    $logs = [];
  }

  // Add new log
  array_unshift($logs, $log_entry);

  // Keep only last 50 entries per user
  $logs = array_slice($logs, 0, 50);

  // Store updated logs
  update_user_meta($user_id, '_q2fa_logs', $logs);
}

function q2fa_get_ip_address()
{
  // Use WordPress function if available (WP 5.9+)
  if (function_exists('wp_get_user_ip')) {
    return wp_get_user_ip();
  }

  // Fallback
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
```

---

## Plugin Activation/Deactivation

### Activation Hook

```php
function q2fa_activate()
{
  // Set default options (if not already set)
  $defaults = q2fa_get_default_settings();

  foreach ($defaults as $key => $value) {
    if (get_option($key) === false) {
      add_option($key, $value);
    }
  }

  // Store plugin version
  update_option('q2fa_version', Q2FA_VERSION);

  // No need to flush rewrite rules (we're not adding any)
}
register_activation_hook(__FILE__, 'q2fa_activate');
```

### Deactivation Hook

```php
function q2fa_deactivate()
{
  // Don't delete settings or user meta
  // Admin may reactivate and expect settings to persist

  // Clear any temporary transients
  global $wpdb;
  $wpdb->query(
    "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_q2fa_%' 
         OR option_name LIKE '_transient_timeout_q2fa_%'"
  );
}
register_deactivation_hook(__FILE__, 'q2fa_deactivate');
```

### Uninstall Hook

```php
// In uninstall.php (separate file)
if (!defined('WP_UNINSTALL_PLUGIN')) {
  exit();
}

// Delete all plugin options
$options = [
  'q2fa_enabled',
  'q2fa_verification_period'
  // ... list all options
];

foreach ($options as $option) {
  delete_option($option);
}

// Delete all user meta
global $wpdb;
$wpdb->query(
  "DELETE FROM {$wpdb->usermeta} 
     WHERE meta_key LIKE '_q2fa_%'"
);
```

---

## File Structure

```
quick-2fa/
├── quick-2fa.php                      # Main plugin file
│   - Plugin header
│   - Constants (Q2FA_VERSION, Q2FA_PATH, Q2FA_URL)
│   - Include core files
│   - Register activation/deactivation hooks
│
├── uninstall.php                      # Cleanup on uninstall
│
├── includes/
│   ├── core.php                       # Core hooks (admin_init, login_init)
│   ├── verification.php               # Verification logic and page handling
│   ├── password.php                   # Password reminder logic and handling
│   ├── security.php                   # Code generation, hashing, rate limiting
│   ├── email.php                      # Email sending functions
│   ├── helpers.php                    # Utility functions (IP address, user agent, etc.)
│   └── logging.php                    # Logging functions
│
├── admin/
│   ├── settings.php                   # Settings page registration and rendering
│   ├── logs.php                       # Logs viewer page
│   └── user-management.php            # User 2FA status management
│
├── templates/
│   ├── verify-form.php                # Verification form HTML
│   ├── password-form.php              # Password reminder form HTML
│   ├── locked-account.php             # Account locked message
│   └── css-inline.php                 # Inline CSS output
│
├── languages/
│   └── quick-2fa.pot                  # Translation template
│
├── docs/
│   ├── requirements.md                # Requirements document
│   └── implementation.md              # This document
│
├── readme.txt                         # WordPress.org readme
└── LICENSE                            # GPL v2 or later
```

---

## Key Functions Reference

### Core Detection Functions

- `q2fa_should_skip_check()` - Determine if 2FA check should be skipped
- `q2fa_user_needs_verification()` - Check if user needs to verify
- `q2fa_user_needs_password_reminder()` - Check if user needs password reminder
- `q2fa_user_has_minimum_role()` - Check if user meets role requirement

### Security Functions

- `q2fa_generate_code()` - Generate random code
- `q2fa_store_code()` - Hash and store code
- `q2fa_verify_code()` - Verify submitted code
- `q2fa_check_rate_limit()` - Check rate limiting
- `q2fa_lock_account()` - Lock user account
- `q2fa_is_account_locked()` - Check if account is locked

### Page Handling Functions

- `q2fa_handle_verification_page()` - Main verification page handler
- `q2fa_handle_password_page()` - Main password page handler
- `q2fa_render_verification_form()` - Render verification HTML
- `q2fa_render_password_form()` - Render password HTML
- `q2fa_process_verification_form()` - Process form submission
- `q2fa_process_password_form()` - Process password form submission

### Redirect Functions

- `q2fa_redirect_to_verification()` - Redirect to verification page
- `q2fa_redirect_to_password_reminder()` - Redirect to password page
- `q2fa_get_return_url()` - Retrieve stored return URL
- `q2fa_get_verify_url()` - Get verification page URL
- `q2fa_get_password_url()` - Get password page URL

### Email Functions

- `q2fa_send_verification_code()` - Send code via email
- `q2fa_get_email_subject()` - Get email subject line
- `q2fa_get_email_message()` - Build email message
- `q2fa_get_email_headers()` - Build email headers

### Password Functions

- `q2fa_generate_strong_password()` - Generate strong password
- `q2fa_update_user_password()` - Update user password
- `q2fa_get_password_age()` - Get days since last password change

### Logging Functions

- `q2fa_log_event()` - Log an event
- `q2fa_get_user_logs()` - Retrieve user logs
- `q2fa_get_ip_address()` - Get user IP address
- `q2fa_get_user_agent()` - Get user agent string

### Helper Functions

- `q2fa_output_inline_css()` - Output inline CSS
- `q2fa_get_default_settings()` - Get default settings array
- `q2fa_default_email_template()` - Get default email template
- `q2fa_default_verify_intro()` - Get default verification intro text
- `q2fa_default_password_intro()` - Get default password intro text

---

## Security Considerations

### Attack Vectors & Mitigations

1. **Bypass via Direct File Access**

   - _Attack:_ Access admin files directly, bypassing hooks
   - _Mitigation:_ `admin_init` fires on ALL admin page loads before content
   - _Additional:_ Use high priority (1) to run before other plugins

2. **Bypass via Query String Manipulation**

   - _Attack:_ Add parameters to confuse detection logic
   - _Mitigation:_ Simple check: does URL start with `/wp-admin/`? Ignore query strings
   - _Additional:_ Whitelist specific parameters that allow bypass (like `?q2fa=verify`)

3. **Timing Attacks on Code Verification**

   - _Attack:_ Measure response time to determine correct digits
   - _Mitigation:_ Use `wp_check_password()` which includes timing-safe comparison
   - _Additional:_ Use constant-time operations where possible

4. **Rate Limit Bypass via Multiple Accounts**

   - _Attack:_ Create multiple accounts to bypass per-user rate limits
   - _Mitigation:_ Out of scope for v1.0; consider IP-based limits in future
   - _Note:_ WordPress registration is typically restricted on production sites

5. **Session Fixation**

   - _Attack:_ Steal session after 2FA verification
   - _Mitigation:_ 2FA doesn't prevent this; WordPress handles session security
   - _Note:_ Recommend HTTPS and complementary security plugins

6. **Email Interception**

   - _Attack:_ Intercept email in transit
   - _Mitigation:_ Short code lifetime (15 minutes), one-time use
   - _Additional:_ Recommend SMTP over TLS for email sending

7. **Redirect URL Manipulation**

   - _Attack:_ Modify return URL to redirect to malicious site
   - _Mitigation:_ Use `wp_validate_redirect()` with safe default
   - _Additional:_ Store URL server-side in transient, not in query string

8. **Plugin Deactivation**
   - _Attack:_ Admin deactivates plugin to bypass 2FA
   - _Mitigation:_ If admin can deactivate plugins, they already have full access
   - _Note:_ MU plugins cannot be deactivated (ideal for hosting providers)

---

## Testing Strategy

### Manual Testing Checklist

**Basic Flow:**

- [ ] User logs in, redirects to verification page
- [ ] Code sent via email
- [ ] Valid code allows admin access
- [ ] Invalid code shows error
- [ ] Expired code (>15 min) shows error
- [ ] After 5 failed attempts, account locks

**Skip Conditions:**

- [ ] REST API requests work without 2FA
- [ ] Application Passwords work without 2FA
- [ ] AJAX requests work without 2FA
- [ ] WP-CLI commands work without 2FA
- [ ] Users below role threshold can access admin

**Password Reminder:**

- [ ] After 60 days, password reminder appears
- [ ] "Update Password" updates password successfully
- [ ] "Remind Me Later" delays reminder by 1 day
- [ ] New password works for login
- [ ] Password manager detects and offers to save

**Edge Cases:**

- [ ] User logs out during verification - clean redirect
- [ ] User navigates back during verification - page still works
- [ ] User refreshes verification page - doesn't send duplicate emails
- [ ] Multiple tabs - verification in one tab works for all tabs
- [ ] Email fails to send - user sees error message

**Settings:**

- [ ] All settings save correctly
- [ ] Changing verification period takes effect
- [ ] Changing minimum role takes effect
- [ ] Disabling plugin allows admin access
- [ ] Custom logo displays correctly
- [ ] Custom intro text displays correctly

**Multisite:**

- [ ] Plugin activates on network
- [ ] Each site has independent settings
- [ ] 2FA works on network admin
- [ ] 2FA works on site admin

**MU Plugin:**

- [ ] Works when placed in mu-plugins/
- [ ] Cannot be deactivated
- [ ] Settings page accessible

### Automated Testing (Future)

Consider adding PHPUnit tests for:

- Code generation and verification
- Rate limiting logic
- User role checking
- Email formatting
- Settings management

---

## Performance Considerations

### Database Queries

**Per Request (when 2FA needed):**

- 1-2 user meta queries (verification status, password age)
- 1 options query (settings - cached by WordPress)
- 1 user query (get user data - cached by WordPress)

**During Verification:**

- 3-4 user meta updates (code, timestamp, attempts)
- 1 transient set (return URL)
- 1 transient get + delete (return URL)

**Optimization:**

- Use `update_user_meta()` instead of `add_user_meta()` to avoid duplicates
- Rely on WordPress object cache
- Use transients for temporary data (auto-cleanup)
- Keep user meta log limited to 50 entries

### Email Sending

- Asynchronous sending not required (immediate feedback is better for security)
- Consider action scheduler for retry logic in future versions
- Log email failures for debugging

### Large Sites

For sites with 1000+ users:

- User meta approach scales well (indexed by user ID)
- Consider custom table in future for advanced logging/analytics
- No global queries (all lookups are per-user)

---

## Backward Compatibility

### Version Migrations

```php
function q2fa_maybe_migrate()
{
  $current_version = get_option('q2fa_version', '0.0.0');

  if (version_compare($current_version, Q2FA_VERSION, '<')) {
    // Run migrations
    q2fa_migrate_to_version(Q2FA_VERSION);

    // Update version
    update_option('q2fa_version', Q2FA_VERSION);
  }
}

function q2fa_migrate_to_version($version)
{
  // Example: v1.0 to v1.1 migration
  // if (version_compare($version, '1.1.0', '>=')) {
  //     q2fa_migrate_1_1_0();
  // }
}
```

---

## WordPress.org Submission Checklist

- [ ] Plugin follows WordPress Coding Standards
- [ ] All strings are internationalized
- [ ] All output is escaped
- [ ] All input is validated and sanitized
- [ ] Nonces used for all form submissions
- [ ] No PHP errors/warnings/notices
- [ ] Plugin header complete with all fields
- [ ] readme.txt follows WordPress.org format
- [ ] Screenshots prepared
- [ ] No external dependencies
- [ ] No obfuscated code
- [ ] GPL-compatible license
- [ ] Tested on WordPress 5.8+
- [ ] Tested on PHP 7.4+
- [ ] No direct database queries (use WP functions)
- [ ] Emergency access instructions in description

---

## Deployment Guide for Hosting Providers

### As MU Plugin

1. Upload `quick-2fa/` folder to `wp-content/mu-plugins/`
2. Plugin auto-activates (cannot be deactivated)
3. Settings available at: `/wp-admin/options-general.php?page=quick-2fa`
4. Default settings work out-of-box (no configuration required)

### Recommended Defaults for Mass Deployment

```php
// In wp-config.php or custom configuration
define('Q2FA_VERIFICATION_PERIOD', 3); // days
define('Q2FA_PASSWORD_REMINDER_PERIOD', 60); // days
define('Q2FA_MINIMUM_ROLE', 'editor'); // editor and above
```

### Client Communication Template

```
Subject: Enhanced Security: Two-Factor Authentication Now Active

Dear [Client Name],

We've activated two-factor authentication (2FA) on your WordPress site
to enhance your account security.

What this means:
- When you log in and access the admin area, you'll receive a 6-digit
  code via email
- Enter this code to verify your identity
- You'll stay verified for 3 days before needing to verify again

Emergency Access:
If you lose access to your email, contact us immediately. We can restore
access through your hosting control panel.

Questions? We're here to help!

[Your Hosting Company]
```

---

## User Lock-out Management Implementation

### Overview

Administrators need visibility and control over user lock-out status directly from the WordPress Users table. This feature enhances the plugin's usability for hosting providers and site administrators by providing quick access to lock-out management without requiring database access.

### Implementation Checklist

**Phase 1: Custom Column Display** ✅

- [x] Hook into `manage_users_columns` to add "Lock Status" column header
- [x] Hook into `manage_users_custom_column` to render column content for each user
- [x] Create helper function `get_user_lockout_status()` to check lock-out state
  - Returns: `'locked'`, `'unlocked'`, or `'never_verified'`
  - Checks if `META_LOCKED_UNTIL` exists and is greater than current time
- [x] Display appropriate icon based on status:
  - Locked: Red dashicon `dashicons-lock` with tooltip showing expiry time
  - Unlocked: Green dashicon `dashicons-yes-alt` 
  - Never verified: Gray dash
- [x] Format tooltip text: "Locked out until [formatted_date]" or "Locked out (manual)"
- [x] Add inline CSS for icon colors (red for locked, green for unlocked)
- [x] Position column after "Email" column (use array manipulation in column filter)

**Phase 2: Column Sorting** ✅

- [x] Hook into `manage_users_sortable_columns` to make column sortable
- [x] Hook into `pre_get_users` to handle orderby parameter
- [x] Add meta_query ordering by `META_LOCKED_UNTIL` value
- [x] Handle NULL values (users without lock-out meta)
- [ ] Test sorting: locked users first vs last

**Phase 3: Users Table Filters** ✅

- [x] Hook into `views_users` to add filter links
- [x] Count locked users: query users where `META_LOCKED_UNTIL` > current timestamp
- [x] Count not-locked users: total users - locked users
- [x] Add filter links with counts: "Locked Out (5)" and "Not Locked Out (142)"
- [x] Hook into `pre_get_users` to filter user list based on selected view
- [x] Add meta_query for "Locked Out" filter
- [x] Add inverse logic for "Not Locked Out" filter
- [x] Preserve current filter when performing row actions
- [ ] Test filter counts update after lock/unlock actions

**Phase 4: Row Actions** ✅

- [x] Hook into `user_row_actions` to add lock/unlock actions
- [x] Check current user's `edit_users` capability
- [x] Prevent adding action for current user (can't lock yourself)
- [x] Generate dynamic action label based on lock-out status:
  - If locked: "Unlock"
  - If not locked: "Lock Out"
- [x] Build action URL with nonce
- [x] Add row action to actions array with appropriate priority
- [x] Style action link (use default WordPress styling)

**Phase 5: Lock Action Handler** ✅

- [x] Hook into `admin_action_quick2fa_lock` (fires on `users.php?action=quick2fa_lock`)
- [x] Verify nonce: `wp_verify_nonce($_GET['_wpnonce'], 'quick2fa_lock_' . $user_id)`
- [x] Check capability: `current_user_can('edit_users')`
- [x] Prevent self-lock: check if target user ID == current user ID
- [x] Get target user ID from `$_GET['user']`
- [x] Set permanent lock: `update_user_meta($user_id, META_LOCKED_UNTIL, PHP_INT_MAX)`
- [x] Log event with admin_id
- [x] Add admin notice: "User [username] has been locked out."
- [x] Redirect back to users.php with filters preserved
- [x] Add error handling for invalid user IDs

**Phase 6: Unlock Action Handler** ✅

- [x] Hook into `admin_action_quick2fa_unlock` (fires on `users.php?action=quick2fa_unlock`)
- [x] Verify nonce: `wp_verify_nonce($_GET['_wpnonce'], 'quick2fa_unlock_' . $user_id)`
- [x] Check capability: `current_user_can('edit_users')`
- [x] Get target user ID from `$_GET['user']`
- [x] Delete lock meta: `delete_user_meta($user_id, META_LOCKED_UNTIL)`
- [x] Reset attempt counter: `update_user_meta($user_id, META_CODE_ATTEMPTS, 0)`
- [x] Log event with admin_id
- [x] Add admin notice: "User [username] has been unlocked."
- [x] Redirect back to users.php with filters preserved

**Phase 7: Helper Functions** ✅

- [x] Create `get_user_lockout_status($user_id)` function
  - Check if `META_LOCKED_UNTIL` exists
  - Compare value to current timestamp
  - Return status string
- [x] Create `format_lockout_expiry($timestamp)` function
  - Format timestamp using WordPress date format
  - Handle special cases (PHP_INT_MAX = "manual lock")
- [x] Create `count_locked_users()` function
  - Efficient count query using `WP_User_Query`
  - Cache result in transient for 5 minutes
- [x] Add function documentation with @since tags

**Phase 8: Testing & Edge Cases** (Ready for Manual Testing)

- [ ] Test with 0 locked users
- [ ] Test with 100+ locked users (performance)
- [ ] Test locking user with active session (should block on next page load)
- [ ] Test automatic lock expiry (timestamp passes, user shows as unlocked)
- [ ] Test multisite: super admin can manage users across sites
- [x] Test self-lock prevention with error message (implemented)
- [x] Test invalid user IDs (implemented with wp_die)
- [x] Test nonce verification failures (implemented)
- [x] Test capability checks (implemented - requires edit_users)
- [ ] Verify filter counts update immediately after lock/unlock
- [ ] Test sorting performance with large user bases
- [ ] Check for N+1 query issues in column rendering

**Phase 9: UI Polish** (In Progress)

- [x] Choose final wording: "Lock Status" ✅
- [x] Decide: show icon for unlocked users → Green checkmark ✅
- [x] Ensure icon colors match WordPress admin theme (red=#d63638, green=#00a32a)
- [ ] Test responsive design (mobile admin)
- [ ] Add screen reader text for accessibility
- [ ] Ensure tooltips work on touch devices
- [x] Match WordPress admin notice styling (uses add_settings_error)
- [ ] Add subtle hover effects on icons (optional)

**Phase 10: Documentation** (Pending)

- [x] Add inline code comments explaining lock-out logic ✅
- [x] Document user meta keys in constants.php (already documented)
- [ ] Add FAQ entry: "How do I unlock a locked user?"
- [ ] Update readme.txt with user management features
- [ ] Add screenshot of Users table with lock-out column
- [ ] Document in hosting provider deployment guide

### Implementation Notes

**File Organization:**

Create new file: `includes/class-user-management.php`

```php
<?php
namespace Quick_2FA;

class User_Management {
    public function run() {
        // Column display
        add_filter('manage_users_columns', [$this, 'add_lockout_column']);
        add_action('manage_users_custom_column', [$this, 'render_lockout_column'], 10, 3);
        
        // Column sorting
        add_filter('manage_users_sortable_columns', [$this, 'make_column_sortable']);
        add_action('pre_get_users', [$this, 'handle_column_sort']);
        
        // Filters
        add_filter('views_users', [$this, 'add_lockout_filters']);
        add_action('pre_get_users', [$this, 'filter_by_lockout_status']);
        
        // Row actions
        add_filter('user_row_actions', [$this, 'add_lockout_actions'], 10, 2);
        
        // Action handlers
        add_action('admin_action_quick2fa_lock', [$this, 'handle_lock_user']);
        add_action('admin_action_quick2fa_unlock', [$this, 'handle_unlock_user']);
    }
    
    // ... method implementations
}
```

**Performance Considerations:**

- Use transient caching for user counts (5-minute expiry)
- Avoid querying lock-out status for every user on large sites (batch meta queries)
- Consider using `update_user_caches()` to prime user cache
- Indexed user meta queries are efficient for typical user counts (<10,000)

**Security Considerations:**

- Always verify nonces before state changes
- Check capabilities on every action
- Prevent self-lock-out (major support issue)
- Sanitize and validate all user inputs
- Use WordPress functions for redirects (`wp_safe_redirect()`)

**Backwards Compatibility:**

- No database schema changes needed (uses existing user meta)
- Feature is additive (doesn't break existing functionality)
- Works alongside automatic lock-outs seamlessly

---

## Future Enhancements Roadmap

### v1.1 (Planned)

- Remember device functionality (30-day trust period) ✅ Implemented
- User lock-out management UI ✅ Implemented
- WP-CLI management commands
- Export logs to CSV

### v1.2 (Under Consideration)

- Bulk lock/unlock actions in Users table
- SMS verification option
- IP whitelist/blacklist
- Backup codes (10 one-time codes)
- User-facing 2FA management page

### v2.0 (Long-term)

- TOTP authenticator app support
- Push notification verification
- Hardware token support (YubiKey, WebAuthn)
- REST API for programmatic management

---

## Revision History

| Version | Date            | Author    | Changes                         |
| ------- | --------------- | --------- | ------------------------------- |
| 1.0     | 2 December 2025 | Assistant | Initial implementation document |

---

_End of Implementation Document_
