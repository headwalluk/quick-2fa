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
const META_CODE_HASH      = '_quick2fa_code_hash';
const META_CODE_TIMESTAMP = '_quick2fa_code_timestamp';
const META_CODE_ATTEMPTS  = '_quick2fa_code_attempts';
const META_LAST_LOGIN     = '_quick2fa_last_login';
// Deliberately NOT prefixed — this key predates the plugin's own namespace
// and is already populated on live sites. Changing the string would orphan
// every stored value, so it stays as-is.
const META_PASSWORD_LAST_CHANGED  = '_password_last_changed';
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
const OPTION_LAST_LOGIN_SINCE           = 'quick2fa_last_login_since';
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
 * Settings bounds.
 *
 * Each registered setting is clamped to its range on save by
 * Settings::sanitize_range(); a value outside the range falls back to the
 * matching DEFAULT_*. Kept here so the validator and the help text on the
 * settings page can cite one source rather than repeating the numbers.
 *
 * @since 1.3.0
 */
const VERIFICATION_PERIOD_MIN        = 1;
const VERIFICATION_PERIOD_MAX        = 365;
const CODE_EXPIRY_MIN                = 5;
const CODE_EXPIRY_MAX                = 60;
const PASSWORD_REMINDER_PERIOD_MIN   = 1;
const PASSWORD_REMINDER_PERIOD_MAX   = 365;
const PASSWORD_REMINDER_COOLDOWN_MIN = 1;
const PASSWORD_REMINDER_COOLDOWN_MAX = 90;
const TRUSTED_DEVICE_EXPIRY_MIN      = 1;
const TRUSTED_DEVICE_EXPIRY_MAX      = 365;
const LOCKOUT_DURATION_MIN           = 1;
const LOCKOUT_DURATION_MAX           = 1440; // 24 hours in minutes.

/**
 * Bundled Select2 asset version.
 *
 * @since 1.3.0
 */
const ASSET_SELECT2_VERSION = '4.0.13';

/**
 * Verification code length bounds.
 *
 * Enforced by the settings validator on save, and again in
 * Verification_Code_Handler::generate() — a value written straight to the
 * database would otherwise silently shorten every code.
 *
 * @since 1.3.0
 */
const CODE_LENGTH_MIN = 4;
const CODE_LENGTH_MAX = 10;

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
 * Permanent locks are stored as a lock-until timestamp far enough in the
 * future that it will never be reached. Anything beyond this offset from now
 * is treated as permanent, and reported as "contact your administrator"
 * rather than as a countdown.
 *
 * @since 1.3.0
 */
const PERMANENT_LOCK_THRESHOLD = 100 * YEAR_IN_SECONDS;

/**
 * Duration applied by a manual, indefinite lock.
 *
 * Comfortably past PERMANENT_LOCK_THRESHOLD, but small enough that
 * time() + this stays an integer. Using PHP_INT_MAX here overflowed to a
 * float, so the lock was stored as '9.223372038644E+18' and every reader had
 * to cope with scientific notation.
 *
 * @since 1.3.0
 */
const PERMANENT_LOCK_DURATION = 200 * YEAR_IN_SECONDS;

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
 * How long a stored return URL stays valid.
 *
 * Long enough to read an email and type a code, short enough that a stale
 * destination is not sitting around after an abandoned login.
 *
 * @since 1.3.0
 */
const RETURN_URL_TTL = 5 * MINUTE_IN_SECONDS;

/**
 * Cached count of locked users, for the users-list filter badge.
 *
 * Invalidated by Account_Security_Handler::lock_account() and
 * ::unlock_account(), so the badge is correct after an automatic lockout as
 * well as an admin or CLI one.
 *
 * @since 1.3.0
 */
/**
 * Users-list column, sort and filter identifiers.
 *
 * @since 1.3.0
 */
const COLUMN_LOCK_STATUS = 'quick2fa_status';
const SORT_KEY_LOCKED    = 'quick2fa_locked';
const QUERY_ARG_FILTER   = 'quick2fa_filter';

const TRANSIENT_LOCKED_COUNT = 'quick2fa_locked_user_count';
const LOCKED_COUNT_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

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
const LOG_CODE_GENERATED              = 'code_generated';
const LOG_CODE_SENT                   = 'code_sent';
const LOG_VERIFICATION_SUCCESS        = 'verification_success';
const LOG_VERIFICATION_FAILED         = 'verification_failed';
const LOG_ACCOUNT_LOCKED              = 'account_locked';
const LOG_ACCOUNT_UNLOCKED            = 'account_unlocked';
const LOG_PASSWORD_CHANGED            = 'password_changed';
const LOG_PASSWORD_REMINDER_DISMISSED = 'password_reminder_dismissed';
const LOG_DEVICE_REVOKED              = 'device_revoked';
const LOG_ALL_DEVICES_REVOKED         = 'all_devices_revoked';

/**
 * Maximum security-log entries retained per user.
 *
 * The log lives in a single user_meta row, so it is capped rather than allowed
 * to grow without limit. Documented in readme.txt's privacy section.
 *
 * @since 1.3.0
 */
const LOG_MAX_ENTRIES = 50;

/**
 * GitHub updater.
 *
 * @since 1.0.0
 */
const UPDATER_GITHUB_REPO = 'headwalluk/quick-2fa';
const UPDATER_CACHE_TTL   = 12 * HOUR_IN_SECONDS;
const UPDATER_CACHE_KEY   = 'quick_2fa_github_release';

/**
 * Back-off after a failed release lookup.
 *
 * A failure was previously not cached at all, so every update check retried
 * the API and blocked for up to UPDATER_REQUEST_TIMEOUT seconds while GitHub
 * was unreachable or rate-limiting. Shorter than UPDATER_CACHE_TTL so a real
 * release is not missed for long.
 *
 * @since 1.3.0
 */
const UPDATER_FAILURE_CACHE_KEY = 'quick_2fa_github_failed';
const UPDATER_FAILURE_CACHE_TTL = HOUR_IN_SECONDS;

/**
 * Seconds to wait on the GitHub API before giving up.
 *
 * @since 1.3.0
 */
const UPDATER_REQUEST_TIMEOUT = 10;

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

/**
 * Absolute password length bounds.
 *
 * Distinct from DEFAULT_PASSWORD_LENGTH_MIN/MAX, which are only the range a
 * generated suggestion is drawn from. These bound what is accepted on update
 * and clamp whatever the quick2fa_password_parameters filter returns.
 *
 * @since 1.3.0
 */
const PASSWORD_MIN_LENGTH             = 8;
const PASSWORD_MAX_LENGTH             = 64;
const DEFAULT_DISABLE_TRUSTED_DEVICES = false;
const DEFAULT_TRUSTED_DEVICE_EXPIRY   = 30;
const DEFAULT_LOCKOUT_DURATION        = 60;
