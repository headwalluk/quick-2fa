# Quick 2FA

[![Version](https://img.shields.io/github/v/release/headwalluk/quick-2fa?label=version&color=blue)](https://github.com/headwalluk/quick-2fa/releases/latest)
[![PHP](https://img.shields.io/badge/PHP-8.2+-purple.svg)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.2+-21759B.svg)](https://wordpress.org/)
[![License](https://img.shields.io/badge/license-GPL--2.0+-green.svg)](LICENSE)
[![Coding Standards](https://img.shields.io/badge/WordPress-Coding%20Standards-blue.svg)](https://github.com/WordPress/WordPress-Coding-Standards)

Quick 2FA adds email-based two-factor authentication to the WordPress admin. After logging in, a user is sent a short code by email and must enter it before the admin loads. Trusted devices, account locking after repeated wrong codes, and password-age reminders come with it.

It is for site owners who want a second factor on admin logins without an authenticator app, a third-party service or changes to how the site's integrations log in. REST API, WP-CLI, AJAX, cron, XML-RPC and Application Passwords requests are left alone.

## Documentation

### Site owners

- [Getting started](docs/getting-started.md) — What it does, requirements, and installing it as a plugin or must-use plugin
- [How it works](docs/how-it-works.md) — Request flow, what gets protected, what bypasses 2FA, where data lives and privacy
- [Configuration](docs/configuration.md) — Every setting explained
- [Trusted devices](docs/trusted-devices.md) — How device trust works, expiry, revocation
- [Account locking](docs/account-locking.md) — Automatic and manual locks, emergency lockdown, the event log
- [AI agents](docs/ai-agents.md) — Letting an AI agent query account security, read-only
- [WP-CLI reference](docs/wp-cli.md) — Full command list
- [Troubleshooting](docs/troubleshooting.md) — Common issues and how to recover

### Developers

- [Hooks and filters](docs/developers/hooks-and-filters.md) — The public extension surface
- [Abilities API](docs/developers/abilities.md) — The four read-only abilities: names, inputs and outputs
- [Extending Quick 2FA](docs/developers/extending.md) — Practical recipes (custom intro, custom passwords, disabling auto-updates, reading last-login data)
- [Changelog](CHANGELOG.md) — Every release, with breaking changes stated at the top of their entry
- [Security policy](SECURITY.md) — How to report a vulnerability

## Licence

GPL v2 or later. See [LICENSE](LICENSE).
