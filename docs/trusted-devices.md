# Trusted devices

## The security model

When trusted devices are **enabled** (default), the request flow is:

1. User logs in
2. Quick 2FA generates a fingerprint for the current device (`SHA-256(IP + User-Agent)`)
3. If that fingerprint is in the user's trusted-devices list **and** hasn't expired → skip verification
4. Otherwise → require verification

After a successful verification, the user can tick "trust this device" to add the current fingerprint to their list, with an expiry of (by default) 30 days.

When trusted devices are **disabled**, every login requires verification, full stop. Use this on high-risk sites where convenience is not a priority.

## Why device-based, not just time-based?

Without trusted devices, the alternative is "verify once every N days regardless of where the login is coming from". That's a meaningful security hole: anyone with the password can log in from a new device for `N - 1` days without ever seeing a verification challenge.

Device-based trust closes that hole. New devices always require verification, even if the user verified yesterday on a different device.

## What's in a fingerprint

A fingerprint is `SHA-256(client_ip + '|' + user_agent)`. We store the hash, not the raw values.

**Caveat:** behind a shared NAT or reverse proxy (Cloudflare, AWS ALB, an office firewall), all users present the same external IP. If two users on the same network share a similar User-Agent string, their fingerprints can collide. See [troubleshooting → my office is behind a proxy](troubleshooting.md#my-office-is-behind-a-reverse-proxy-or-shared-nat) for the practical implications.

## Expiry

Each trusted-device entry stores its own expiry timestamp, set when the device was first trusted. When a user logs in:

- If the fingerprint matches **and** the entry hasn't expired → trusted, skip verification
- If the fingerprint matches **but** the entry has expired → entry is silently removed and verification is required

There's no separate "verification period" check overlaid on top of trusted devices — the per-device expiry is the only timer.

## Revoking devices

Users can manage their own trusted devices on their **Profile** page. Each device is shown with its first-trusted date and expiry. The currently-active device is highlighted.

Site administrators can revoke devices for any user from the same profile page (when editing another user). They can also clear *all* trusted devices for a user via WP-CLI:

```bash
wp quick-2fa clear-devices <user>
```

A device list is also wiped automatically when:

- The user changes their password (via the built-in reminder flow)
- The plugin is uninstalled (see `uninstall.php`)

Lock-outs **do not** wipe trusted devices automatically — when an admin unlocks the account, the original trusted devices come back. If you want a clean slate after a lock-out, run `clear-devices` after `unlock`.

## Disabling the feature entirely

The disable-trusted-devices toggle is **CLI-only** for now — there's no settings UI checkbox. To force every login to require verification:

```bash
wp option update quick2fa_disable_trusted_devices 1
```

Re-enable with `wp option update quick2fa_disable_trusted_devices 0`.

Existing trusted-device entries in user meta will be ignored but not deleted. Re-enabling the feature restores the previous trust list (subject to per-device expiry). See [WP-CLI → configuration via CLI](wp-cli.md#configuration-via-cli) for the full CLI-only settings list.
