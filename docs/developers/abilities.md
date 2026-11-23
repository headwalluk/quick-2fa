# Abilities API

Quick 2FA registers four read-only abilities with the WordPress Abilities API (WordPress 6.9+), in the `quick-2fa` category, when the *Abilities API* setting is on. Site operators: see [AI agents](../ai-agents.md).

Ability names, input fields and output fields are public contracts. They change only with a major version, and a field is added rather than renamed.

## Calling them

Every ability is annotated `readonly`, so over REST it is called with `GET` and its input goes in the `input` query parameter:

```
GET /wp-json/wp-abilities/v1/abilities/quick-2fa/get-user-security-status/run?input[user]=sam
```

From PHP:

```php
$ability = wp_get_ability( 'quick-2fa/get-user-security-status' );
$result  = $ability instanceof WP_Ability ? $ability->execute( array( 'user' => 'sam' ) ) : null;
```

Dates are ISO 8601 in UTC (`2026-11-23T09:56:36+00:00`). `null` means none is recorded.

## Permission

The caller needs `manage_options`, or the capability returned by [`quick2fa_abilities_capability`](hooks-and-filters.md#quick2fa_abilities_capability). A request authenticated by a browser login cookie must also come from a login session that has passed 2FA, when 2FA applies to the caller. Application Password and WP-CLI requests have no browser session and skip that check, as they skip the 2FA redirect.

Refusals are `WP_Error`s: `quick2fa_ability_forbidden` (capability) or `quick2fa_ability_unverified` (2FA), which the Abilities API reports as `ability_invalid_permissions`.

## `quick-2fa/get-user-security-status`

**Input:** `user` (required): an ID, login name or email address. An unknown user is a `quick2fa_user_not_found` error. On multisite, so is a user who isn't a member of the current site, unless the caller can `manage_network_users`.

**Output:** one user record.

| Field | Type | Meaning |
|-------|------|---------|
| `user_id` | integer | |
| `user_login` | string | |
| `display_name` | string | |
| `roles` | string[] | Role slugs |
| `registered` | date | |
| `is_2fa_required` | boolean | Whether the mode and protected roles require this user to verify |
| `password_last_changed` | date or null | The last recorded change. `null` if none is recorded, including a value Quick 2FA filled in from the registration date |
| `password_age_days` | integer | Days since the last recorded change, or since registration |
| `is_password_reminder_due` | boolean | Whether the next admin page load would show the password reminder |
| `last_login` | date or null | |
| `last_2fa_verification` | date or null | The last successful code entry |
| `is_locked` | boolean | |
| `is_lock_permanent` | boolean | Locked until an administrator unlocks it |
| `locked_until` | date or null | `null` when unlocked or permanently locked |
| `trusted_device_count` | integer | Unexpired trusted devices |

## `quick-2fa/list-user-security`

**Input:** every field optional.

| Field | Type | Meaning |
|-------|------|---------|
| `roles` | string[] | Users with any of these roles. Omitted: users who can `edit_posts` |
| `password_older_than_days` | integer | Password age, as `password_age_days`, above this |
| `no_login_for_days` | integer | No recorded login in this many days, or none at all |
| `locked_only` | boolean | Locked accounts only |
| `limit` | integer | 1–200, default 50 |
| `offset` | integer | Default 0 |

**Output:** `total` (matches before paging), `offset`, and `users`, a list of user records as above, ordered by user ID. More than 1,000 users matching the roles is a `quick2fa_too_many_users` error; narrow the roles.

## `quick-2fa/get-configuration`

**Input:** none.

**Output:** `plugin_version`, `mode` (`all`, `roles` or `disabled`), `protected_roles`, `verification_period_days`, `code_expiry_minutes`, `trusted_devices_enabled`, `trusted_device_expiry_days`, `lockout_duration_minutes`, `password_reminders_enabled`, `password_reminder_period_days`, `password_reminder_cooldown_days`, and `last_login_recorded_since` (date or null). Email settings are not included.

## `quick-2fa/get-security-events`

**Input:** `user` (required, as above), `limit` (1–50, default 20), `event_types` (optional list).

**Output:** `user_id`, and `events`, newest first. Each event has:

| Field | Type | Meaning |
|-------|------|---------|
| `event_type` | string | One of the types in [account locking](../account-locking.md#event-logging) |
| `time` | date or null | |
| `details` | object | Only the fields the event recorded, from: `source`, `reason` (at most 200 characters), `attempts`, `success`, `device_count`, `locked_until` (`null` for a permanent lock), `actor_user_id` (the administrator who acted) |
| `ip_metadata` | null | Reserved for non-identifying IP details, such as a country; always `null` for now |

The stored IP address, user agent, email address and device key are never returned.
