# Extending Quick 2FA

Practical recipes for the most common customisations. All examples go in your theme's `functions.php`, a custom mu-plugin, or a site-specific plugin — anywhere code runs early in the WordPress request lifecycle.

For the full list of available filters, see [hooks and filters](hooks-and-filters.md).

## Customise the verification page intro text

Replace the default "For your security, we need to verify your identity" message:

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
add_filter( 'headwall_github_updater_enabled', function( $enabled, $plugin_slug ) {
    if ( 'quick-2fa' !== $plugin_slug ) {
        return $enabled;
    }

    // Disable on staging and local environments.
    if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
        $env = WP_ENVIRONMENT_TYPE;
        if ( 'staging' === $env || 'local' === $env || 'development' === $env ) {
            return false;
        }
    }

    return $enabled;
}, 10, 2 );
```

## Pin Quick 2FA to a specific version on a critical site

Same filter — disable updates entirely on the production site, then update manually after testing on staging:

```php
add_filter( 'headwall_github_updater_enabled', function( $enabled, $plugin_slug ) {
    if ( 'quick-2fa' === $plugin_slug ) {
        return false;
    }
    return $enabled;
}, 10, 2 );
```

## Exempt a specific user role from 2FA

Use the built-in **Settings → Quick 2FA → Mode → Specific Roles** option and exclude the role from the protected list. There's no filter for this — the setting is the right tool.

## Email all admins on lock-out events (custom integration)

There's no built-in action hook for this yet, but you can listen to the WordPress core hooks Quick 2FA uses indirectly. The cleanest path: tail the user event log via a daily cron job.

```php
add_action( 'init', function() {
    if ( ! wp_next_scheduled( 'my_quick2fa_lockout_audit' ) ) {
        wp_schedule_event( time(), 'daily', 'my_quick2fa_lockout_audit' );
    }
} );

add_action( 'my_quick2fa_lockout_audit', function() {
    $users = get_users( array(
        'meta_key'     => '_quick2fa_locked_until',
        'meta_compare' => 'EXISTS',
        'fields'       => array( 'ID', 'user_email', 'user_login' ),
    ) );

    if ( empty( $users ) ) {
        return;
    }

    $body = "Currently locked Quick 2FA users:\n\n";
    foreach ( $users as $user ) {
        $body .= sprintf( "- %s (%s)\n", $user->user_login, $user->user_email );
    }

    wp_mail( get_option( 'admin_email' ), 'Quick 2FA daily lockout report', $body );
} );
```

If you need a real-time hook (`quick2fa_account_locked`, etc.), open a feature request on GitHub.

## Building a custom integration

If you find yourself reaching for namespaced functions or constants from `Quick_2FA\*`, **stop** — those are internal and will break across releases. Instead:

1. Check whether an existing filter solves your problem (see [hooks and filters](hooks-and-filters.md))
2. If not, open an issue describing the use case
3. We'll either extend an existing filter or add a new public hook in the next release
