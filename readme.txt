=== Quick 2FA ===
Contributors: headwall
Tags: security, two-factor, 2fa, authentication, email
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight email-based two-factor authentication for WordPress admin access.

== Description ==

Quick 2FA adds email-based two-factor authentication to the WordPress admin area without touching the rest of your site. REST API, WP-CLI, AJAX, cron, XML-RPC and Application Passwords all bypass the 2FA check, so existing automations and integrations keep working unchanged.

Distributed via GitHub releases with in-plugin auto-updates from [headwalluk/quick-2fa](https://github.com/headwalluk/quick-2fa). The plugin is **not** listed on wordpress.org — install via the GitHub release zip.

**Full documentation lives in the [GitHub repository](https://github.com/headwalluk/quick-2fa)** — see the project [README](https://github.com/headwalluk/quick-2fa/blob/master/README.md) and the [`docs/`](https://github.com/headwalluk/quick-2fa/tree/master/docs) directory for how it works, configuration, trusted devices, account locking, WP-CLI reference, troubleshooting, and developer hooks.

== Installation ==

1. Download `quick-2fa.zip` from the [latest GitHub release](https://github.com/headwalluk/quick-2fa/releases/latest)
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate
3. (Optional) Settings → Quick 2FA to tune the defaults

The plugin will receive future updates automatically via its bundled GitHub updater.

For Must-Use plugin installation and other deployment options, see the project README on GitHub.

== Frequently Asked Questions ==

= Does this break the REST API, WP-CLI, or webhooks? =

No. Quick 2FA only intercepts browser-based admin page access. REST API, Application Passwords, WP-CLI, AJAX, cron, and XML-RPC all bypass the 2FA check. Existing integrations keep working unchanged.

= What if I lose access to my email? =

Run `wp quick-2fa emergency_disable --yes` via WP-CLI, or rename the plugin folder via SFTP to force-deactivate it. Full recovery procedures are in [`docs/troubleshooting.md`](https://github.com/headwalluk/quick-2fa/blob/master/docs/troubleshooting.md) on GitHub.

= Where's the full documentation? =

On GitHub: [headwalluk/quick-2fa](https://github.com/headwalluk/quick-2fa). The [`docs/`](https://github.com/headwalluk/quick-2fa/tree/master/docs) directory covers how it works, all settings, trusted devices, account locking, WP-CLI commands, troubleshooting, and the developer hook reference.

= How do I report a security issue? =

See [`SECURITY.md`](https://github.com/headwalluk/quick-2fa/blob/master/SECURITY.md) in the repository for the responsible-disclosure process.

== Changelog ==

= 1.1.0 =
**Breaking:** the `headwall_github_updater_enabled` filter has been renamed to `quick_2fa_updater_enabled`. Sites using the old filter name will silently stop disabling auto-updates after upgrading — update your `add_filter()` call. The GitHub updater has been integrated into the plugin's namespace as `Quick_2FA\Github_Updater`. See [CHANGELOG.md](https://github.com/headwalluk/quick-2fa/blob/master/CHANGELOG.md) on GitHub.

= 1.0.1 =
Fix: restore compatibility with WordPress's theme/plugin file editor — "Update File" no longer reverts changes with a `loopback_request_failed` error. See [CHANGELOG.md](https://github.com/headwalluk/quick-2fa/blob/master/CHANGELOG.md) on GitHub.

= 1.0.0 =
Initial public release. See [CHANGELOG.md](https://github.com/headwalluk/quick-2fa/blob/master/CHANGELOG.md) on GitHub.

== Upgrade Notice ==

= 1.1.0 =
**Breaking filter rename:** `headwall_github_updater_enabled` → `quick_2fa_updater_enabled`. If you use the old filter to disable auto-updates (e.g. on staging), update the filter name before upgrading or auto-updates will silently re-enable.

= 1.0.1 =
Bug fix for the built-in theme/plugin file editor. Recommended for any site that uses **Appearance → Theme File Editor** or **Plugins → Plugin File Editor**.

= 1.0.0 =
Initial public release.

== Privacy Policy ==

Quick 2FA stores the following data locally on your WordPress site:

* Hashed verification codes (user meta, never plaintext)
* Verification and password-change timestamps (user meta)
* Security event log, capped at 50 entries per user (user meta)
* IP addresses in the event log, for incident investigation
* Trusted device fingerprints (user meta, hashed)

No data is sent to external services. The in-plugin GitHub updater polls `api.github.com/repos/headwalluk/quick-2fa/releases/latest` on a 12-hour cache to check for updates; disable it via the `quick_2fa_updater_enabled` filter if needed.
