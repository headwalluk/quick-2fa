# Security Policy

## Supported Versions

We actively support the latest stable release of Quick 2FA. Security updates will be provided for:

| Version | Supported          |
| ------- | ------------------ |
| 0.8.x   | :white_check_mark: |
| < 0.8   | :x:                |

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you discover a security vulnerability within Quick 2FA, please send an email to security@power-plugins.com. All security vulnerabilities will be promptly addressed.

Please include the following information in your report:

- Type of vulnerability
- Full paths of source file(s) related to the vulnerability
- Location of the affected source code (tag/branch/commit or direct URL)
- Step-by-step instructions to reproduce the issue
- Proof-of-concept or exploit code (if possible)
- Impact of the issue, including how an attacker might exploit it

### Response Timeline

- **Initial Response**: Within 48 hours
- **Vulnerability Confirmation**: Within 7 days
- **Fix Development**: Depends on severity (Critical: 1-3 days, High: 3-7 days)
- **Public Disclosure**: After fix is released and users have had time to update (minimum 7 days)

## Security Best Practices

### For Users

1. **Keep the plugin updated** - Security patches are released promptly
2. **Use strong passwords** - The plugin enforces password requirements, follow them
3. **Monitor locked accounts** - Check the Users admin page regularly for suspicious lockouts
4. **Review event logs** - Use WP-CLI commands to audit security events
5. **Backup your site** - Before making configuration changes

### For Developers

If you're extending or integrating with Quick 2FA:

1. **Never disable nonce verification** - All form submissions must be verified
2. **Validate user capabilities** - Always check `current_user_can()` before sensitive operations
3. **Sanitize all inputs** - Use WordPress sanitization functions
4. **Escape all outputs** - Use `esc_html()`, `esc_attr()`, `esc_url()` appropriately
5. **Avoid direct database queries** - Use WordPress APIs and meta functions

## Security Features

Quick 2FA implements multiple security layers:

### Authentication Security
- Email-based two-factor authentication
- Cryptographically secure code generation (`random_int()`)
- Password hashing for code storage (`wp_hash_password()`)
- Automatic code expiration (configurable)
- Rate limiting on code generation and verification
- Account lockout after failed verification attempts

### Session Security
- Trusted device fingerprinting
- Configurable device trust expiration
- Session termination on account lock
- Device revocation (individual or all)

### Administrative Security
- Capability checks on all admin actions
- Nonce verification on all form submissions
- Self-lock prevention
- Audit logging of security events
- WP-CLI integration for emergency recovery

### Data Protection
- Email header injection prevention
- URL validation before redirects
- Safe handling of superglobal variables
- Type-safe function signatures
- Input validation and sanitization

## Emergency Recovery

If you're locked out and cannot access the WordPress admin:

### Via WP-CLI
```bash
# Disable 2FA plugin temporarily
wp quick-2fa emergency_disable

# Check plugin status
wp quick-2fa status <username>

# Unlock a specific user
wp quick-2fa unlock <username>
```

### Via Database
Only use as a last resort if WP-CLI is unavailable:
```sql
UPDATE wp_options 
SET option_value = 'disabled' 
WHERE option_name = 'quick2fa_mode';
```

### Via FTP/File Manager
Temporarily rename the plugin folder to disable it:
```bash
mv quick-2fa quick-2fa-disabled
```

## Known Limitations

1. **Email Dependency**: The plugin requires a functioning email system. Configure SMTP if needed.
2. **Shared Hosting**: Some rate limiting features may be affected by shared hosting constraints.
3. **Clock Skew**: Server time must be reasonably accurate for time-based features.

## Security Checklist for Site Administrators

- [ ] Email delivery is configured and tested
- [ ] SMTP is configured (recommended for production)
- [ ] Backup admin account exists with 2FA disabled
- [ ] WP-CLI access is available for emergency recovery
- [ ] Regular security log reviews are scheduled
- [ ] Failed login monitoring is in place
- [ ] Plugin updates are applied promptly
- [ ] Database backups are automated

## Credits

We appreciate the security research community and welcome responsible disclosure. Contributors who report valid security issues will be credited in release notes (unless they prefer to remain anonymous).

## License

This security policy is licensed under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/).
