# Extending Quick 2FA

Practical recipes for the most common customisations. All examples go in your theme's `functions.php`, a custom mu-plugin, or a site-specific plugin — anywhere code runs early in the WordPress request lifecycle.

For the full list of available filters, see [hooks and filters](hooks-and-filters.md).

## Customise the verification page intro text

Replace the default "For your security, we need to verify your identity before you can access the admin area." message:

```php
add_filter( 'quick2fa_verify_intro', function( $intro ) {
    return sprintf(
        'Hi! For your security, please check your email for a code from %s and enter it below.',
        get_bloginfo( 'name' )
    );
} );
```

## Customise the password reminder intro text

```php
add_filter( 'quick2fa_password_intro', function( $intro ) {
    return 'It\'s time for a password refresh. We recommend using the strong password we\'ve generated below.';
} );
```

## Generate longer passwords for the reminder flow

```php
add_filter( 'quick2fa_password_parameters', function( $params ) {
    $params['length'] = random_int( 20, 28 );
    return $params;
} );
```

Or for compliance scenarios where you need a fixed length:

```php
add_filter( 'quick2fa_password_parameters', function( $params ) {
    return array(
        'length'              => 16,
        'special_chars'       => true,
        'extra_special_chars' => true,
    );
} );
```

## Disable auto-updates on staging environments

Quick 2FA's GitHub updater can be turned off per-site:

```php
add_filter( 'quick2fa_updater_enabled', function( $enabled ) {
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
        $env = WP_ENVIRONMENT_TYPE;
        if ( 'staging' === $env || 'local' === $env || 'development' === $env ) {
            return false;
        }
    }

    return $enabled;
} );
```

## Pin Quick 2FA to a specific version on a critical site

Same filter — disable updates entirely on the production site, then update manually after testing on staging:

```php
add_filter( 'quick2fa_updater_enabled', '__return_false' );
```

## Exempt a specific user role from 2FA

Set **Settings → Quick 2FA → 2FA Mode** to **Enabled for specific roles**, and leave the role out of **Protected Roles**. There's no filter for this; the setting is the right tool.

## Email a daily report of locked accounts

There's no action hook for lock events yet, but a daily cron job can read the [lock timestamp](hooks-and-filters.md#stored-data-you-can-read) and report the accounts that are locked right now:

```php
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'my_quick2fa_lockout_report' ) ) {
        wp_schedule_event( time(), 'daily', 'my_quick2fa_lockout_report' );
    }
} );

add_action( 'my_quick2fa_lockout_report', function() {
    // A lock whose end time has passed is over, even while the meta is still there.
    $users = get_users( array(
        'meta_key'     => '_quick2fa_locked_until',
        'meta_value'   => time(),
        'meta_compare' => '>',
        'meta_type'    => 'NUMERIC',
        'fields'       => array( 'ID', 'user_email', 'user_login' ),
    ) );

    if ( ! empty( $users ) ) {
        $body = "Accounts currently locked by Quick 2FA:\n\n";
        foreach ( $users as $user ) {
            $body .= sprintf( "- %s (%s)\n", $user->user_login, $user->user_email );
        }

        wp_mail( get_option( 'admin_email' ), 'Quick 2FA daily lockout report', $body );
    }
} );
```

If you need a real-time hook (`quick2fa_account_locked`, etc.), open a feature request on GitHub.

## Reading last-login data

Since 1.3.0 Quick 2FA records a last-login timestamp for **every** user, on every successful
login, independent of the 2FA flow. WordPress core keeps no such record, so this fills a real
gap — but read the caveats before you rely on it.

Two pieces of data, both safe to read directly:

| Key | Where | Value |
|---|---|---|
| `_quick2fa_last_login` | User meta | Unix timestamp of that user's most recent login |
| `quick2fa_last_login_since` | Site option | Unix timestamp of the first login this site ever recorded |

```php
$last_login = (int) get_user_meta( $user_id, '_quick2fa_last_login', true );

if ( $last_login > 0 ) {
    printf( 'Last login: %s', wp_date( 'Y-m-d H:i', $last_login ) );
}
```

### Absence does not mean "never logged in"

This is the important part, and getting it wrong is how you delete an active account.

**1. Check the epoch first.** A user with no `_quick2fa_last_login` value may simply have last
logged in before this site started recording. `quick2fa_last_login_since` tells you when
recording began, so you can tell the two cases apart:

```php
$recording_since = (int) get_option( 'quick2fa_last_login_since', 0 );
$last_login      = (int) get_user_meta( $user_id, '_quick2fa_last_login', true );
$registered      = strtotime( get_userdata( $user_id )->user_registered );

if ( $last_login > 0 ) {
    // Known: this user logged in at $last_login.
} elseif ( $recording_since > 0 && $registered > $recording_since ) {
    // Known: registered after recording began and never logged in since.
} else {
    // Unknown — predates recording. Do not treat as inactive.
}
```

If `quick2fa_last_login_since` is absent entirely, the site has recorded nothing and **no**
conclusion can be drawn about any user.

**2. Some authentication paths never reach us.** The timestamp is written on the core
`wp_login` action, which covers standard authentication including front-end logins. It does
**not** fire for Application Password / REST authentication, nor for SSO and social-login
plugins that call `wp_set_auth_cookie()` directly without going through `wp_signon()`. A user
who only ever authenticates by those routes will accumulate no timestamp despite being active.

**3. A site may have opted out.** The `quick2fa_record_last_login` filter can disable recording
per-user or site-wide (see [hooks and filters](hooks-and-filters.md#quick2fa_record_last_login)).

Treat a timestamp as positive evidence of activity. Never treat its absence as evidence of
inactivity without the epoch check above, and prefer a conservative default for any destructive
operation.

### Retention

Last-login timestamps survive plugin deletion — `uninstall.php` deliberately keeps them,
because they cannot be backfilled and delete-and-reinstall is a routine troubleshooting step.
Sites that need them gone can clear the key directly:

```php
delete_metadata( 'user', 0, '_quick2fa_last_login', '', true );
delete_option( 'quick2fa_last_login_since' );
```

## Building a custom integration

If you find yourself reaching for namespaced functions or constants from `Quick_2FA\*`, **stop** — those are internal and will break across releases. Instead:

1. Check whether an existing filter solves your problem (see [hooks and filters](hooks-and-filters.md))
2. If not, open an issue describing the use case
3. We'll either extend an existing filter or add a new public hook in the next release
