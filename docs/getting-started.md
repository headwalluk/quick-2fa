# Getting started

## What it does

- Email-based 2FA verification on every admin login (or only for specific roles)
- Trusted-device tracking so users aren't asked to verify on every login
- Account locking after too many failed attempts
- Password reminders to nudge users towards regular password rotation
- Last-login tracking for every user — a record WordPress core does not keep, recorded passively outside the 2FA flow
- Comprehensive WP-CLI commands for incident response and recovery
- Read-only queries for AI agents through the WordPress Abilities API (opt-in, WordPress 6.9+)
- Non-breaking by design — REST API, WP-CLI, AJAX, cron, XML-RPC, and Application Passwords all bypass 2FA so existing integrations keep working

## Requirements

- WordPress 6.2 or later
- PHP 8.2 or later
- Working `wp_mail()` (any SMTP plugin or transactional email service)

## Install

### Standard plugin

1. Download `quick-2fa.zip` from the [latest release](https://github.com/headwalluk/quick-2fa/releases/latest)
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate
3. *(Optional)* Settings → Quick 2FA to tune the defaults

After install, the plugin will receive future updates automatically via the bundled GitHub updater. No additional configuration needed.

### Must-Use plugin

1. Extract the `quick-2fa` folder into `wp-content/mu-plugins/`
2. WordPress doesn't load plugins from subfolders of `mu-plugins/`, so create `wp-content/mu-plugins/quick-2fa-loader.php` to load it:

   ```php
   <?php
   require_once __DIR__ . '/quick-2fa/quick-2fa.php';
   ```

3. Defaults are initialised on first admin page load — no activation hook required
4. Settings → Quick 2FA to configure

WordPress doesn't update must-use plugins, so the bundled updater doesn't apply. Update by replacing the `quick-2fa` folder with the one from a newer release.

## Next steps

- [Configuration](configuration.md) explains every setting
- [How it works](how-it-works.md) covers the request flow and what bypasses 2FA
- [Troubleshooting](troubleshooting.md) has emergency recovery, if you lock yourself out
