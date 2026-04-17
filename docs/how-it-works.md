# How Quick 2FA works

## Design philosophy

Quick 2FA is built around four principles:

1. **Minimal attack surface** — uses WordPress's existing `wp-login.php` endpoint rather than introducing custom URLs or rewrite rules
2. **Early interception** — checks happen on `admin_init` at priority 1, before any admin code loads
3. **No theme/plugin interference** — 2FA pages render in the WordPress login context, where themes don't load and most plugins are dormant
4. **Fail secure** — if anything goes wrong, deny access rather than allow

## Request flow

When a logged-in user accesses any admin page:

```
admin_init (priority 1)
  └── Plugin::check_verification()
        ├── should_skip_check()?
        │     ├── WP-CLI request? → skip
        │     ├── AJAX request? → skip
        │     ├── REST API request? → skip
        │     ├── cron job? → skip
        │     ├── XML-RPC request? → skip
        │     ├── Application Password auth? → skip
        │     ├── User Switching active? → skip
        │     ├── already on a ?q2fa= page? → skip (no loops)
        │     └── plugin in disabled mode? → skip
        │
        ├── Account locked? → wp_logout() + wp_die()
        │
        ├── Verification needed?
        │     └── Yes → store return URL → redirect to ?q2fa=verify
        │
        └── Password reminder needed?
              └── Yes → redirect to ?q2fa=password
```

When the user lands on `wp-login.php?q2fa=verify`:

1. `login_init` fires → `Plugin::handle_login_actions()` renders the verification template
2. A code is generated with `random_int()`, hashed with `wp_hash_password()`, stored in user meta
3. The plain code is emailed to the user via `wp_mail()`
4. User submits the code → `Verification_Code_Handler::verify()` checks against the stored hash
5. On success → optional "trust this device" → retrieve return URL from transient → redirect

## What gets protected

By default, **every user with admin-area access** requires 2FA. This is configurable to "specific roles only" (default: any role with `install_plugins` or `manage_options`) or fully disabled.

## What bypasses 2FA

The following requests skip the 2FA check entirely. **This is intentional and important** — without these bypasses, the plugin would break automation, integrations, and recovery paths.

| Bypass | Why |
|--------|-----|
| WP-CLI | Server-side automation, can't email a code to a script |
| AJAX (`wp_doing_ajax()`) | Already-authenticated requests originating from a 2FA-verified admin page |
| REST API (`REST_REQUEST`) | Application-to-application calls, typically authenticated via Application Passwords |
| Cron (`wp_doing_cron()`) | Scheduled background tasks, no human present |
| XML-RPC (`XMLRPC_REQUEST`) | Legacy integration endpoint, app-level auth |
| Application Passwords | Already represents an explicit out-of-band credential decision |
| User Switching plugin | Admin-initiated impersonation, already authenticated as admin |
| Already on a `?q2fa=` page | Prevents redirect loops |
| Theme/plugin editor loopback | Core's fatal-error check after a PHP edit loops back with cookie auth but WP's own User-Agent, which would otherwise fail the trusted-device check and cause the edit to be reverted. Validated against core's `scrape_key_*` transient so bare query params don't bypass |
| Plugin mode set to `disabled` | The master off switch — useful as a temporary recovery state (see [configuration](configuration.md#mode)) |

If you need to *also* protect any of these endpoints, that's a different security control (Application Passwords with strong scope, IP allow-listing at the web server, etc.) — Quick 2FA deliberately stays out of those layers.

## Where data lives

| Data | Storage |
|------|---------|
| Per-user state (code hash, lock status, trusted devices, event log) | `wp_usermeta` |
| Plugin settings | `wp_options` (autoloaded) |
| Return URLs after verification | Transients (`q2fa_return_{user_id}`, 5-minute TTL) |
| Rate limit counters | Transients (`q2fa_rate_limit_*`) |

Codes are **never stored in plaintext** — they're hashed with `wp_hash_password()` and verified with `wp_check_password()`, the same primitives WordPress uses for user passwords.

## See also

- [Configuration](configuration.md) — Tuning the behaviour
- [Trusted devices](trusted-devices.md) — How device trust changes the request flow
- [Account locking](account-locking.md) — What happens after too many failed attempts
