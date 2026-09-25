# Hooks and filters

This is the **public extension surface** of Quick 2FA. Anything not listed here should be considered internal — it may change without notice between releases. Functions in the `Quick_2FA` namespace are private; the filters and actions below are the supported integration points.

## Compatibility

- A filter's name and arguments don't change within a major version. New arguments are only ever added at the end.
- A renamed filter keeps working under its old name until at least the next major version. The old name's result is passed on to the new one, and WordPress raises a deprecation notice when `WP_DEBUG` is on. Each filter's entry below lists any deprecated name.
- Any change that could break an integration is listed in `CHANGELOG.md`.

## Filters

### `quick2fa_verify_intro`

Filter the intro text shown above the code input on the verification page. Applied at render time on every verification page load.

**Parameters:**
- `string $intro` — Default intro text

**Returns:** `string` — Plain text or a tight subset of inline HTML (see below).

```php
add_filter( 'quick2fa_verify_intro', function( $intro ) {
    return 'For security reasons, please enter the verification code we just sent you.';
} );
```

#### Allowed HTML

The returned string is rendered through `wp_kses()` with a tight whitelist. Anything outside the whitelist is silently stripped. The allowed tags are:

| Tag | Allowed attributes |
|-----|---------------------|
| `<a>` | `href`, `rel`, `target`, `title` |
| `<b>` | — |
| `<br>` | — |
| `<em>` | — |
| `<i>` | — |
| `<span>` | `class` |
| `<strong>` | — |

Plain text is always safe. Use the formatting tags to highlight key parts of the intro:

```php
add_filter( 'quick2fa_verify_intro', function( $intro ) {
    return '<strong>Heads up:</strong> we\'ve sent a one-time code to your email. ' .
           'See our <a href="https://example.com/security" rel="noopener" target="_blank">security policy</a> ' .
           'if you weren\'t expecting this.';
} );
```

The intro text is not user-configurable from the settings page — this filter is the only way to customise it. Add the filter from your theme's `functions.php`, a custom mu-plugin, or a site-specific plugin.

---

### `quick2fa_password_intro`

Filter the intro text shown above the password update form on the password reminder page. Applied at render time on every password reminder page load.

**Parameters:**
- `string $intro` — Default intro text

**Returns:** `string` — Plain text or the same tight subset of inline HTML as `quick2fa_verify_intro` (see allowed-tag table above).

```php
add_filter( 'quick2fa_password_intro', function( $intro ) {
    return 'Your password is overdue for a refresh — <strong>let\'s update it now</strong>.';
} );
```

Like `quick2fa_verify_intro`, this is filter-only — there's no settings UI for the intro text — and the returned string is rendered through `wp_kses()` with the same whitelist.

---

### `quick2fa_password_parameters`

Filter the parameters used by the built-in strong-password generator on the password reminder page.

**Parameters:**
- `array $defaults` — An array with keys:
  - `length` (`int`) — Password length. Default: a random length from 12 to 20
  - `special_chars` (`bool`) — Include common special characters (`!@#$%^&*()`). Default `true`
  - `extra_special_chars` (`bool`) — Include rarer special characters (`` -_ []{}<>~`+=,.;:/?| ``), which some systems reject. Default `false`

**Returns:** `array` — Same structure.

```php
add_filter( 'quick2fa_password_parameters', function( $params ) {
    return array(
        'length'              => random_int( 16, 24 ),
        'special_chars'       => true,
        'extra_special_chars' => false,
    );
} );
```

Each value is checked before use. The result goes to `wp_generate_password()` as its three arguments, so other keys are ignored:

- `length` must be an `int`, and is clamped to 8–64
- `special_chars` and `extra_special_chars` must be `bool`
- A value of the wrong type falls back to its default, and a return that isn't a non-empty array falls back to all three defaults

---

### `quick2fa_updater_enabled`

Disable the in-plugin GitHub auto-updater. Useful for staging environments, local development, or when you want to pin a site to a specific version.

**Parameters:**
- `bool $enabled` — `true` by default

**Returns:** `bool` — Return `false` to disable update checks.

```php
// Disable updates on staging
add_filter( 'quick2fa_updater_enabled', function( $enabled ) {
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE ) {
        return false;
    }
    return $enabled;
} );

// Pin a production site to its current version
add_filter( 'quick2fa_updater_enabled', '__return_false' );
```

The result is read as a boolean the way WordPress reads option values, so `'no'`, `'off'` and `'false'` also disable updates.

**Deprecated name:** before 1.5.0 this filter was `quick_2fa_updater_enabled`. The old name still works: its result is passed on to `quick2fa_updater_enabled`, and WordPress raises a deprecation notice when `WP_DEBUG` is on. Rename your `add_filter()` call.

---

### `quick2fa_record_last_login`

Control whether Quick 2FA records a last-login timestamp for a given user. Applied on every successful login, before anything is written.

**Parameters:**
- `bool $record` — `true` by default
- `WP_User $user` — the user who just logged in

**Returns:** `bool` — Return `false` to skip recording for this user. Read as a boolean the same way as `quick2fa_updater_enabled`.

```php
// Don't record logins for a service account
add_filter( 'quick2fa_record_last_login', function( $record, $user ) {
    if ( 'api-service' === $user->user_login ) {
        return false;
    }
    return $record;
}, 10, 2 );

// Opt the whole site out of last-login recording
add_filter( 'quick2fa_record_last_login', '__return_false' );
```

See [Reading last-login data](extending.md#reading-last-login-data) for the data this writes and how to consume it.

---

## Actions

Quick 2FA does not currently define any plugin-specific action hooks. The only `do_action()` calls in the codebase are to WordPress core hooks (`login_head`, `login_footer`, `login_enqueue_scripts`) and to the Query Monitor hook (`qm/cease`) for security on auth pages.

If you need an action hook to integrate with — e.g. a `quick2fa_after_verification` hook — open a feature request on GitHub describing the use case.

## What about constants and namespaced functions?

Constants and namespaced functions in `Quick_2FA\*` are **private**. Don't reference them from your own code — they may be renamed, removed, or restructured at any release without warning.

If you find yourself wanting to call into the plugin, that's a sign we need a public hook for your use case. Open an issue with details and we'll consider exposing one.

## Stored data you can read

These stored values are stable and safe to read directly with `get_user_meta()` and `get_option()`. Use the literal strings shown; the PHP constants that hold them are private.

| Key | Where | Value |
|-----|-------|-------|
| `_quick2fa_last_login` | User meta | Unix timestamp of the user's most recent login. See [reading last-login data](extending.md#reading-last-login-data) before relying on it |
| `quick2fa_last_login_since` | Option | Unix timestamp of the first login this site recorded |
| `_quick2fa_locked_until` | User meta | Unix timestamp the account's lock ends. The account is locked while it is in the future. A manual lock is set about 200 years ahead |
| `quick2fa_mode` | Option | `all`, `roles` or `disabled`; see [configuration](../configuration.md#2fa-mode) |

Treat them as read-only from code. Writing to them skips the plugin's own checks and event logging, so lock and unlock accounts with the [WP-CLI commands](../wp-cli.md) instead. The database edits in [troubleshooting](../troubleshooting.md#4-direct-database-edit) are for recovery only.
