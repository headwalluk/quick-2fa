# Quick 2FA

[![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)](https://github.com/headwalluk/quick-2fa/releases/latest)
[![PHP](https://img.shields.io/badge/PHP-8.0+-purple.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-21759B.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0+-green.svg)](LICENSE)
[![Coding Standards](https://img.shields.io/badge/WordPress-Coding%20Standards-blue.svg)](https://github.com/WordPress/WordPress-Coding-Standards)

Lightweight email-based two-factor authentication for WordPress admin access.

## What it does

- Email-based 2FA verification on every admin login (or only for specific roles)
- Trusted-device tracking so users aren't asked to verify on every login
- Account locking after too many failed attempts
- Password reminders to nudge users towards regular password rotation
- Comprehensive WP-CLI commands for incident response and recovery
- Non-breaking by design — REST API, WP-CLI, AJAX, cron, XML-RPC, and Application Passwords all bypass 2FA so existing integrations keep working

## Install

### Standard plugin

1. Download `quick-2fa.zip` from the [latest release](https://github.com/headwalluk/quick-2fa/releases/latest)
2. WordPress admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate
3. *(Optional)* Settings → Quick 2FA to tune the defaults

After install, the plugin will receive future updates automatically via the bundled GitHub updater. No additional configuration needed.

### Must-Use plugin

1. Extract the `quick-2fa` folder into `wp-content/mu-plugins/`
2. Copy `quick-2fa/quick-2fa.php` up one level into `wp-content/mu-plugins/quick-2fa-loader.php` (or use a loader of your own)
3. Defaults are initialised on first admin page load — no activation hook required
4. Settings → Quick 2FA to configure

### Requirements

- WordPress 6.0 or later
- PHP 8.0 or later
- Working `wp_mail()` (any SMTP plugin or transactional email service)

## Documentation

Full user and developer documentation lives in [`docs/`](docs/):

- [How it works](docs/how-it-works.md)
- [Configuration](docs/configuration.md)
- [Trusted devices](docs/trusted-devices.md)
- [Account locking](docs/account-locking.md)
- [WP-CLI reference](docs/wp-cli.md)
- [Troubleshooting](docs/troubleshooting.md)
- [Hooks and filters](docs/developers/hooks-and-filters.md) *(for developers)*
- [Extending Quick 2FA](docs/developers/extending.md) *(for developers)*

## Security disclosures

See [SECURITY.md](SECURITY.md) for the responsible-disclosure process.

## License

GPL v2 or later. See [LICENSE](LICENSE).
