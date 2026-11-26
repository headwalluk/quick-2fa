# Quick 2FA — Documentation

Lightweight email-based two-factor authentication for WordPress admin access.

## For site administrators

- [Getting started](getting-started.md) — What it does, requirements, and installing it as a plugin or must-use plugin
- [How it works](how-it-works.md) — Request flow, what gets protected, what bypasses 2FA, where data lives and privacy
- [Configuration](configuration.md) — Every setting explained
- [Trusted devices](trusted-devices.md) — How device trust works, expiry, revocation
- [Account locking](account-locking.md) — Automatic and manual locks, emergency lockdown, the event log
- [AI agents](ai-agents.md) — Letting an AI agent query account security, read-only
- [WP-CLI reference](wp-cli.md) — Full command list
- [Troubleshooting](troubleshooting.md) — Common issues and how to recover

## For developers

- [Hooks and filters](developers/hooks-and-filters.md) — The public extension surface
- [Abilities API](developers/abilities.md) — The four read-only abilities: names, inputs and outputs
- [Extending Quick 2FA](developers/extending.md) — Practical recipes (custom intro, custom passwords, disabling auto-updates, reading last-login data)
- [Changelog](../CHANGELOG.md) — Every release, with breaking changes stated at the top of their entry

## Need to escalate?

- Locked out of your own site? See [troubleshooting → emergency recovery](troubleshooting.md#i-am-locked-out-of-my-own-site)
- Found a security issue? See [`SECURITY.md`](../SECURITY.md) for the responsible disclosure process
