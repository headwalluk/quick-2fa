# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.11.2] - 2026-03-12

### Changed
- **Default 2FA Mode**: Changed default mode from "Enabled for specific roles" to "Enabled for all users" for stronger out-of-the-box security
- **Comment Cleanup**: Tidied section header comments in constants.php
- **Version Sync**: Fixed QUICK_2FA_VERSION constant which was stuck on 0.11.0

## [0.11.1] - 2026-03-02

### Changed
- **Code Cleanup**: Removed ~150 unnecessary comments across all PHP files that restated what the code does, keeping only comments that explain *why* or provide useful context
- **PHPDoc Fix**: Fixed `Plugin::$instance` property — added missing `@since` tag and corrected type from `Plugin` to `?Plugin`

### Removed
- **Developer Documentation**: Removed `docs/` directory (historic requirements and security review files superseded by README and SECURITY.md)

## [0.11.0] - 2026-02-26

### Fixed
- **Trusted Device Expiry**: Trusted devices now honour their full configured trust period (e.g., 30 days)
  - Previously, the time-based verification period (default 3 days) overrode device trust
  - Device trust check now takes priority when trusted devices are enabled
  - Time-based verification period is used as fallback when trusted devices are disabled

### Changed
- **Code Quality**: Achieved 0 phpcs errors and 0 warnings across entire codebase
  - Rewrote `get_user_agent()` and `get_current_admin_url()` to eliminate empty statement errors
  - Removed empty else blocks from `class-plugin.php`, `class-user-management.php`, and `functions.php`
  - Prefixed template foreach variables with `q2fa_` in `settings-page.php`
  - Added justified `phpcs:ignore` directives for WP-CLI signature requirements and admin-context queries

### Removed
- Unused `Plugin::get_settings()` method
- Empty defensive else blocks that served no purpose (previously added in v0.9.0)

## [0.10.0] - 2026-01-18

### Changed
- **Code-First Template Refactoring**: Completed migration to code-first pattern for all view templates
  - Refactored password-page.php to match WordPress core login page structure
  - Refactored settings-page.php to eliminate inline HTML/PHP mixing
  - All templates now use `printf()` and `echo` exclusively (no inline HTML)
  - Moved all JavaScript to separate files (no inline `<script>` tags)

### Added
- **Admin JavaScript**: Created `assets/admin/settings.js` for settings page functionality
  - Select2 initialization moved from inline script
  - Protected roles visibility toggle externalized
  - Uses `wp_localize_script()` for PHP-to-JS data passing

### Improved
- **Query Monitor Security**: Moved `qm/cease` action to `handle_login_actions()` method
  - Now prevents Query Monitor output on ALL q2fa pages (including error pages)
  - Previously only suppressed on successful template loads
  - Prevents information leakage on invalid action URLs
- **Code Quality**: Reduced settings-page.php from 365 lines to 210 lines
  - Eliminated 300+ lines of HTML-first code
  - Better maintainability and readability
  - Consistent with WordPress core coding patterns

### Fixed
- **Settings Page Assets**: Proper JavaScript enqueuing with dependencies
  - Admin JS now depends on jQuery and Select2
  - Localized script receives PHP constants for dynamic behavior
  - No more inline JavaScript mixing PHP and JS syntax

## [0.9.3] - 2026-01-17

### Changed
- **WordPress Login Page Compliance**: Refactored verification page to match WordPress core patterns
  - Uses WordPress login page structure (`#login`, `#loginform`, `.message`, etc.)
  - Replaced `wp_head()` with `login_head` action to prevent theme asset loading
  - Replaced `wp_footer()` with `login_footer` action for proper login page footer
  - Consolidated CSS from 245 lines (2 files) to 40 lines (1 file)
  - Leverages WordPress core login styles for consistency

### Added
- **Query Monitor Suppression**: Added `do_action('qm/cease')` to prevent debug output on 2FA pages
  - Prevents sensitive debugging information from displaying on login pages
  - Security enhancement to avoid information leakage

### Fixed
- **Asset Loading**: Eliminated theme/frontend CSS/JS from loading on verification page
  - Previously loaded all theme assets via `wp_head()` and `wp_footer()`
  - Now uses login-specific hooks for isolated, minimal asset loading
  - Improves performance and prevents visual conflicts

### Improved
- **Code Standards**: All view template code now follows WordPress coding standards
  - Code-first template approach using `printf()` and `echo`
  - No inline HTML mixed with PHP snippets
  - Passes phpcs with 0 errors, 0 warnings

## [0.9.2] - 2025-12-21

### Fixed
- **Password Manager Compatibility**: Fixed password update form field names
  - Changed password field name from `q2fa_new_password` to standard `password`
  - Changed hidden username field from email to actual `user_login` value
  - Added separate hidden email field for password managers that match on email
  - Password managers now correctly update password field instead of username

### Security
- **Template Protection**: Added `is_user_logged_in()` checks to view templates
  - Both verification-page.php and password-page.php now verify user is logged in
  - Defense-in-depth approach prevents accidental data exposure
  - Redirects to login page if accessed without authentication

## [0.9.1] - 2025-12-21

### Added
- **Email Masking Function**: New `mask_email()` function for privacy protection
  - Masks email addresses displayed on verification page
  - Example: `paul@headwall.co.uk` becomes `p***@h***********.uk`
  - Shows first character of local part and domain name, plus full TLD

### Changed
- **Verification Page Layout**: Improved email address display
  - Email address now on separate line to prevent word wrap issues
  - Changed from inline text to multi-line format for better readability
  - Masked email prevents disclosure to attackers with only username/password

### Security
- **Privacy Enhancement**: Email addresses no longer exposed on verification page
  - Prevents email harvesting by attackers with stolen credentials
  - Maintains user confirmation while protecting privacy

## [0.9.0] - 2025-12-21

### Added
- **WP-CLI Emergency Disable Command**: New `emergency_disable` command for emergency recovery
  - Sets plugin mode to disabled, bypassing all 2FA checks
  - Requires `--yes` flag to confirm or prompts for confirmation
  - Logs emergency disable events to error log
  - Use: `wp quick-2fa emergency_disable` or `wp quick-2fa emergency_disable --yes`
- **Security Policy Documentation**: Added comprehensive SECURITY.md file
  - Vulnerability reporting process with security@power-plugins.com contact
  - 48-hour response time commitment
  - Complete security features documentation
  - Emergency recovery procedures via WP-CLI, database, and FTP
  - Known limitations and administrator security checklist

### Changed
- **PHP Requirement**: Minimum PHP version is 8.0 (supports 8.0+)
- **Code Quality Improvements**: Comprehensive defensive coding enhancements
  - Added type validation for all WordPress meta and option retrieval
  - Added `isset()` checks for all PHP superglobal accesses ($_SERVER, $_GET, $_POST)
  - Added empty else blocks with explanatory comments to all if/elseif chains
  - Improved code path coverage and clarity throughout codebase

### Fixed
- **PHP 8.2+ Compatibility**: Fixed float-string conversion warning
  - Added `is_numeric()` validation before casting user meta values to integers
  - Handles PHP_INT_MAX scientific notation safely in lockout status checks
- **Missing Return Statement**: Fixed missing return in user lockout column rendering
- **Superglobal Access**: Fixed undefined array key warnings
  - `get_current_admin_url()` now handles missing $_SERVER['REQUEST_URI']
  - `get_user_agent()` safely accesses $_SERVER['HTTP_USER_AGENT']
- **Dead Code Removal**: Removed reference to non-existent `wp_get_user_ip()` function

### Technical
- **Switch to If/Else**: Refactored switch statement to cleaner if/else structure in `render_lockout_column()`
- **Type Safety**: Enhanced numeric validation in `get_user_lockout_status()`
- **WordPress Coding Standards**: Verified compliance via phpcs (all informational warnings only)
- **PHPDoc Coverage**: Confirmed 100% documentation coverage across all files
- **Admin Notifications**: Already includes warning when MODE_DISABLED is active

## [0.8.1] - 2025-12-14

### Fixed
- **Undefined Variable Warning**: Fixed undefined `$user` variable in verification page template
  - Added `$user = get_userdata( $user_id );` before requiring template
  - Resolves PHP warnings in error logs
  - Template now receives expected user object as documented

## [0.8.0] - 2025-12-05

### Added
- **Customizable Password Generation**: New `quick2fa_password_parameters` filter
  - Allows developers to customize suggested passwords on password reminder page
  - Control password length (8-64 characters, randomized 10-16 by default)
  - Toggle special characters and extra special characters
  - Automatic fallback to secure defaults for malformed filter returns
  - Validates all parameters to prevent security issues
- **Password Generation Constants**: Added to `constants.php`
  - `DEFAULT_PASSWORD_LENGTH_MIN = 10`
  - `DEFAULT_PASSWORD_LENGTH_MAX = 16`
  - `DEFAULT_PASSWORD_SPECIAL_CHARS = true`
  - `DEFAULT_PASSWORD_EXTRA_SPECIAL = false`

### Changed
- **Enhanced `generate_strong_password()` Method**:
  - Now accepts customization via filter hook
  - Randomizes password length between min/max by default
  - Robust validation prevents empty/malformed arrays
  - Type checking for all parameters (int for length, bool for flags)
  - Length automatically clamped to safe 8-64 character range

### Security
- All filter returns validated before use
- Empty arrays fall back to secure defaults
- Non-array returns fall back to secure defaults
- Invalid types fall back to secure defaults
- Minimum 8 character length enforced
- Maximum 64 character length enforced

### Technical Notes
- Filter hook: `quick2fa_password_parameters`
- Parameters: `length` (int), `special_chars` (bool), `extra_special_chars` (bool)
- Default behavior: Random length 10-16 chars with standard special chars
- Example usage documented in README.md

## [0.7.2] - 2025-12-04

### Added
- **User Switching Plugin Compatibility**: Automatic bypass for User Switching plugin
  - Detects when admin has switched into another user account
  - Skips 2FA verification when `current_user_switched()` returns true
  - Improves admin UX when managing multiple user accounts
  - No configuration needed - works automatically when User Switching is active
  - Safe implementation: User Switching has own authorization checks

### Technical Notes
- Added check in `should_skip_check()` function
- Uses `function_exists('current_user_switched')` for graceful degradation
- Only bypasses when User Switching plugin is active and user was switched
- Maintains security: User Switching requires `switch_to_user` capability

## [0.7.1] - 2025-12-03

### Changed
- **Trusted Devices Always Enabled**: Removed admin setting to enable/disable trusted devices
  - Feature is now always available to users
  - Users still choose whether to trust their device during verification
  - Simplified admin settings page by removing unnecessary toggle
  - Better UX: No confusion about whether feature is available
- **Refactored Option Logic**: Renamed for better default handling
  - Changed `OPTION_ENABLE_TRUSTED_DEVICES` to `OPTION_DISABLE_TRUSTED_DEVICES`
  - Changed `DEFAULT_ENABLE_TRUSTED_DEVICES = false` to `DEFAULT_DISABLE_TRUSTED_DEVICES = false`
  - Reversed all boolean logic checks throughout codebase
  - Missing/empty option now correctly defaults to "enabled"
  - Avoids WordPress `get_option()` false/empty ambiguity

### Removed
- **Settings Page**: Removed "Allow users to trust devices" checkbox
- **Settings Registration**: Removed `OPTION_ENABLE_TRUSTED_DEVICES` from registered settings
- **Unnecessary Variables**: Cleaned up `$trusted_devices_enabled` variable and related code

### Technical Notes
- Option renamed: `quick2fa_enable_trusted_devices` → `quick2fa_disable_trusted_devices`
- Default value: `false` (devices enabled)
- Logic pattern: `if ( ! get_option( OPTION_DISABLE_TRUSTED_DEVICES ) )` means "if enabled"
- Future extensibility: Easy to add filter `apply_filters('quick2fa_disable_trusted_devices', false)`
- Files modified: 6 files, 8 insertions(+), 41 deletions(-)

## [0.7.0] - 2025-12-03

### Added
- **Trusted Devices Profile Section**: User profile management for trusted devices
  - View all trusted devices with expiration dates
  - "This Device" indicator shows current device in the list
  - Individual device revocation with nonce security
  - Bulk "Revoke All Devices" action
  - Automatic cleanup of expired devices on page load
  - Permission checks: users can edit own profile, admins can edit others
  - Event logging for device revocations (single and bulk)
- **View Template Separation**: Extracted HTML from PHP classes
  - Created `views/profile-trusted-devices.php` template
  - Clean MVC pattern with data preparation in controller
  - Improved maintainability and code organization

### Changed
- **Verification Flow Refactoring**: Flattened `handle_verification_page()` method
  - Converted deeply nested if/else statements to flat if/elseif chain
  - Improved code readability and logic tracing
  - Easier to debug and maintain
- **Enhanced Security**: Nonce verification improvements
  - All `wp_unslash($_POST['_wpnonce'])` now wrapped with `sanitize_text_field()`
  - All `wp_unslash($_GET['_wpnonce'])` now wrapped with `sanitize_text_field()`
  - Consistent pattern across all 8 nonce verification points
  - Meets WordPress Coding Standards requirements

### Removed
- **Debug Code Cleanup**: Removed development artifacts
  - Removed 6 debug `error_log()` statements from `user_needs_verification()`
  - Cleaned up unused `$user` variable in `handle_verification_page()`
  - Production-ready code with no debug output

### Technical Notes
- Profile section hooks: `show_user_profile` and `edit_user_profile`
- Admin actions: `admin_action_quick2fa_revoke_device` and `admin_action_quick2fa_revoke_all_devices`
- Current device fingerprint passed to template for comparison
- Template uses WordPress table styling (form-table, widefat)
- All device management respects `OPTION_ENABLE_TRUSTED_DEVICES` setting

## [0.6.1] - 2025-12-02

### Added
- **MU Plugin Compatibility**: First-run initialization for Must-Use plugin installations
  - `Plugin::check_first_run()` method runs on `admin_init` with priority 1
  - Automatically sets default options when `quick2fa_version` option doesn't exist
  - All options set with autoload enabled for performance
  - Version tracking for future upgrade logic

### Changed
- **Settings Page UX**: Reordered 2FA mode radio options for better flow
  - Now: Disabled → Enabled for all users → Enabled for specific roles
  - Places "specific roles" option adjacent to role selector
- **Email Settings**: Enhanced input fields with `widefat` CSS class
  - From Name, From Address, and Subject fields now full-width
  - Better visibility and easier editing of email configuration

### Technical Notes
- Activation hooks remain for regular plugin installations
- MU plugin installations now properly initialize via `check_first_run()`
- Explicit `else` clauses in conditionals for code clarity
- All user preferences preserved during version updates

## [0.6.0] - 2025-12-02

### Added
- **Trusted Devices**: Optional "Trust this device" feature with configurable expiry (1-365 days)
  - SHA256 device fingerprinting based on user agent and IP
  - User meta storage for trusted device records
  - Automatic cleanup of expired devices
  - Settings page configuration for enabling/disabling and expiry duration
- **User Lock-Out Management**: Comprehensive admin UI for managing locked users
  - "Lock Status" column in users table with visual indicators
  - Filter views for locked/unlocked users with counts
  - Row actions for manual lock/unlock operations
  - Self-lock prevention for current admin
  - Session termination when locking accounts
- **WP-CLI Commands**: Full command-line interface for user security management
  - `wp quick-2fa lock <user>` - Lock account and terminate sessions
  - `wp quick-2fa unlock <user>` - Unlock account and reset attempts
  - `wp quick-2fa lock-all [--exclude=<user>]` - Emergency lockdown
  - `wp quick-2fa unlock-all` - Bulk unlock all users
  - `wp quick-2fa status <user>` - Comprehensive user status
  - `wp quick-2fa list-locked [--format=<format>]` - List locked users
  - `wp quick-2fa clear-devices <user>` - Remove trusted devices
- **Configurable Lock-Out Duration**: Admin setting for automatic lock duration
  - Configurable from 1-1440 minutes (1 minute to 24 hours)
  - Default: 60 minutes
  - Separate from permanent manual locks

### Changed
- **Lock-Out Enforcement**: Now blocks ALL users at login, not just those with active admin sessions
  - Added `wp_authenticate_user` filter to check lock status during authentication
  - Enhanced `check_verification()` to handle already-logged-in users
  - Improved lock detection and messaging (permanent vs temporary)
- **Session Management**: All lock operations now terminate active sessions via `WP_Session_Tokens::destroy_all()`
- **Permanent Lock Detection**: Fixed messaging for permanent locks (>100 years = "contact administrator")
- **WP-CLI Namespace**: Standardized command namespace from `quick2fa` to `quick-2fa` for consistency

### Removed
- **Deprecated Functions**: Cleaned up for v1.0.0 readiness
  - Removed wrapper functions that were deprecated in v0.4.0
  - Direct use of handler classes now required

### Technical Notes
- Manual locks (via admin UI or CLI) use `PHP_INT_MAX` expiry (~68 years)
- Automatic locks (rate-limit breaches) use configurable duration from settings
- Device fingerprints stored as SHA256 hashes with timestamps
- All WP-CLI commands accept user ID, login, or email as identifier
- User Management UI uses proper nonce verification and capability checks

## [0.5.0] - 2025-12-02

### Added
- PHP 8.2 type hints for all function signatures (parameters and return types)
- Email template directory (`emails/`) for better organization
- Template file `emails/verification-code.php` for verification email content
- Global caching for expensive function calls (`get_default_settings()`, `get_default_protected_roles()`, `get_default_email_template()`)
- `.htaccess` file in `docs/` directory to prevent web access to documentation

### Changed
- Standardized direct access blocking to use `defined( 'ABSPATH' ) || die();` across all files
- Refactored `get_default_email_template()` to load from template file instead of inline string
- Improved code quality with strict type declarations
- Enhanced maintainability with cleaner separation of email templates

### Technical Notes
- All functions now leverage PHP 8.2+ type system for better type safety
- Performance improvements through request-level caching of default values
- More consistent code style across the project

## [0.4.0] - 2025-12-02

### Changed
- **Major Architecture Refactoring**: Restructured codebase for better maintainability and extensibility
- Consolidated all code into `Quick_2FA` namespace (removed global-scope functions)
- Extracted focused handler classes for better separation of concerns:
  - `Account_Security_Handler` - Account locking and security event logging
  - `Email_Handler` - Email template rendering and sending
  - `Verification_Code_Handler` - Code generation, storage, and verification
  - `Password_Reminder_Handler` - Password age tracking and updates
- Improved code organization with class-based architecture
- Enhanced testability through dependency injection
- Simplified Plugin class by delegating to handler classes

### Added
- New handler classes provide better API for extending functionality
- Improved security event logging with `Account_Security_Handler::get_event_log()`
- Better lock time tracking with `Account_Security_Handler::get_lock_time_remaining()`
- More flexible email handling with separated template and sending logic

### Deprecated
- Wrapper functions in `functions.php` are now deprecated (but still work for backward compatibility):
  - `generate_code()` - Use `Verification_Code_Handler::generate()` instead
  - `store_code()` - Use `Verification_Code_Handler::store()` instead
  - `verify_code()` - Use `Verification_Code_Handler::verify()` instead
  - `send_verification_code()` - Use `Verification_Code_Handler::send_via_email()` instead
  - `get_email_message()` - Use `Email_Handler::get_message()` instead
  - `get_email_headers()` - Use `Email_Handler::get_headers()` instead
  - `is_account_locked()` - Use `Account_Security_Handler::is_locked()` instead
  - `lock_account()` - Use `Account_Security_Handler::lock_account()` instead
  - `log_event()` - Use `Account_Security_Handler::log_event()` instead

### Technical Notes
- All refactoring is backward compatible - existing integrations continue to work
- Preparation for future SMS and OTP/Google Authenticator support
- Improved code quality and WordPress coding standards compliance

## [0.3.0] - 2025-12-01

### Added
- Security hardening improvements
- Enhanced input validation
- Improved nonce verification
- Better rate limiting implementation

### Changed
- Code quality improvements
- Updated documentation

## [0.2.0] - Earlier Release

### Added
- Password reminder functionality
- Customizable email templates
- Role-based protection settings
- Admin settings interface

## [0.1.0] - Initial Release

### Added
- Email-based two-factor authentication
- Verification code generation and validation
- Account locking after failed attempts
- Session management
- Basic settings interface
