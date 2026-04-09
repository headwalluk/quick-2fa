# Account locking

## When does an account get locked?

An account is locked automatically when a user fails verification too many times. The current thresholds:

- **5 failed verification attempts** within a single verification session
- Lockout duration: **60 minutes** by default (configurable via the "Lockout duration" setting)

A failed attempt counts whether the code was wrong, expired, or never requested. A successful verification resets the counter.

Locked users:

- Are blocked at `wp_authenticate_user` — they can't even reach the verification page
- Receive a clear error message stating how long the lock will last
- Are automatically logged out of any active sessions on the next admin page load
- Have a `LOG_ACCOUNT_LOCKED` event recorded in their security event log

## Manual lockout (admin action)

Site administrators can manually lock or unlock any user:

- **From the admin UI:** Users → All Users → row actions ("Lock Out" / "Unlock")
- **From WP-CLI:** `wp quick-2fa lock <user>` and `wp quick-2fa unlock <user>`

Manual locks are **permanent** — they have no expiry timestamp and persist until an admin unlocks the account explicitly. The "Lock Status" column on the Users table shows whether each lock is automatic (with an unlock time) or manual (permanent).

You cannot lock your own account, either via the admin UI or via WP-CLI. This is a deliberate guardrail to prevent self-lockout.

## Emergency lockdown

If you need to lock every user account at once (incident response, suspected credential leak), use:

```bash
wp quick-2fa lock-all --exclude=admin
```

The `--exclude` argument is critical — without it, you'll lock yourself out too. The command requires confirmation unless you pass `--yes`.

To recover, use:

```bash
wp quick-2fa unlock-all
```

This unlocks **all** locked users — both automatic and manual locks. If you want to be more selective, list locked users with `wp quick-2fa list-locked` and unlock them individually.

## Recovering from a lockout you can't WP-CLI out of

If you don't have shell access and you've locked yourself out:

```bash
wp quick-2fa emergency_disable --yes
```

This sets `OPTION_MODE` to `disabled`, bypassing 2FA entirely. After you log back in, **re-enable 2FA immediately** and investigate why the lockout happened.

If you don't have *any* shell access at all, you can do the same thing directly via the database:

```sql
UPDATE wp_options SET option_value = 'disabled' WHERE option_name = 'quick2fa_mode';
```

(Adjust the table prefix to match your installation.)

## Rate limit on code generation

Separately from verification attempts, there's a rate limit on **code generation**: a user can request at most **3 codes per 15-minute window**. This prevents an attacker (or a confused user) from spamming the user's inbox.

The limit is per-user across all sessions — not per-session. If an attacker has a hijacked session, they can exhaust a legitimate user's code-generation budget. This is a known trade-off (see the project tracker's open review items).

## Event logging

Every lock-relevant event is recorded in the per-user security event log:

| Event | When |
|-------|------|
| `code_generated` | A new verification code was created |
| `code_sent` | The code email was dispatched (success or failure) |
| `verification_success` | The user entered a valid code |
| `verification_failed` | The user entered an invalid code |
| `account_locked` | Account was locked (automatic or manual) |
| `account_unlocked` | Account was unlocked |
| `password_changed` | User changed their password via the reminder flow |
| `device_revoked` | A trusted device was revoked |
| `all_devices_revoked` | All trusted devices were revoked for the user |

The log is capped at **50 entries per user** and stored in `wp_usermeta`. View it via `wp quick-2fa status <user>` (shows summary) or directly via `get_user_meta( $user_id, '_quick2fa_logs', true )`.

The event log is **preserved on plugin uninstall** by default (it may be useful for incident review even after Quick 2FA is removed). See the project tracker for the open review item on uninstall data retention.
