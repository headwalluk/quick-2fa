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

Every setting is a WordPress option, so `wp option get` and `wp option update` work on all of them. [Configuration](configuration.md) lists each option name.

One setting has no field on the settings page and can only be changed this way: `quick2fa_disable_trusted_devices`, which makes every login session verify. See [trusted devices → disabling the feature](trusted-devices.md#disabling-the-feature).

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

### Force everyone to verify again (after a security incident)

Clearing trusted devices stops them skipping verification, but a session that has already verified carries on until it ends. To challenge everyone straight away, end their sessions too:

```bash
wp user list --field=ID | xargs -I {} wp quick-2fa clear-devices {}
wp user list --field=ID | xargs -I {} wp user session destroy {} --all
```

The second command logs out every user, including you.
