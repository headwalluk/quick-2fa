=== Quick 2FA ===
Contributors: yourusername
Tags: security, two-factor, 2fa, authentication, email
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 0.7.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight email-based two-factor authentication for WordPress admin access.

== Description ==

Quick 2FA is a lightweight security plugin that adds email-based two-factor authentication to your WordPress admin area. Unlike heavy security suites, Quick 2FA focuses on doing one thing well: protecting admin access with minimal overhead.

= Key Features =

* **Email-based verification** - No apps or hardware tokens needed
* **Lightweight** - Minimal dependencies, no JavaScript on auth pages
* **Non-breaking** - Works with REST API, Application Passwords, WooCommerce API, WP-CLI
* **Flexible deployment** - Install from WordPress.org or as a Must Use plugin
* **Password reminders** - Optional reminders to update passwords regularly
* **Customizable** - Configure verification period, protected roles, and page branding

= Perfect For =

* Small WordPress hosting providers deploying to client sites
* Individual site administrators wanting simple 2FA
* E-commerce sites needing to protect all user levels
* Anyone seeking lightweight security without bloat

= How It Works =

1. User logs in to WordPress normally
2. When accessing admin pages, they receive a 6-digit code via email
3. Enter the code to verify identity
4. Stay verified for 3 days (configurable)
5. Optionally receive password change reminders

= Emergency Access =

If you lose email access, you can disable the plugin by:
* Deleting `/wp-content/plugins/quick-2fa/` via FTP/SFTP
* Or for MU plugin: `/wp-content/mu-plugins/quick-2fa/`
* Contact your hosting provider if you need assistance

== Installation ==

= Automatic Installation =

1. Go to Plugins > Add New
2. Search for "Quick 2FA"
3. Click "Install Now" and then "Activate"
4. Plugin works immediately with sensible defaults

= Manual Installation =

1. Download the plugin zip file
2. Upload to `/wp-content/plugins/` and extract
3. Activate through the Plugins menu
4. Configure at Settings > Quick 2FA (optional)

= As Must-Use Plugin =

1. Upload `quick-2fa` folder to `/wp-content/mu-plugins/`
2. Plugin activates automatically
3. Cannot be deactivated (ideal for hosting providers)

== Frequently Asked Questions ==

= Does this work with the REST API? =

Yes! Quick 2FA only affects admin page access via browser. REST API, Application Passwords, WooCommerce API, webhooks, and WP-CLI all continue to work normally.

= What if I lose access to my email? =

Delete the plugin directory via FTP/SFTP, or contact your hosting provider. This is why we recommend documenting your FTP credentials before activation.

= Can I customize the verification pages? =

Yes! You can add a logo and custom intro text. The pages use minimal HTML/CSS with no theme loading for maximum security.

= Does this work on Multisite? =

Yes! Works on both single-site and Multisite installations. Each site has independent settings.

= Which user roles require 2FA? =

By default, administrators and any custom roles with `install_plugins` or `manage_options` capabilities. You can configure this to protect specific roles or all users.

= How long does verification last? =

By default, 3 days. You can configure this from 1-30 days in settings.

= Does this prevent password managers from working? =

No! Our password reminder page is specifically designed to be compatible with password manager browser extensions.

= Can I manage locked users from the command line? =

Yes! Quick 2FA includes comprehensive WP-CLI support. See the WP-CLI Commands section below.

== WP-CLI Commands ==

Quick 2FA provides powerful command-line tools for managing user lock-out status:

= Lock/Unlock Individual Users =

`wp quick-2fa lock <user>`
Lock a user account and terminate all active sessions.

`wp quick-2fa unlock <user>`
Unlock a user account and reset failed attempt counter.

= Emergency Lockdown =

`wp quick-2fa lock-all [--exclude=<user>]`
Lock ALL users (optionally exclude one admin account). Useful when your site is under attack.

`wp quick-2fa unlock-all`
Unlock all currently locked users.

= User Management =

`wp quick-2fa status <user>`
Show comprehensive status including lock status, last verification, failed attempts, and trusted devices.

`wp quick-2fa list-locked [--format=<format>]`
List all locked users. Supports table, CSV, JSON, and YAML formats.

`wp quick-2fa clear-devices <user>`
Remove all trusted devices for a user.

= Examples =

    # Emergency: Lock down entire site except admin
    wp quick-2fa lock-all --exclude=admin

    # Check user status
    wp quick-2fa status john_doe

    # List locked users as CSV
    wp quick-2fa list-locked --format=csv

    # Unlock a specific user
    wp quick-2fa unlock jane_smith

All commands accept user ID, login, or email address as the user identifier.

== Screenshots ==

1. Verification page - Clean, simple email code entry
2. Password reminder page - Compatible with password managers
3. Settings page - All options in one place
4. Admin notice when 2FA is disabled

== Changelog ==

= 0.7.2 =
* Added User Switching plugin compatibility - automatic bypass when switching users
* Improves admin UX when managing multiple accounts
* No configuration needed - works automatically when User Switching is active

= 0.7.1 =
* Trusted devices now always enabled - removed admin toggle setting
* Refactored option logic: OPTION_ENABLE_TRUSTED_DEVICES → OPTION_DISABLE_TRUSTED_DEVICES
* Users can still choose whether to trust their device during verification
* Cleaner default handling - missing option defaults to enabled
* Removed "Allow users to trust devices" checkbox from settings page
* Reduced settings complexity while maintaining full functionality

= 0.7.0 =
* Added trusted devices profile section - view and manage devices from user profile
* Added "This Device" indicator on current device in trusted devices list
* Refactored verification flow - flattened nested conditionals for better readability
* Improved nonce verification - wrapped all wp_unslash() calls with sanitize_text_field()
* Removed debug error_log statements from production code
* Code cleanup and formatting improvements across all files
* Enhanced WordPress Coding Standards compliance

= 0.6.1 =
* Added MU plugin compatibility - initialize defaults on first run
* Improved settings page UX - reordered 2FA mode options
* Enhanced email settings with widefat inputs for better visibility
* Code quality improvements with PHPCS

= 0.6.0 =
* Added trusted devices feature with configurable expiry (1-365 days)
* Added comprehensive user lock-out management via admin UI
* Added WP-CLI commands for managing user accounts and security
* Added configurable auto-lock duration setting (1-1440 minutes)
* Enhanced lock-out enforcement for all users at login
* Improved session termination when locking accounts
* Standardized WP-CLI command namespace to quick-2fa
* Fixed permanent vs temporary lock messaging

= 0.3.0 =
* Comprehensive security review and hardening
* Added input sanitization callbacks for all settings
* Enhanced email header injection protection
* Improved IP address validation with IPv4/IPv6 support
* Code quality improvements: 3,100+ WordPress Coding Standards fixes
* Full compliance with WordPress Plugin Handbook security guidelines
* Added OWASP Top 10 security coverage
* Created comprehensive security audit documentation

= 0.2.0 =
* Added complete settings page with Select2 role selection
* Implemented password reminder functionality with strong password generation
* Fixed session handling to keep users logged in after password change
* Added support for invalid registration dates
* Improved security with proper session management
* Updated minimum requirements: PHP 8.2, WordPress 6.0

= 0.1.0 =
* Initial development release
* Email-based two-factor authentication
* Verification code generation and validation
* Rate limiting and account locking
* Event logging system

== Upgrade Notice ==

= 1.0.0 =
Initial release of Quick 2FA.

== Support ==

For support, please visit the plugin's support forum on WordPress.org or GitHub repository.

== Privacy Policy ==

Quick 2FA stores the following data:
* Hashed verification codes (user meta)
* Verification timestamps (user meta)
* Login attempt logs (user meta, last 50 entries)
* IP addresses in logs (for security auditing)

All codes are hashed and never stored in plain text. Logs are limited to 50 entries per user. No data is sent to external services.
