# Quick 2FA - Requirements Document

**Version:** 1.0  
**Date:** 2 December 2025  
**Status:** Draft

---

## Overview

Quick 2FA is a lightweight WordPress plugin that provides email-based two-factor authentication for administrative access. The plugin is designed to be minimalist, secure, and compatible with WordPress.org distribution and Must Use (MU) plugin installation.

### Target Audience

The primary target audience for this plugin is **small WordPress hosting providers** who want to deploy it across their clients' sites as a Must-Use plugin. The plugin must work reliably out-of-the-box with minimal configuration required.

Secondary audience includes individual site administrators installing from WordPress.org who want a simple, lightweight 2FA solution without the complexity of full-featured security plugins.

---

## Core Principles

1. **Lightweight** - Minimal dependencies, no JavaScript, simple HTML-only UI
2. **Non-Breaking** - Must not interfere with REST API, Application Passwords, WooCommerce API keys, webhooks, WP-CLI, or cron jobs
3. **Flexible Deployment** - Installable from wordpress.org or as an MU plugin in `wp-content/mu-plugins/`
4. **Security-First** - Codes stored as hashes, rate limiting, expiry mechanisms

---

## Primary Features

### 1. Email-Based 2FA Verification

When an authenticated admin user attempts to access any URL path starting with `/wp-admin/` (HTML pages only):

1. Check if user's account has been verified within the verification period
2. If NOT verified:
   - Generate a 6-digit numeric code
   - Hash the code using WordPress password hashing (`wp_hash_password()`)
   - Store hashed code in user meta with timestamp
   - Email the plain-text code to the user's email address
   - Redirect user to verification page
3. Verification page displays:
   - Simple HTML form requesting the 6-digit code
   - Instructions to check email
   - Option to resend code (with rate limiting)
4. On form submission:
   - Verify submitted code against hashed value
   - Track failed attempts
   - On success: update "last verified" timestamp
   - On failure: increment failure counter, show error
5. After successful verification, redirect to originally requested admin page

### 2. Password Change Reminder

After successful 2FA verification, check if user should be reminded to change their password:

1. Check when user last changed their password (using `user_pass` modification time or custom meta)
2. Check when user was last shown the password change reminder
3. If password hasn't been changed in X days (configurable) AND reminder hasn't been shown recently:
   - Redirect to password change reminder page
   - Display page with "Set a new strong random password now" button
4. Password change page structure:
   - Must include form elements compatible with password manager browser extensions
   - Should include hidden username field (pre-filled with user's email)
   - Should include password field (pre-filled with generated strong password)
   - Button to submit and update password
   - Option to "Remind me later" (delays reminder by 1 day)
5. On password update:
   - Update user password
   - Update "last password changed" timestamp
   - Clear any existing sessions (except current one)
   - Redirect to originally requested admin page

---

## Technical Requirements

### Request Detection

**MUST trigger 2FA:**

- HTTP requests to URLs starting with `/wp-admin/`
- Request type: HTML pages (not AJAX, not REST API)
- User authentication: via cookie-based sessions

**MUST NOT trigger 2FA:**

- REST API requests (any authentication method)
- Requests authenticated via Application Passwords
- WooCommerce API requests with API keys
- WordPress AJAX requests (`/wp-admin/admin-ajax.php`)
- WordPress cron jobs
- WP-CLI commands (detected via `defined('WP_CLI') && WP_CLI` as safety measure)
- Webhook callbacks
- XML-RPC requests
- User's role is not in the protected roles list (when mode = `roles`)
- 2FA Mode is set to `disabled`

### Admin Notifications

**When 2FA Mode = `disabled`:**

- Display a persistent admin notice on ALL admin pages
- Notice type: `error` (red background)
- Message: "Quick 2FA is currently disabled. Your admin area is not protected by two-factor authentication. [Enable 2FA]"
- Notice should NOT be dismissible
- "Enable 2FA" link goes directly to plugin settings page
- Only displayed to users with `manage_options` capability

### Security Implementation

#### Code Generation & Storage

- Generate cryptographically secure random 6-digit codes
- Hash codes using `wp_hash_password()` before storing
- Store in user meta: `_quick2fa_code_hash`, `_quick2fa_code_timestamp`
- Codes expire after 15 minutes
- Maximum 3 active codes per user (newest code supersedes old ones)

#### Rate Limiting

- Maximum 5 verification attempts per code
- Maximum 3 code generation requests per 15 minutes per user
- Lock account after 10 failed verification attempts in 1 hour (require admin unlock or wait period)

#### Session Management

- Store last verification timestamp in user meta: `_quick2fa_last_verified`
- Verification is valid for X days (default: 3)
- Store per-session or per-device if possible (using secure cookies/tokens)

#### Logging

- Log all code generation events (timestamp, user ID, email sent status)
- Log all verification attempts (success/failure, IP address, user agent)
- Log all account locks/unlocks
- Logs should be accessible to site admins via Settings page

---

## Configuration Settings

### General Settings

| Setting              | Type    | Default   | Description                                                                                    |
| -------------------- | ------- | --------- | ---------------------------------------------------------------------------------------------- |
| 2FA Mode             | Select  | `roles`   | Enablement mode: `all` (all users), `roles` (specific roles), `disabled` (completely disabled) |
| Protected Roles      | Array   | See below | Roles requiring 2FA (only visible when mode = `roles`)                                         |
| Verification Period  | Integer | `3`       | Days before re-verification required                                                           |
| Security Code Length | Integer | `6`       | Length of verification code (4-8 digits)                                                       |
| Code Expiry Time     | Integer | `15`      | Minutes before code expires                                                                    |

**2FA Mode Options:**

- **Enabled for all users** (`all`) - ALL users must verify, including subscribers and customers. Recommended for e-commerce sites where subscriber-level accounts could be exploited.
- **Enabled for specific roles** (`roles`) - Only selected roles must verify (DEFAULT)
- **Disabled** (`disabled`) - 2FA is completely disabled; displays persistent admin notice

**Default Protected Roles:**
When mode is set to `roles`, the following roles are selected by default:

- All roles with `install_plugins` capability (typically Administrator)
- All roles with `manage_options` capability (typically Administrator)
- This auto-detection works with custom roles and automatically includes:
  - Administrator (has both capabilities)
  - Any custom administrative roles

**Role Selection Interface:**

- Use Select2 multi-select dropdown for role selection
- All WordPress roles available for selection (including custom roles)
- Pre-select roles with `install_plugins` OR `manage_options` capabilities on first activation
- Once settings are saved, capability inspection is not repeated (saved roles are preserved)
- Allow admin to add/remove any roles from selection
- If no roles are selected, display warning that 2FA is effectively disabled for role-based mode

### Email Settings

| Setting        | Type     | Default                  | Description                                                       |
| -------------- | -------- | ------------------------ | ----------------------------------------------------------------- |
| From Name      | String   | `{Site Name}`            | Email sender name                                                 |
| From Email     | Email    | `{admin_email}`          | Email sender address                                              |
| Subject Line   | String   | `Your verification code` | Email subject                                                     |
| Email Template | Textarea | See below                | Plain text email template with `{code}` and `{name}` placeholders |

### Password Reminder Settings

| Setting                    | Type    | Default | Description                                      |
| -------------------------- | ------- | ------- | ------------------------------------------------ |
| Enable Password Reminders  | Boolean | `true`  | Show password change reminders                   |
| Password Reminder Period   | Integer | `60`    | Days since last password change to show reminder |
| Password Reminder Cooldown | Integer | `1`     | Days before showing reminder again if dismissed  |

### Customization Settings

| Setting                 | Type     | Default      | Description                                 |
| ----------------------- | -------- | ------------ | ------------------------------------------- |
| Logo URL                | URL      | Empty        | Logo displayed above verification form      |
| Verification Page Intro | Textarea | Default text | Plain text intro for verification page      |
| Password Reminder Intro | Textarea | Default text | Plain text intro for password reminder page |

---

## User Interface

### Verification Page (`/wp-admin/quick-2fa-verify`)

**HTML Structure:**

```html
<!doctype html>
<html>
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Account Verification Required</title>
    <style>
      /* Minimal inline CSS for basic styling */
    </style>
  </head>
  <body>
    <div class="q2fa-container">
      <!-- Logo (if configured) -->
      <img src="{logo_url}" alt="Logo" class="q2fa-logo" />

      <!-- Custom intro text -->
      <p>{custom_intro_text}</p>

      <!-- Default instructions -->
      <p>Please check your email for a verification code and enter it below.</p>

      <!-- Error messages (if any) -->
      <div class="q2fa-error">{error_message}</div>

      <!-- Verification form -->
      <form method="post" action="">
        <?php wp_nonce_field('quick2fa_verify'); ?>
        <label for="q2fa_code">Verification Code:</label>
        <input type="text" name="q2fa_code" id="q2fa_code" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code" inputmode="numeric" />
        <button type="submit" name="q2fa_verify">Verify</button>
      </form>

      <!-- Resend code link -->
      <form method="post" action="">
        <?php wp_nonce_field('quick2fa_resend'); ?>
        <button type="submit" name="q2fa_resend">Resend Code</button>
      </form>
    </div>
  </body>
</html>
```

**Requirements:**

- No WordPress theme assets loaded (no `wp_head()` or `wp_footer()`)
- No JavaScript
- Minimal inline CSS for basic layout and styling
- Mobile-responsive using CSS media queries
- Accessible (proper labels, ARIA attributes)
- Form uses POST method with nonce verification
- Autocomplete attribute for password managers

### Password Reminder Page (`/wp-admin/quick-2fa-password`)

**HTML Structure:**

```html
<!doctype html>
<html>
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Update Your Password</title>
    <style>
      /* Minimal inline CSS for basic styling */
    </style>
  </head>
  <body>
    <div class="q2fa-container">
      <!-- Logo (if configured) -->
      <img src="{logo_url}" alt="Logo" class="q2fa-logo" />

      <!-- Custom intro text -->
      <p>{custom_intro_text}</p>

      <!-- Default instructions -->
      <p>It's been {X} days since you last changed your password. For security, we recommend updating it regularly.</p>

      <!-- Password update form -->
      <form method="post" action="" autocomplete="on">
        <?php wp_nonce_field('quick2fa_password'); ?>

        <!-- Hidden username for password manager detection -->
        <input type="hidden" name="username" value="{user_email}" autocomplete="username" />

        <label for="q2fa_new_password">New Password:</label>
        <input type="password" name="q2fa_new_password" id="q2fa_new_password" required autocomplete="new-password" value="{generated_strong_password}" />

        <button type="button" onclick="generatePassword()">Generate Strong Password</button>

        <button type="submit" name="q2fa_update_password">Update Password</button>
      </form>

      <!-- Remind later option -->
      <form method="post" action="">
        <?php wp_nonce_field('quick2fa_remind_later'); ?>
        <button type="submit" name="q2fa_remind_later">Remind Me Later</button>
      </form>
    </div>
  </body>
</html>
```

**Requirements:**

- Form structure compatible with password manager browser extensions
- Username field (hidden) with `autocomplete="username"`
- Password field with `autocomplete="new-password"`
- Pre-filled with strong generated password
- No JavaScript required for core functionality (password generation can be server-side)
- Option to defer reminder

### Settings Page (`/wp-admin/options-general.php?page=quick-2fa`)

Standard WordPress admin page using Settings API:

- **General Settings Section:**
  - 2FA Mode selector (radio buttons: All Users / Specific Roles / Disabled)
  - Role selection via Select2 multi-select dropdown (visible when "Specific Roles" selected)
  - Note: Select2 is included in WordPress core since 5.3, no additional dependencies needed
  - Verification period, code length, code expiry settings
- **Email Settings Section:**
  - From name, from email, subject line
  - Email template editor with placeholder reference
  - "Send Test Email" button
- **Password Reminder Settings Section:**
  - Enable/disable toggle
  - Reminder period and cooldown settings
- **Customization Section:**
  - Logo URL uploader
  - Intro text areas for verification and password pages
- **Activity Log Section:**
  - Recent verifications, failed attempts, account locks
  - Filter by user, event type, date range
- **User Management Section:**
  - List of users with 2FA status
  - Quick actions: reset verification, unlock account, view logs

---

## Workflow Diagrams

### Primary Authentication Flow

```
User requests /wp-admin/* (HTML page)
    ↓
Is user logged in? → NO → Normal WP login flow
    ↓ YES
Is request type excluded? → YES → Allow access
(AJAX, REST, App Password, etc.)
    ↓ NO
Is user role below threshold? → YES → Allow access
    ↓ NO
Is 2FA disabled? → YES → Allow access
    ↓ NO
Was account verified recently? → YES → Continue to password check
    ↓ NO
Generate & email code
    ↓
Redirect to verification page
    ↓
User submits code → Valid? → YES → Store verification timestamp
    ↓ NO                                    ↓
Increment failures                   Continue to password check
Show error
```

### Password Reminder Flow

```
User successfully verified 2FA
    ↓
Are password reminders enabled? → NO → Allow access to admin
    ↓ YES
When was password last changed?
    ↓
< {password_reminder_period} days? → YES → Allow access to admin
    ↓ NO
When was reminder last shown?
    ↓
< {reminder_cooldown} days? → YES → Allow access to admin
    ↓ NO
Redirect to password reminder page
    ↓
User clicks "Update Password"
    ↓
Update password in database
Clear other sessions
Update timestamp
    ↓
Allow access to admin
```

---

## Data Storage

### User Meta Keys

| Meta Key                           | Type    | Description                                          |
| ---------------------------------- | ------- | ---------------------------------------------------- |
| `_quick2fa_code_hash`              | String  | Hashed verification code                             |
| `_quick2fa_code_timestamp`         | Integer | Unix timestamp when code was generated               |
| `_quick2fa_code_attempts`          | Integer | Failed verification attempts for current code        |
| `_quick2fa_last_verified`          | Integer | Unix timestamp of last successful verification       |
| `_quick2fa_last_password_reminder` | Integer | Unix timestamp when password reminder was last shown |
| `_quick2fa_locked_until`           | Integer | Unix timestamp when account lock expires (if locked) |
| `_quick2fa_verification_token`     | String  | Optional: device/session-specific token              |

### Options

| Option Key                            | Type    | Description                                  |
| ------------------------------------- | ------- | -------------------------------------------- |
| `quick2fa_mode`                       | String  | 2FA mode: `all`, `roles`, or `disabled`      |
| `quick2fa_protected_roles`            | Array   | Serialized array of role slugs requiring 2FA |
| `quick2fa_verification_period`        | Integer | Days before re-verification required         |
| `quick2fa_code_length`                | Integer | Length of verification code                  |
| `quick2fa_code_expiry`                | Integer | Minutes before code expires                  |
| `quick2fa_password_reminders_enabled` | Boolean | Enable password change reminders             |
| `quick2fa_password_reminder_period`   | Integer | Days before password reminder                |
| `quick2fa_password_reminder_cooldown` | Integer | Days before showing reminder again           |
| `quick2fa_logo_url`                   | String  | Logo URL for auth pages                      |
| `quick2fa_verify_intro`               | String  | Custom intro text for verification page      |
| `quick2fa_password_intro`             | String  | Custom intro text for password page          |
| `quick2fa_email_from_name`            | String  | Email sender name                            |
| `quick2fa_email_from_email`           | String  | Email sender address                         |
| `quick2fa_email_subject`              | String  | Email subject line                           |
| `quick2fa_email_template`             | Text    | Email template with placeholders             |
| `quick2fa_version`                    | String  | Plugin version for migration handling        |

### Logs

Consider using custom database table for detailed logging:

**Table: `{prefix}_quick2fa_logs`**

```sql
CREATE TABLE {prefix}_quick2fa_logs (
    id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT(20) UNSIGNED NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_data TEXT,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at DATETIME NOT NULL,
    INDEX user_id (user_id),
    INDEX event_type (event_type),
    INDEX created_at (created_at)
);
```

---

## Plugin Architecture

### File Structure

```
quick-2fa/
├── quick-2fa.php                    # Main plugin file
├── readme.txt                       # WordPress.org readme
├── LICENSE                          # GPL v2 or later
├── includes/
│   ├── class-quick2fa.php          # Main plugin class
│   ├── class-authenticator.php      # 2FA logic
│   ├── class-settings.php           # Settings management
│   ├── class-email.php              # Email handling
│   ├── class-logger.php             # Logging functionality
│   ├── class-rate-limiter.php       # Rate limiting
│   └── functions.php                # Helper functions
├── admin/
│   ├── class-admin.php              # Admin interface
│   ├── settings-page.php            # Settings page template
│   └── logs-page.php                # Logs viewer template
├── templates/
│   ├── verify.php                   # Verification page template
│   └── password-reminder.php        # Password reminder template
├── assets/
│   └── css/
│       └── auth-pages.css           # Minimal CSS for auth pages (inline)
└── docs/
    └── requirements.md              # This document
```

### MU-Plugin Compatibility

For MU-plugin installation, create a loader file:

**`wp-content/mu-plugins/quick-2fa-loader.php`:**

```php
<?php
/**
 * Quick 2FA MU-Plugin Loader
 */
require_once WPMU_PLUGIN_DIR . '/quick-2fa/quick-2fa.php';
```

---

## WordPress.org Distribution

### Plugin Header

```php
/**
 * Plugin Name: Quick 2FA
 * Plugin URI: https://example.com/quick-2fa
 * Description: Lightweight email-based two-factor authentication for WordPress admin access
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: quick-2fa
 * Domain Path: /languages
 */
```

### Coding Standards

- Follow WordPress Coding Standards (WPCS)
- Use WordPress-VIP-Go standards for security
- All strings must be internationalized using `__()`, `_e()`, `esc_html__()`, etc.
- Escape all output using appropriate functions
- Validate and sanitize all input
- Use nonces for all form submissions
- Prefix all functions, classes, and database entries with `quick2fa_`

### Dependencies

- **Select2:** Used for role selection in settings page (included in WordPress core since 5.3)
- Enqueue on settings page only: `wp_enqueue_script('select2')` and `wp_enqueue_style('select2')`
- No other external dependencies

---

## Security Considerations

### Identified Risks & Mitigations

1. **Brute Force Attacks**

   - _Risk:_ Attacker attempts many codes
   - _Mitigation:_ Rate limiting, account locking, code expiry

2. **Code Interception**

   - _Risk:_ Email intercepted in transit
   - _Mitigation:_ Short code lifetime (15 min), one-time use

3. **Session Hijacking**

   - _Risk:_ Attacker steals authenticated session
   - _Mitigation:_ 2FA doesn't prevent this; recommend complementary plugins

4. **Email Delivery Failure**

   - _Risk:_ User can't receive code
   - _Mitigation:_ Resend option, logging, admin can unlock accounts

5. **Plugin Bypass**

   - _Risk:_ Attacker finds way to access admin without triggering 2FA
   - _Mitigation:_ Early hook priority, comprehensive request detection

6. **Database Compromise**
   - _Risk:_ Attacker accesses database directly
   - _Mitigation:_ Codes stored as hashes, not plaintext

---

## Implementation Decisions

### Resolved

1. **First-Time Setup**

   - Plugin works out-of-the-box immediately upon activation
   - No grace period, no manual configuration required
   - All eligible users (based on role threshold) are required to verify on next admin access
   - Default settings should be sensible for immediate production use

2. **Emergency Access**

   - **Solution:** Delete plugin directory from server
   - Plugin description on WordPress.org must include clear emergency access instructions
   - Recommend users document their FTP/SFTP credentials before activation
   - Hosting providers should inform clients of this requirement
   - **Instructions to include:** "If you lose email access, delete `/wp-content/plugins/quick-2fa/` (or `/wp-content/mu-plugins/quick-2fa/`) via FTP/SFTP or contact your hosting provider for assistance."

3. **WordPress Multisite**

   - Plugin should work with Multisite installations without special handling
   - When installed as regular plugin: activated per-site with per-site settings
   - When installed as MU plugin: automatically active on all sites
   - Network admin area follows same rules as regular admin area
   - No network-wide settings panel in v1.0 (each site configures independently)

4. **WP-CLI Detection**
   - Include WP-CLI detection as safety measure: `defined('WP_CLI') && WP_CLI`
   - Primary protection is URL path detection (`/wp-admin/` HTML pages only)
   - WP-CLI detection adds extra layer of protection against edge cases

### Open Questions

4. **Role Granularity:** Should settings allow:

   - Different verification periods per role?
   - Exempting specific users (not just roles)?
   - Different code lengths per role?

5. **Backup Codes:** Should we generate one-time backup codes (like Google Authenticator)?
   - Useful if email access is lost
   - Adds complexity
   - Storage and UI considerations

---

## Success Criteria

The plugin will be considered successful when:

1. ✅ Installed and activated without errors on WordPress 5.8+ (single-site and multisite)
2. ✅ Works immediately out-of-the-box with sensible defaults (no configuration required)
3. ✅ Admin users are prompted for verification code on admin access
4. ✅ REST API, Application Passwords, and WooCommerce API continue to work
5. ✅ WP-CLI commands execute without triggering 2FA
6. ✅ Verification codes are hashed in database (not plaintext)
7. ✅ Password reminders appear at configured intervals
8. ✅ Password manager extensions can detect and save new passwords
9. ✅ All settings are configurable via Settings page
10. ✅ No JavaScript required for core functionality
11. ✅ No WordPress theme/plugin assets loaded on auth pages
12. ✅ Plugin passes WordPress.org plugin review requirements
13. ✅ Plugin works as MU plugin without modifications
14. ✅ Emergency access instructions clearly documented in plugin description
15. ✅ Suitable for deployment by hosting providers across multiple client sites

---

## Future Enhancements (Out of Scope for v1.0)

### Planned for v1.1+

- **Remember device functionality** - "Trust this device for 30 days" checkbox option
  - Uses secure browser cookies/tokens
  - Device fingerprinting for additional security
  - Per-user management of trusted devices
- **WP-CLI management commands** - Full plugin control via command line
  - Enable/disable 2FA globally or per-user
  - Reset verification status
  - Unlock accounts
  - View/clear logs
  - Generate emergency bypass tokens

### Under Consideration

- SMS-based 2FA option
- TOTP authenticator app support (Google Authenticator, Authy, etc.)
- Push notification 2FA
- Hardware token support (YubiKey, etc.)
- Backup codes system
- IP whitelist/blacklist
- Geographic restrictions
- Integration with security plugins (Wordfence, iThemes Security, etc.)
- REST API endpoints for programmatic management
- User-facing 2FA management dashboard
- WordPress Multisite network-wide administration panel

---

## Revision History

| Version | Date            | Author    | Changes                       |
| ------- | --------------- | --------- | ----------------------------- |
| 1.0     | 2 December 2025 | Assistant | Initial requirements document |

---

## Appendices

### Default Email Template

```
Hello {name},

Your verification code is: {code}

This code will expire in 15 minutes.

If you did not request this code, please contact your site administrator immediately.

---
{site_name}
{site_url}
```

### Default Verification Page Intro

```
For your security, we need to verify your identity before you can access the admin area.
```

### Default Password Reminder Intro

```
Regular password changes help keep your account secure. We recommend updating your password every 60 days.
```

---

## User Lock-out Management

### Overview

Since Quick 2FA can automatically lock out users after failed verification attempts, administrators need a straightforward way to view locked users and manually control lock-out status directly from the WordPress Users admin table (`/wp-admin/users.php`).

**Design Philosophy:** Keep it lightweight and integrated into WordPress's native UI patterns. No custom admin pages required.

### Custom Users Table Column

**Column Header:** "2FA Status" or "Security"

**Column Content:**
- **Locked Users:** Display a red padlock icon (🔒 or dashicon `dashicons-lock`)
  - Tooltip/title attribute: "Locked out until [date/time]" (e.g., "Locked out until Dec 2, 2025 3:45 PM")
  - If permanently locked (no expiry): "Locked out (manual)"
- **Unlocked Users:** Display a green checkmark icon (✓ or dashicon `dashicons-yes`) or leave empty
  - Tooltip: "Not locked out" or no tooltip
- **Never Verified:** Display a gray dash (—) or empty state
  - Optional tooltip: "Never verified"

**Column Position:** Insert after the "Email" column, before "Role" column for logical grouping with user identity information.

**Sortability:** Make column sortable by lock-out status (locked users first/last).

### Users Table Filter

**Location:** Add to the existing Views filter row (All | Administrator | Editor | etc.)

**Filter Options:**
1. **All** - Show all users (default view, shows count)
2. **Locked Out** - Show only users currently locked out
3. **Not Locked Out** - Show only users not currently locked out (or never locked)

**Filter Text Format:** `Locked Out (5)` where the number indicates count of users in that state.

**Query Behavior:**
- "Locked Out" filter: Query users where `_quick2fa_locked_until` meta exists AND value > current timestamp
- "Not Locked Out" filter: Query users where meta doesn't exist OR value <= current timestamp

### Row Actions

**Action Label:** Dynamic based on current lock-out status
- If user is locked: **"Unlock"** or **"Remove Lock"**
- If user is not locked: **"Lock Out"** or **"Lock User"**

**Action Behavior:**
- **Lock Action:**
  - Sets `_quick2fa_locked_until` user meta to a far-future timestamp (e.g., 100 years from now, or use `-1` for permanent)
  - Shows admin notice: "User [username] has been locked out."
  - Logs event: `manual_lock` with admin user ID and timestamp
  - Remains on Users table page with filter preserved

- **Unlock Action:**
  - Deletes `_quick2fa_locked_until` user meta
  - Resets `_quick2fa_code_attempts` to 0
  - Shows admin notice: "User [username] has been unlocked."
  - Logs event: `manual_unlock` with admin user ID and timestamp
  - Remains on Users table page with filter preserved

**Security:**
- Requires `edit_users` capability
- Nonce verification on action requests
- Confirm action via admin notice (no separate confirmation dialog for simplicity)
- Prevent locking out yourself (display error: "You cannot lock out your own account.")

**URL Pattern:**
- Lock: `/wp-admin/users.php?action=quick2fa_lock&user=123&_wpnonce=abc123`
- Unlock: `/wp-admin/users.php?action=quick2fa_unlock&user=123&_wpnonce=abc123`

### Bulk Actions

**Optional Enhancement (v1.1+):**
- Add "Lock Out" and "Unlock" to bulk actions dropdown
- Allow administrators to lock/unlock multiple users at once
- Confirmation notice: "5 users have been locked out." / "3 users have been unlocked."

**Not included in v1.0** to keep initial implementation lightweight.

### Technical Implementation Notes

**Hooks to Use:**
- `manage_users_columns` - Add custom column header
- `manage_users_custom_column` - Render column content
- `manage_users_sortable_columns` - Make column sortable
- `views_users` - Add filter links
- `pre_get_users` - Modify user query for filters
- `user_row_actions` - Add lock/unlock row action
- `admin_action_quick2fa_lock` - Handle lock action
- `admin_action_quick2fa_unlock` - Handle unlock action

**Performance Considerations:**
- User meta queries are indexed by user ID (efficient)
- Avoid N+1 queries: use `update_user_caches()` if displaying many users
- Lock-out filter uses meta_query (indexed, performant for typical user counts)

**UI Guidelines:**
- Use WordPress Dashicons for consistency
- Match WordPress admin color scheme (red = locked, green = unlocked)
- Keep tooltips concise
- Follow WordPress admin notice patterns for feedback

### User Experience Flow

**Scenario 1: Admin discovers locked user**
1. Navigate to Users → All Users
2. See red padlock icon in "2FA Status" column
3. Hover over icon: sees "Locked out until Dec 2, 2025 3:45 PM"
4. Click "Unlock" row action
5. User meta deleted, admin notice confirms: "User john@example.com has been unlocked."

**Scenario 2: Admin needs to temporarily disable user access**
1. Navigate to Users → All Users
2. Find user, click "Lock Out" row action
3. User meta set, admin notice confirms: "User jane@example.com has been locked out."
4. User cannot access `/wp-admin/` until unlocked
5. Later, admin clicks "Unlock" to restore access

**Scenario 3: Admin wants to review all locked users**
1. Navigate to Users → All Users
2. Click "Locked Out (5)" filter
3. Table shows only locked users
4. Admin can quickly unlock specific users via row actions

### Edge Cases to Handle

1. **User locks themselves:** Prevent with capability check + current user ID check
2. **Expired lock shows as locked:** Check timestamp before displaying icon
3. **Super admin on multisite:** Should have permission to unlock any user on any site
4. **User is locked during active session:** Next page navigation triggers 2FA check, sees lock, shows locked message
5. **Manual lock vs automatic lock:** Both use same meta key, same unlock process (no distinction needed)

### Success Metrics

- **Reduced support tickets** about locked users
- **Faster resolution** of lock-out issues (no need to SSH or use phpMyAdmin)
- **Increased adoption** by hosting providers who need manual control
- **No performance impact** on Users table page load time

---

_End of Requirements Document_
