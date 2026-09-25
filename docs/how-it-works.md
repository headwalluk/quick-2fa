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
        │     ├── theme/plugin editor loopback? → skip
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
2. If the user has no valid code yet, one is generated with `random_int()`, hashed with `wp_hash_password()` and stored in user meta. A reload reuses the existing code instead of sending another
3. The plain code is emailed to the user via `wp_mail()`
4. User submits the code → `Verification_Code_Handler::verify()` checks against the stored hash
5. On success → the current login session is marked as verified → if trusted devices are enabled, the device-trust cookie is set (see [trusted devices](trusted-devices.md)) → the return URL is read from its transient → redirect
6. On the next admin page load, the password reminder check runs, and may redirect to `wp-login.php?q2fa=password`

## What gets protected

By default (**Enabled for all users**), every user who reaches the admin area must verify, including subscribers, who can open their profile page. **Enabled for specific roles** limits this to the protected roles, which default to any role with the `install_plugins` or `manage_options` capability. See [configuration](configuration.md#2fa-mode).

The check runs when an admin page loads, so a login that never visits `wp-admin` is not challenged. A shop customer who logs in and stays on the front end, for example, never sees the verification page.

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
| Theme/plugin editor loopback | Core's fatal-error check after a PHP edit loops back with the admin's auth cookie but not the device-trust cookie, so it would otherwise fail the trusted-device check, get redirected to verification, and cause the edit to be reverted. Validated against core's `scrape_key_*` transient so bare query params don't bypass |
| Plugin mode set to `disabled` | The master off switch — useful as a temporary recovery state (see [configuration](configuration.md#2fa-mode)) |

If you need to *also* protect any of these endpoints, that's a different security control (Application Passwords with strong scope, IP allow-listing at the web server, etc.) — Quick 2FA deliberately stays out of those layers.

## Last-login recording

Since v1.3.0 Quick 2FA records **when each user last logged in**, for every user, on every
successful login — not just the logins it challenges. WordPress core keeps no such record.

This is the one thing the plugin does outside the 2FA flow. It is passive: a `wp_login`
listener and a single user-meta write. It does not change who is challenged, does not read or
write any 2FA state, and has no setting. Nothing in Quick 2FA consumes the data — it is
recorded for site owners and integrations that need to answer "who has gone dormant?".

The same bypasses listed above apply, for the same underlying reason: Application Password and
REST authentication do not fire `wp_login`, so they leave no timestamp. A missing timestamp
therefore means *unknown*, not *never logged in*. Developers consuming this data should read
[reading last-login data](developers/extending.md#reading-last-login-data) first, and site
owners who don't want it recorded can disable it with the `quick2fa_record_last_login`
[filter](developers/hooks-and-filters.md#quick2fa_record_last_login).

## Where data lives

| Data | Storage |
|------|---------|
| Per-user state (code hash, lock status, trusted devices, event log) | `wp_usermeta`, keys starting `_quick2fa_` |
| Last-login timestamp (`_quick2fa_last_login`) | `wp_usermeta` |
| Date this site started recording logins (`quick2fa_last_login_since`) | `wp_options` (autoloaded) |
| Plugin settings | `wp_options` (autoloaded), names starting `quick2fa_` |
| Which login sessions have verified | WordPress's own session data (`session_tokens` user meta), removed when the session ends |
| Device-trust token | A cookie in the user's browser; only its SHA-256 hash is stored server-side |
| Return URLs after verification | Transients (`q2fa_return_{user_id}`, 5-minute TTL) |
| Code-sending rate limit | Transients (`q2fa_rate_limit_code_gen_{user_id}`, 15-minute TTL) |

Codes are **never stored in plaintext** — they're hashed with `wp_hash_password()` and verified with `wp_check_password()`, the same primitives WordPress uses for user passwords.

## Deactivating and deleting the plugin

Deactivating Quick 2FA leaves all of its data in place, so reactivating it picks up where it left off.

Deleting the plugin from the Plugins screen removes **only the trusted-device lists**. Settings, account locks, event logs, last-login timestamps and any outstanding code hashes stay in the database. A locked account is therefore still locked if the plugin is installed again. Last-login timestamps are kept on purpose, because they can't be recreated (see [reading last-login data](developers/extending.md#retention)).

To remove everything after deleting the plugin:

```bash
wp eval 'global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( "_quick2fa_" ) . "%" ) );'
wp option list --search='quick2fa_*' --field=option_name | xargs -n1 wp option delete
wp cache flush
```

## See also

- [Configuration](configuration.md) — Tuning the behaviour
- [Trusted devices](trusted-devices.md) — How device trust changes the request flow
- [Account locking](account-locking.md) — What happens after too many failed attempts
