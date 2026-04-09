<?php
/**
 * Quick 2FA — Uninstaller
 *
 * Runs when the user deletes the plugin via the WordPress admin
 * (Plugins → Delete). Does NOT run on plugin deactivation, which is
 * intentionally non-destructive (a developer may temporarily deactivate
 * the plugin while testing something else).
 *
 * Current policy:
 *   - WIPE: trusted device fingerprints (per-user, low value to retain)
 *   - KEEP: plugin settings, lock status, security event log,
 *           password-reminder timestamps
 *
 * The kept data is preserved so that uninstalling and reinstalling does
 * not silently unlock previously-locked accounts or wipe a site's tuned
 * configuration. A future release may revisit this and offer a "delete
 * all data" option — see dev-notes/00-project-tracker.md for the
 * outstanding review item.
 *
 * @package Quick_2FA
 * @since   1.0.0
 */

// Bail out if not invoked by WordPress's uninstall handler.
defined( 'WP_UNINSTALL_PLUGIN' ) || die();

// Load plugin constants so we can reference META_TRUSTED_DEVICES by name
// rather than duplicating the meta key string here.
require_once __DIR__ . '/constants.php';

// Bulk-delete the trusted-devices meta key from every user.
// The fourth argument is unused when $delete_all (5th arg) is true.
delete_metadata( 'user', 0, Quick_2FA\META_TRUSTED_DEVICES, '', true );
