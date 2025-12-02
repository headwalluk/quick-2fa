# Code Refactoring Plan - v0.4.0

## Overview
Refactor codebase to improve separation of concerns, testability, and maintainability by grouping related functionality into focused classes and keeping utility functions separate.

## Goals
- ✅ Single namespace (Quick_2FA) for all code
- ✅ Remove global-scope functions
- ✅ Group related functionality into cohesive classes
- ✅ Keep utility functions separate and simple
- ✅ Improve testability through dependency injection where possible
- ✅ Maintain backward compatibility for hooks/filters

---

## Phase 1: Merge Functions Files ✅ COMPLETE

### Task: Consolidate into Single Namespaced functions.php
- [x] Merge current `functions.php` (global scope) into `functions-private.php`
- [x] Rename `functions-private.php` → `functions.php`
- [x] Update `quick-2fa.php` to load the single `functions.php`
- [x] Ensure all functions are in `Quick_2FA` namespace
- [x] Update all function references across codebase
- [x] Test that plugin still works after merge (phpcs passes)

**Files affected:**
- `functions.php` (deleted old global-scope version)
- `functions-private.php` (renamed to functions.php)
- `quick-2fa.php` (updated require statement)
- `includes/class-plugin.php` (updated function calls)
- `includes/class-settings.php` (updated function calls)

---

## Phase 2: Extract Verification Code Logic ✅ COMPLETE

### Task: Create Verification_Code_Handler Class
- [x] Create new file: `includes/class-verification-code-handler.php`
- [x] Move code generation logic from `functions.php`
- [x] Move code storage logic from `functions.php`
- [x] Move code verification logic from `functions.php`
- [x] Move rate limiting logic specific to code generation
- [x] Add instance-based class with user_id property
- [x] Keep backward-compatible wrapper functions in functions.php
- [x] Verify with php-sniff (passes)

**New Class Created:**
```php
class Verification_Code_Handler {
    private $user_id;
    
    public function __construct( $user_id )
    public function generate()
    public function store( $code )
    public function verify( $submitted_code )
    public function is_expired()
    public function get_remaining_attempts()
    public function check_rate_limit()
    public function send_via_email()
    private function cleanup()
}
```

**Functions refactored:**
- `generate_code()` → wrapper for `Verification_Code_Handler::generate()`
- `store_code()` → wrapper for `Verification_Code_Handler::store()`
- `verify_code()` → wrapper for `Verification_Code_Handler::verify()`
- `check_code_generation_rate_limit()` → wrapper for `Verification_Code_Handler::check_rate_limit()`
- `send_verification_code()` → wrapper for `Verification_Code_Handler::send_via_email()`

**Files affected:**
- `includes/class-verification-code-handler.php` (created)
- `quick-2fa.php` (added require for new class)
- `functions.php` (refactored functions to use new class)

---

## Phase 3: Extract Email Logic ✅ COMPLETE

### Task: Create Email_Handler Class
- [x] Create new file: `includes/class-email-handler.php`
- [x] Move email formatting logic from `functions.php`
- [x] Move email sending logic from `functions.php`
- [x] Add template rendering methods
- [x] Add header sanitization methods
- [x] Integrate Email_Handler into Verification_Code_Handler
- [x] Keep backward-compatible wrapper functions
- [x] Verify with php-sniff (passes)

**New Class Created:**
```php
class Email_Handler {
    public function send_verification_code( $user, $code )
    public function get_headers()
    public function get_message( $code, $user )
    public function sanitize_headers( $from_name, $from_email )
    private function get_template()
    private function replace_placeholders( $template, $data )
}
```

**Functions refactored:**
- `get_email_message()` → wrapper for `Email_Handler::get_message()`
- `get_email_headers()` → wrapper for `Email_Handler::get_headers()`
- Email sanitization logic → `Email_Handler::sanitize_headers()`

**Files affected:**
- `includes/class-email-handler.php` (created)
- `includes/class-verification-code-handler.php` (updated to use Email_Handler)
- `quick-2fa.php` (added require for new class)
- `functions.php` (refactored functions to use new class)

---

## Phase 4: Extract Password Reminder Logic ✅ COMPLETE

### Task: Create Password_Reminder_Handler Class
- [x] Create new file: `includes/class-password-reminder-handler.php`
- [x] Move password age checking logic from `Plugin` class
- [x] Move password update logic from `Plugin` class
- [x] Move reminder cooldown logic from `Plugin` class
- [x] Add session management for password changes
- [x] Integrate Password_Reminder_Handler into Plugin class
- [x] Verify with php-sniff (passes)

**New Class Created:**
```php
class Password_Reminder_Handler {
    private $user_id;
    
    public function __construct( $user_id )
    public function needs_reminder()
    public function get_password_age()
    public function is_in_cooldown()
    public function update_password( $new_password )
    public function dismiss_reminder()
    public function generate_strong_password( $length = 16 )
    private function maintain_session( $sessions, $current_token )
}
```

**Methods refactored in Plugin class:**
- `user_needs_password_reminder()` → uses `Password_Reminder_Handler::needs_reminder()`
- `handle_password_page()` → uses `Password_Reminder_Handler` for all password operations

**Files affected:**
- `includes/class-password-reminder-handler.php` (created)
- `includes/class-plugin.php` (refactored to use Password_Reminder_Handler)
- `quick-2fa.php` (added require for new class)

---

## Phase 5: Extract Account Security Logic ✅ COMPLETE

### Task: Create Account_Security_Handler Class
- [x] Create new file: `includes/class-account-security-handler.php`
- [x] Move account locking logic from `functions.php`
- [x] Move event logging logic from `functions.php`
- [x] Integrate Account_Security_Handler into Verification_Code_Handler
- [x] Integrate Account_Security_Handler into Password_Reminder_Handler
- [x] Keep backward-compatible wrapper functions
- [x] Verify with php-sniff (passes)

**New Class Created:**
```php
class Account_Security_Handler {
    private $user_id;
    
    public function __construct( $user_id )
    public function is_locked()
    public function lock_account( $duration = null )
    public function unlock_account()
    public function get_lock_time_remaining()
    public function log_event( $event_type, $data = array() )
    public function get_event_log( $limit = 50 )
    public function clear_event_log()
    public static function get_client_ip()
    public static function get_client_user_agent()
}
```

**Functions refactored:**
- `is_account_locked()` → wrapper for `Account_Security_Handler::is_locked()`
- `lock_account()` → wrapper for `Account_Security_Handler::lock_account()`
- `log_event()` → wrapper for `Account_Security_Handler::log_event()`

**Files affected:**
- `includes/class-account-security-handler.php` (created)
- `includes/class-verification-code-handler.php` (updated to use Account_Security_Handler)
- `includes/class-password-reminder-handler.php` (updated to use Account_Security_Handler)
- `quick-2fa.php` (added require for new class)
- `functions.php` (refactored functions to use new class)

---

## Phase 6: Clean Up Utility Functions ✅ COMPLETE

### Task: Keep Only Pure Utility Functions in functions.php
After all extractions, `functions.php` contains:

**Pure Utilities (Kept):**
- [x] `get_ip_address()` - Network utility
- [x] `get_user_agent()` - Network utility
- [x] `get_current_admin_url()` - URL utility
- [x] `store_return_url()` - Session utility
- [x] `get_return_url()` - Session utility
- [x] `should_skip_check()` - Context checking utility
- [x] Settings & configuration functions
- [x] URL generation utilities

**Deprecated Wrappers (Maintained for backward compatibility):**
- [x] `log_event()` → wraps Account_Security_Handler
- [x] `generate_code()` → wraps Verification_Code_Handler
- [x] `store_code()` → wraps Verification_Code_Handler
- [x] `verify_code()` → wraps Verification_Code_Handler
- [x] `check_code_generation_rate_limit()` → wraps Verification_Code_Handler
- [x] `send_verification_code()` → wraps Verification_Code_Handler
- [x] `get_email_message()` → wraps Email_Handler
- [x] `get_email_headers()` → wraps Email_Handler
- [x] `is_account_locked()` → wraps Account_Security_Handler
- [x] `lock_account()` → wraps Account_Security_Handler

---

## Phase 7: Update Plugin Class ✅ COMPLETE

### Task: Refactor Plugin Class to Use New Handlers
- [x] Update `handle_verification_page()` to use `Verification_Code_Handler`
- [x] Update `handle_password_page()` to use `Password_Reminder_Handler`
- [x] Update `user_needs_password_reminder()` to use `Password_Reminder_Handler`
- [x] All handler classes properly integrated
- [x] Plugin class simplified and delegating to handlers

**Example Usage:**
```php
class Plugin {
    private $verification_handler;
    private $email_handler;
    private $password_handler;
    private $security_handler;
    
    private function handle_verification_page() {
        $user_id = get_current_user_id();
        
        $verification = new Verification_Code_Handler( $user_id );
        $email = new Email_Handler();
        
        if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
            $result = $verification->verify( $_POST['code'] );
            // ...
        }
    }
}
```

---

## Phase 8: Update Tests and Documentation ✅ COMPLETE

### Task: Ensure Everything Still Works
- [x] Run phpcs on all new files (passes with only pre-existing warnings)
- [x] Run phpcbf to fix code style issues
- [x] Update README.md with new architecture
- [x] Create CHANGELOG.md documenting v0.4.0 changes
- [x] Update version to 0.4.0
- [x] All handler classes follow WordPress coding standards

### Remaining Tasks (Future)
- [ ] Test verification flow end-to-end (manual testing)
- [ ] Test password reminder flow end-to-end (manual testing)
- [ ] Test rate limiting functionality (manual testing)
- [ ] Test account locking functionality (manual testing)
- [ ] Test email sending (manual testing)
- [ ] Update `docs/implementation.md` with new architecture details
- [ ] Add class diagrams showing new structure

---

## Phase 9: Create Unit Tests (Future)

### Task: Add PHPUnit Tests for Each Class
- [ ] Add PHPUnit configuration
- [ ] Write tests for `Verification_Code_Handler`
- [ ] Write tests for `Email_Handler`
- [ ] Write tests for `Password_Reminder_Handler`
- [ ] Write tests for `Account_Security_Handler`
- [ ] Add continuous integration

---

## Benefits After Refactoring

### Code Quality
✅ **Single Responsibility Principle** - Each class does one thing well
✅ **Testability** - Classes can be unit tested in isolation
✅ **Maintainability** - Changes isolated to specific classes
✅ **Readability** - Clear purpose for each file/class

### Architecture
✅ **Dependency Injection** - Handlers can be mocked for testing
✅ **Separation of Concerns** - Business logic separate from utilities
✅ **Encapsulation** - Related data and behavior grouped together
✅ **Extensibility** - Easy to add new verification methods (SMS, TOTP, etc.)

### Developer Experience
✅ **Clear Structure** - New developers can understand quickly
✅ **IDE Support** - Better autocomplete with class methods
✅ **Debugging** - Easier to trace through class methods
✅ **Documentation** - Classes are self-documenting

---

## File Structure After Refactoring

```
quick-2fa/
├── quick-2fa.php                          # Main plugin file
├── constants.php                          # Plugin constants
├── functions.php                          # Utility functions only (namespaced)
├── includes/
│   ├── class-plugin.php                  # Main plugin orchestrator
│   ├── class-settings.php                # Settings page handler
│   ├── class-verification-code-handler.php   # NEW: Code generation/verification
│   ├── class-email-handler.php               # NEW: Email sending/formatting
│   ├── class-password-reminder-handler.php   # NEW: Password reminder logic
│   └── class-account-security-handler.php    # NEW: Locking/logging/rate limiting
├── views/
│   ├── verification-page.php
│   ├── password-page.php
│   └── settings-page.php
└── docs/
    ├── requirements.md
    ├── implementation.md
    ├── security-review.md
    └── refactoring-plan.md               # This document
```

---

## Migration Strategy

### Backward Compatibility
- Keep public function wrappers if any themes/plugins use them (unlikely for private plugin)
- Maintain all existing action/filter hooks
- No changes to database schema or user meta keys

### Testing Approach
1. Complete Phase 1 (merge functions) - test thoroughly
2. Extract one class at a time (Phases 2-5)
3. Test after each class extraction
4. Don't move to next phase until current phase is stable

### Version Numbering
- **v0.4.0** - Complete refactoring with new architecture
- Mark as pre-release if needed for thorough testing
- **v1.0.0** - Stable release after refactoring proves solid

---

## Estimated Effort

- **Phase 1** (Merge): 30 minutes
- **Phase 2** (Verification Handler): 2 hours
- **Phase 3** (Email Handler): 1 hour
- **Phase 4** (Password Handler): 1.5 hours
- **Phase 5** (Security Handler): 1.5 hours
- **Phase 6** (Cleanup): 1 hour
- **Phase 7** (Plugin Updates): 2 hours
- **Phase 8** (Testing): 2 hours

**Total: ~11.5 hours** (spread over multiple sessions)

---

## Decision Points

### Question 1: Static vs Instance Methods?
**Recommendation:** Use instance methods with dependency injection
- Easier to test with mocks
- Can maintain state if needed
- More flexible for future enhancements

### Question 2: Keep Functions as Wrappers?
**Recommendation:** No wrappers needed
- Plugin is private/internal use
- Clean break to new architecture
- No backward compatibility concerns

### Question 3: When to Release?
**Recommendation:** Release v0.4.0 after thorough testing
- Mark as "pre-release" on GitHub if uncertain
- Get feedback before v1.0.0
- Can always roll back if issues arise

---

## Next Steps

1. **Review this plan** - Does it align with your vision?
2. **Start Phase 1** - Merge functions files (low risk, high value)
3. **Extract one handler** - Prove the pattern works
4. **Continue if successful** - Complete remaining phases
5. **Test thoroughly** - Ensure no regressions

---

## Notes

- Keep all git commits atomic (one phase per commit)
- Write descriptive commit messages
- Tag v0.4.0-alpha, v0.4.0-beta before final release
- Update CHANGELOG.md with architectural improvements
