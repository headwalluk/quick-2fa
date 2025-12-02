=== Quick 2FA ===
Contributors: yourusername
Tags: security, two-factor, 2fa, authentication, email
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
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

== Screenshots ==

1. Verification page - Clean, simple email code entry
2. Password reminder page - Compatible with password managers
3. Settings page - All options in one place
4. Admin notice when 2FA is disabled

== Changelog ==

= 1.0.0 =
* Initial release
* Email-based two-factor authentication
* Password change reminders
* Role-based protection
* MU plugin support

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
