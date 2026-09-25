# Account locking

## When does an account get locked?

An account is locked automatically when someone enters too many wrong codes:

- The **5th wrong code** entered against the same emailed code locks the account
- The lock lasts for the **Auto-Lock Duration** setting, 60 minutes by default

Only a wrong code counts as a failed attempt. Submitting after the code has expired, or when no code was sent, shows an error but doesn't count. Sending a new code resets the count, and so does a successful verification.

Reaching the verification page takes the account's password, so an automatic lock means someone who knows the password has been guessing codes. Unless the user simply mistyped, treat the password as compromised.

While an account is locked:

- It can't log in. The login form shows how long an automatic lock has left, or for a manual lock, asks the user to contact the site administrator
- Any of its sessions that loads an admin page is logged out
- An `account_locked` event is recorded in its [event log](#event-logging)

## Manual lockout (admin action)

Anyone who can edit users can lock or unlock an account:

- **From the admin UI:** Users → All Users → the **Lock Out** and **Unlock** row actions
- **From WP-CLI:** `wp quick-2fa lock <user>` and `wp quick-2fa unlock <user>`

A manual lock ends all of the account's sessions straight away. It is effectively **permanent**: it is set to expire 200 years ahead, and it lasts until someone unlocks the account. The **Lock Status** column on the Users screen shows "Locked out until …" for an automatic lock and "Locked out (manual)" for a manual one.

Unlocking by hand, from either place, also resets the account's failed-attempt count, because someone is deliberately restoring access. A timed lock that simply runs out leaves the count where it was, so an attacker who waits out the lock gets no fresh set of guesses against the same code. Sending a new code resets the count.

The admin UI won't let you lock your own account, as a guardrail against self-lockout. WP-CLI runs as no particular user, so it has no such guard: `wp quick-2fa lock` will lock whichever account you name, including your own.

Locking an account doesn't revoke its [trusted devices](trusted-devices.md#revoking-devices).

## Emergency lockdown

If you need to lock every user account at once (incident response, suspected credential leak), use:

```bash
wp quick-2fa lock-all --exclude=your_login
```

Always pass `--exclude` with your own login, or you'll lock yourself out too. The command asks for confirmation unless you pass `--yes`.

To undo it:

```bash
wp quick-2fa unlock-all
```

This unlocks **every** locked account, whether the lock was automatic or manual. To be more selective, list locked accounts with `wp quick-2fa list-locked` and unlock them one at a time.

## If you've locked yourself out

See [troubleshooting → I am locked out of my own site](troubleshooting.md#i-am-locked-out-of-my-own-site) for the recovery options, from waiting it out to renaming the plugin folder.

## Rate limit on sending codes

Separately from wrong codes, each user can be sent at most **3 codes per 15-minute window**, counted from the first code sent. This stops anyone, attacker or confused user, from flooding the user's inbox. Reloading the verification page reuses the current code and doesn't count; the **Resend Code** button always sends a new one and does.

The limit is per user, not per session, so someone with the password can use up the legitimate user's allowance. That is an accepted trade-off.

## Event logging

Each user has a security event log. These events are recorded:

| Event | When |
|-------|------|
| `code_generated` | A new verification code was created |
| `code_sent` | The code email was handed to `wp_mail()`, with `success` recording the result |
| `verification_success` | The user entered a valid code |
| `verification_failed` | The user entered a wrong code |
| `account_locked` | The account was locked, automatically or manually |
| `account_unlocked` | The account was unlocked |
| `password_changed` | The user changed their password from the password reminder page |
| `password_reminder_dismissed` | The user dismissed the password reminder |
| `device_revoked` | A trusted device was revoked |
| `all_devices_revoked` | All of the user's trusted devices were revoked |

Each entry records the time, IP address and user agent. The log keeps the **50 most recent entries** per user, newest first, in the `_quick2fa_logs` user meta. There is no screen for it; read it with WP-CLI:

```bash
wp user meta get <user> _quick2fa_logs --format=json
```

`wp quick-2fa status <user>` shows the current failed-attempt count and lock state, but not the log itself.

The log is kept when the plugin is deleted, so it is still there for an incident review. See [how it works → deactivating and deleting the plugin](how-it-works.md#deactivating-and-deleting-the-plugin) to remove it.
