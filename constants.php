<?php
/**
 * Plugin Constants
 *
 * @package Quick_2FA
 * @since 1.0.0
 */

namespace Quick_2FA;

// Exit if accessed directly.
defined( 'ABSPATH' ) || die();

/**
 * User meta keys.
 *
 * @since 1.0.0
 */
const META_CODE_HASH              = '_quick2fa_code_hash';
const META_CODE_TIMESTAMP         = '_quick2fa_code_timestamp';
const META_CODE_ATTEMPTS          = '_quick2fa_code_attempts';
const META_LAST_VERIFIED          = '_quick2fa_last_verified';
const META_LAST_PASSWORD_REMINDER = '_quick2fa_last_password_reminder';
const META_LOCKED_UNTIL           = '_quick2fa_locked_until';
const META_LOGS                   = '_quick2fa_logs';
const META_TRUSTED_DEVICES        = '_quick2fa_trusted_devices';

/**
 * Options.
 *
 * @since 1.0.0
 */
const OPTION_MODE                       = 'quick2fa_mode';
const OPTION_PROTECTED_ROLES            = 'quick2fa_protected_roles';
const OPTION_VERIFICATION_PERIOD        = 'quick2fa_verification_period';
const OPTION_CODE_LENGTH                = 'quick2fa_code_length';
const OPTION_CODE_EXPIRY                = 'quick2fa_code_expiry';
const OPTION_PASSWORD_REMINDERS_ENABLED = 'quick2fa_password_reminders_enabled';
const OPTION_PASSWORD_REMINDER_PERIOD   = 'quick2fa_password_reminder_period';
const OPTION_PASSWORD_REMINDER_COOLDOWN = 'quick2fa_password_reminder_cooldown';
const OPTION_EMAIL_FROM_NAME            = 'quick2fa_email_from_name';
const OPTION_EMAIL_FROM_ADDRESS         = 'quick2fa_email_from_address';
const OPTION_EMAIL_SUBJECT              = 'quick2fa_email_subject';
const OPTION_DISABLE_TRUSTED_DEVICES    = 'quick2fa_disable_trusted_devices';
const OPTION_TRUSTED_DEVICE_EXPIRY      = 'quick2fa_trusted_device_expiry';
const OPTION_LOCKOUT_DURATION           = 'quick2fa_lockout_duration';
const OPTION_VERSION                    = 'quick2fa_version';

/**
 * 2FA modes.
 *
 * @since 1.0.0
 */
const MODE_ALL      = 'all';
const MODE_ROLES    = 'roles';
const MODE_DISABLED = 'disabled';

/**
 * Rate limiting.
 *
 * @since 1.0.0
 */
const RATE_LIMIT_CODE_GENERATION_MAX    = 3;
const RATE_LIMIT_CODE_GENERATION_WINDOW = 900; // 15 minutes in seconds.
const RATE_LIMIT_VERIFICATION_MAX       = 5;
const RATE_LIMIT_ACCOUNT_LOCK_THRESHOLD = 10;
const RATE_LIMIT_ACCOUNT_LOCK_WINDOW    = 3600; // 1 hour in seconds.
const RATE_LIMIT_ACCOUNT_LOCK_DURATION  = 3600; // 1 hour in seconds.

/**
 * Trusted-device cookie.
 *
 * Device trust is carried by a persistent secure cookie token (since 1.2.0),
 * not a hash of network attributes (IP/User-Agent). The raw token exists only
 * in the browser cookie; its SHA-256 hash is what we persist server-side, keyed
 * into META_TRUSTED_DEVICES. COOKIEHASH is appended to the cookie name at use
 * time so it is scoped to the installation, mirroring core's auth cookies.
 *
 * @since 1.2.0
 */
const COOKIE_DEVICE_TOKEN = 'quick2fa_device';
const DEVICE_TOKEN_BYTES  = 32;

/**
 * Transient key prefixes.
 *
 * @since 1.0.0
 */
const TRANSIENT_RETURN_URL = 'q2fa_return_';
const TRANSIENT_RATE_LIMIT = 'q2fa_rate_limit_';

/**
 * Query parameter for 2FA pages.
 *
 * @since 1.0.0
 */
const QUERY_PARAM = 'q2fa';

/**
 * Actions.
 *
 * @since 1.0.0
 */
const ACTION_VERIFY   = 'verify';
const ACTION_PASSWORD = 'password';

/**
 * Log event types.
 *
 * @since 1.0.0
 */
const LOG_CODE_GENERATED       = 'code_generated';
const LOG_CODE_SENT            = 'code_sent';
const LOG_VERIFICATION_SUCCESS = 'verification_success';
const LOG_VERIFICATION_FAILED  = 'verification_failed';
const LOG_ACCOUNT_LOCKED       = 'account_locked';
const LOG_ACCOUNT_UNLOCKED     = 'account_unlocked';
const LOG_PASSWORD_CHANGED     = 'password_changed';

/**
 * GitHub updater.
 *
 * @since 1.0.0
 */
const UPDATER_GITHUB_REPO = 'headwalluk/quick-2fa';
const UPDATER_CACHE_TTL   = 12 * HOUR_IN_SECONDS;
const UPDATER_CACHE_KEY   = 'quick_2fa_github_release';

/**
 * Defaults.
 *
 * @since 1.0.0
 */
const DEFAULT_MODE                       = MODE_ALL;
const DEFAULT_VERIFICATION_PERIOD        = 3;
const DEFAULT_CODE_LENGTH                = 6;
const DEFAULT_CODE_EXPIRY                = 15;
const DEFAULT_PASSWORD_REMINDERS_ENABLED = true;
const DEFAULT_PASSWORD_REMINDER_PERIOD   = 60;
const DEFAULT_PASSWORD_REMINDER_COOLDOWN = 1;
const DEFAULT_PASSWORD_LENGTH_MIN        = 12;
const DEFAULT_PASSWORD_LENGTH_MAX        = 20;
const DEFAULT_PASSWORD_SPECIAL_CHARS     = true;
const DEFAULT_PASSWORD_EXTRA_SPECIAL     = false;
const DEFAULT_DISABLE_TRUSTED_DEVICES    = false;
const DEFAULT_TRUSTED_DEVICE_EXPIRY      = 30;
const DEFAULT_LOCKOUT_DURATION           = 60;
