# Changelog

All notable changes to Quick 2FA will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
