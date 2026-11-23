# AI agents

Quick 2FA can answer an AI agent's questions about account security on your site: when a user last changed their password, who hasn't logged in for months, whether an account is locked, and what has happened on an account recently. It does this through the WordPress Abilities API, which needs **WordPress 6.9 or later**.

It is **off by default**, and **read-only**. An agent can never lock or unlock an account, revoke a device, change a password or change a setting through Quick 2FA.

## Turning it on

**Settings → Quick 2FA → AI Agents → Abilities API**: tick *Let AI agents query account security* and save. On WordPress older than 6.9 the setting can be saved but does nothing, and the page says so.

The agent connects as a WordPress user, usually with an [Application Password](https://make.wordpress.org/core/2020/11/05/application-passwords-integration-guide/). Only users who can manage options (administrators, on a standard site) can use the abilities. A developer can change that capability with the [`quick2fa_abilities_capability`](developers/hooks-and-filters.md#quick2fa_abilities_capability) filter.

## What an agent can ask

| Question | Ability |
|----------|---------|
| When did Sam last change their password? Has Sam logged in recently? Is Sam's account locked? | `quick-2fa/get-user-security-status` |
| Which administrators haven't changed their password this year? Which staff haven't logged in for 90 days? Which accounts are locked? | `quick-2fa/list-user-security` |
| Is 2FA on for shop managers? How long does a lockout last? | `quick-2fa/get-configuration` |
| Has anyone been trying to get into Sam's account? | `quick-2fa/get-security-events` |

Without roles, the list covers staff (users who can edit posts), not customers or subscribers, and it covers at most 1,000 users per query.

The developer reference, with every field, is [Abilities API](developers/abilities.md).

## What the answers can't tell you

- **Password changes before 1.8.0.** Before version 1.8.0, Quick 2FA recorded a password change only when it was made on its own reminder page. A change made before then on the profile page, by a reset or with WP-CLI is unknown: an agent sees the last reminder-page change instead, or "no change recorded" if there was none, and password age then counts from the user's registration. Every change from 1.8.0 on is recorded, however it is made.
- **Logins before Quick 2FA was installed.** Logins are recorded from the first one after installation; the configuration tells an agent when that was.
- **Events older than the last 50.** The security event log keeps the 50 most recent entries per user. See [account locking](account-locking.md#event-logging).

## What is never returned

- IP addresses and browser details (user agents). The event log records both, and an agent would pass them to its model provider, so they are left out. A future version may add non-identifying details instead, such as the country
- Email addresses, including the address a code was sent to
- Verification codes, trusted-device tokens and their hashes
- Email settings

The user's ID, login name, display name and roles *are* returned, so an agent can tell users apart.

## Security

Being read-only limits what Quick 2FA adds, not what an Application Password can do. An administrator's Application Password can already change any user's password through WordPress's own REST API. Treat it like the administrator's password: give the agent its own user where you can, and revoke the Application Password (**Users → Profile → Application Passwords**) if it may have leaked.

When an administrator calls an ability from a logged-in browser rather than with an Application Password, that login session must already have passed 2FA.

## See also

- [Configuration](configuration.md)
- [How it works](how-it-works.md)
- [Abilities API](developers/abilities.md) *(for developers)*
