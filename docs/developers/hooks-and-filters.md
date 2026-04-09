# Hooks and filters

This is the **public extension surface** of Quick 2FA. Anything not listed here should be considered internal — it may change without notice between releases. Functions in the `Quick_2FA` namespace are private; the filters and actions below are the supported integration points.

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
  - `length` (`int`) — Password length
  - `special_chars` (`bool`) — Include common special characters (`!@#$%^&*`)
  - `extra_special_chars` (`bool`) — Include rare special characters (less compatible with some systems)

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

The generator passes the result to `wp_generate_password()`, so any options that function supports are honoured.

---

### `headwall_github_updater_enabled`

Disable the in-plugin GitHub auto-updater for this plugin. Useful for staging environments, local development, or when you want to pin a site to a specific version.

> This filter is shared across multiple Headwall plugins that bundle the same updater class. The plugin slug and repo are passed as additional arguments so you can target a specific plugin.

**Parameters:**
- `bool $enabled` — `true` by default
- `string $plugin_slug` — The plugin directory name (e.g. `quick-2fa`)
- `string $github_repo` — The GitHub `owner/repo` string (e.g. `headwalluk/quick-2fa`)

**Returns:** `bool` — Return `false` to disable update checks for the plugin.

```php
// Disable updates for Quick 2FA only
add_filter( 'headwall_github_updater_enabled', function( $enabled, $plugin_slug, $github_repo ) {
    if ( 'quick-2fa' === $plugin_slug ) {
        return false;
    }
    return $enabled;
}, 10, 3 );

// Disable updates on staging
add_filter( 'headwall_github_updater_enabled', function( $enabled ) {
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE ) {
        return false;
    }
    return $enabled;
} );
```

## Actions

Quick 2FA does not currently define any plugin-specific action hooks. The only `do_action()` calls in the codebase are to WordPress core hooks (`login_head`, `login_footer`, `login_enqueue_scripts`) and to the Query Monitor hook (`qm/cease`) for security on auth pages.

If you need an action hook to integrate with — e.g. a `quick2fa_after_verification` hook — open a feature request on GitHub describing the use case.

## What about constants and namespaced functions?

Constants and namespaced functions in `Quick_2FA\*` are **private**. Don't reference them from your own code — they may be renamed, removed, or restructured at any release without warning.

If you find yourself wanting to call into the plugin, that's a sign we need a public hook for your use case. Open an issue with details and we'll consider exposing one.
