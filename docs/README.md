# Quick 2FA — Documentation

Lightweight email-based two-factor authentication for WordPress admin access.

## For site administrators

- [How it works](how-it-works.md) — Request flow, what gets protected, what bypasses 2FA
- [Configuration](configuration.md) — Every setting explained
- [Trusted devices](trusted-devices.md) — How device trust works, expiry, revocation
- [Account locking](account-locking.md) — Lockout thresholds, recovery, emergency disable
- [WP-CLI reference](wp-cli.md) — Full command list
- [Troubleshooting](troubleshooting.md) — Common issues and how to recover

## For developers

- [Hooks and filters](developers/hooks-and-filters.md) — The public extension surface
- [Extending Quick 2FA](developers/extending.md) — Practical recipes (custom intro, custom passwords, disabling auto-updates)

## Need to escalate?

- Locked out of your own site? See [troubleshooting → emergency recovery](troubleshooting.md#i-am-locked-out-of-my-own-site)
- Found a security issue? See [`SECURITY.md`](../SECURITY.md) for the responsible disclosure process
