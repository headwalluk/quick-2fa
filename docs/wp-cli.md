# WP-CLI reference

All commands are namespaced under `wp quick-2fa`. Run `wp help quick-2fa <command>` for the canonical built-in help on any command.

> The `<user>` argument accepts a numeric user ID, a login (e.g. `john_doe`), or an email address.

## User management

### `lock <user>`

Lock a user account permanently and end all of its active sessions.

```bash
wp quick-2fa lock 123
wp quick-2fa lock john_doe
wp quick-2fa lock john@example.com
```

WP-CLI runs as no particular user, so nothing stops this locking your own account. The admin UI refuses to; the CLI does not.

### `unlock <user>`

Unlock a user account and reset their failed-attempt counter.

```bash
wp quick-2fa unlock 123
```

### `lock-all [--exclude=<user>] [--yes]`

Emergency lockdown — lock every user account at once. **Always use `--exclude` to keep yourself unlocked.**

```bash
wp quick-2fa lock-all --exclude=admin
wp quick-2fa lock-all --exclude=admin --yes    # skip confirmation prompt
```

### `unlock-all [--yes]`

Unlock every currently-locked user and reset their failed-attempt counters. Asks for confirmation unless `--yes` is given.

```bash
wp quick-2fa unlock-all
wp quick-2fa unlock-all --yes    # skip confirmation prompt
```

### `status <user> [--format=table|json|yaml]`

Show a user's login, email, lock state, last verification time, failed attempt count and trusted device count.

```bash
wp quick-2fa status admin
wp quick-2fa status john_doe --format=json
```

### `list-locked [--format=table|csv|json|yaml]`

List every currently-locked user with their lock-until time, or `Permanent` for a manual lock.

```bash
wp quick-2fa list-locked
wp quick-2fa list-locked --format=csv > locked.csv
```

## Trusted devices

### `clear-devices <user>`

Remove all trusted device tokens for a user. They'll need to verify on every device on their next login.

```bash
wp quick-2fa clear-devices admin
```

## Emergency

### `emergency-disable [--yes]`

Set Quick 2FA to `disabled` mode, bypassing all 2FA checks. Use this only if you've genuinely locked yourself out and you have no other recovery path.

```bash
wp quick-2fa emergency-disable          # asks for confirmation
wp quick-2fa emergency-disable --yes
```

The action is recorded to the PHP error log so it leaves a paper trail. **Re-enable 2FA from the settings page as soon as you've recovered access.**

## Configuration via CLI

A small number of plugin settings have **no UI** in the settings page and must be set from the CLI (or via direct database edit). They use the standard WordPress `wp option` commands.

### Disable trusted devices

Require verification once per login session, regardless of any previously-trusted devices:

```bash
wp option update quick2fa_disable_trusted_devices 1
```

Re-enable the feature:

```bash
wp option update quick2fa_disable_trusted_devices 0
```

When set to `1`, the trusted-devices feature is bypassed entirely — existing trusted-device entries in user meta are ignored but not deleted, so re-enabling restores the previous state (subject to per-device expiry).

This is intentional CLI-only — there's no settings UI checkbox. If you want to add one, see the project tracker.

## Plugin updates

The in-plugin GitHub updater also runs under WP-CLI (from 1.5.0), so the standard plugin commands see and install new releases:

```bash
wp plugin list --name=quick-2fa --fields=name,version,update,update_version
wp plugin update quick-2fa
```

See [troubleshooting](troubleshooting.md#the-plugin-updater-isnt-picking-up-new-releases) if a release isn't showing up.

## Examples — common operational tasks

### Audit who's locked

```bash
wp quick-2fa list-locked --format=table
```

### Reset a single user after they fat-fingered codes too many times

```bash
wp quick-2fa unlock john_doe
wp quick-2fa status john_doe   # confirm
```

### Suspected credential leak — lock everyone except yourself

```bash
wp quick-2fa lock-all --exclude=your_login
```

### Force everyone to re-verify on next login (after a security incident)

```bash
# Wipe all trusted devices, plugin-wide
wp user list --field=ID | xargs -I {} wp quick-2fa clear-devices {}
```
