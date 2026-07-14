# gCore Security Documentation

This document provides information about the security features, configurations, and best practices for the gCore framework.

## Table of Contents

1. [Security Overview](#security-overview)
2. [Security Architecture](#security-architecture)
3. [Security Manager](#security-manager)
4. [Authentication and Authorization](#authentication-and-authorization)
5. [Input Validation and Sanitization](#input-validation-and-sanitization)
6. [Cross-Site Scripting (XSS) Prevention](#cross-site-scripting-xss-prevention)
7. [Content Security Policy](#content-security-policy)
8. [Rate Limiting](#rate-limiting)
9. [Security Monitoring and Logging](#security-monitoring-and-logging)
10. [WordPress Integration](#wordpress-integration)
11. [Security Headers](#security-headers)
12. [Configuration Best Practices](#configuration-best-practices)
13. [Troubleshooting](#troubleshooting)
14. [FAQ](#faq)

## Security Overview

gCore is designed with a security-first approach, integrating security directly into the architecture rather than as an afterthought. The framework implements multiple layers of security:

- **Security Manager**: Centralized security management system
- **XSS Prevention**: Automatic detection and prevention of cross-site scripting attacks
- **Content Security Policy**: Configurable CSP implementation
- **Hardware Security Module Support**: Integration with physical security devices
- **Rate Limiting**: Intelligent rate limiting for API requests
- **Input Validation**: input sanitization
- **Security Headers**: HTTP security headers implementation
- **Monitoring and Alerts**: Real-time security monitoring with alerting capabilities

## Security Architecture

gCore's security architecture follows the principles of "defense in depth" with multiple security layers working together:

```
┌─────────────────────────────────────────────────────────────┐
│                    gCore Security Architecture              │
├─────────────────────────────────────────────────────────────┤
│ ┌─────────────┐ ┌─────────────┐ ┌─────────────────────────┐ │
│ │ HTTP Headers│ │  Input      │ │ Authentication &        │ │
│ │ CSP, HSTS,  │ │  Validation │ │ Authorization           │ │
│ │ XFO, etc.   │ │  & Filtering│ │                         │ │
│ └─────────────┘ └─────────────┘ └─────────────────────────┘ │
│                                                             │
│ ┌─────────────┐ ┌─────────────┐ ┌─────────────────────────┐ │
│ │ XSS         │ │ Rate        │ │ Cryptographic           │ │
│ │ Prevention  │ │ Limiting    │ │ Operations              │ │
│ │             │ │             │ │                         │ │
│ └─────────────┘ └─────────────┘ └─────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────┐ ┌───────────────────────────┐   │
│ │ Security Monitoring     │ │ Hardware Security Module  │   │
│ │ & Alerting              │ │ Integration               │   │
│ │                         │ │                           │   │
│ └─────────────────────────┘ └───────────────────────────┘   │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                     Security Manager                    │ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │                       ValKey Storage                    │ │
│ │                                                         │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

The security systems are modular and composed using trait-based design, allowing for components to be enabled or disabled as needed.

## Security Manager

The `SecurityManager` class is the core component that coordinates all security features. It follows a singleton pattern and provides interfaces for:

- Initializing security components
- Managing security features
- Applying security policies
- Monitoring security events
- Integrating with other gCore components

### Initialization

```php
// Get the SecurityManager instance
$securityManager = \gCore\Modules\Core\gCore::getInstance()->getSecurityManager();

// Check if security features are enabled
if ($securityManager->isEnabled()) {
    // Security is enabled and functioning
}
```

### Configuration

The SecurityManager can be configured in the `config/managers/SecurityManager.yaml` file:

```yaml
security:
  level: high           # low, medium, high, extreme
  xss_prevention: true
  rate_limiting: true
  input_validation: true
  content_security_policy: true
  monitoring: true
  
  # Default security limits
  limits:
    request_rate: 60     # Requests per minute
    login_attempts: 5    # Per 15 minutes
    password_reset: 3    # Per hour
    api_rate: 120        # Per minute
    
  # Notification settings  
  notifications:
    email: security@example.com
    critical_only: false
    channels:
      - email
      - admin_dashboard
      - webhook
```

## Authentication and Authorization

gCore implements a reliable authentication and authorization system with support for various authentication methods.

### WordPress Integration

When used in WordPress, gCore integrates with the WordPress authentication system but adds enhanced security:

- 2FA support for admin authentication
- Advanced role-based capability management
- API token authentication with fine-grained permissions
- Session management with security controls

### Custom Authentication

For standalone applications, gCore provides its own authentication system:

- Username/password authentication
- OAuth 2.0/OpenID Connect support
- JWT token authentication
- API key authentication

### Capability-Based Authorization

gCore uses a capability-based authorization system. Instead of just roles, the system checks for specific capabilities:

```php
// Check if user has specific capabilities
if ($securityManager->hasCapability('manage_security_settings')) {
    // Allow access to security settings
}
```

## Input Validation and Sanitization

The framework provides input validation and sanitization through the `SanitationTrait`.

### Usage

```php
// Sanitize input directly
$clean_input = $securityManager->sanitizeInput($_POST['user_input']);

// Validate against a pattern
if ($securityManager->validateInput($input, 'email')) {
    // Input is a valid email
}

// Check for malicious patterns
if ($securityManager->detectMaliciousInput($input)) {
    // Potentially malicious input detected
}
```

### Validation Patterns

The framework includes predefined validation patterns for common data types:

- Email addresses
- URLs
- IP addresses
- Usernames
- Numeric values
- Dates
- Telephone numbers
- Credit card numbers (format only)

## Cross-Site Scripting (XSS) Prevention

gCore implements XSS prevention through the `XSSPreventionTrait`.

### Features

- Automatic detection of XSS patterns
- HTML sanitization
- JavaScript URL filtering
- Attribute filtering
- Integration with WordPress kses functions when available

### Usage

```php
// Check if a string contains XSS patterns
if ($securityManager->detectXSS($input)) {
    // XSS detected, handle accordingly
}

// Sanitize HTML content
$safe_html = $securityManager->sanitizeHTML($html_content);

// Sanitize URLs
$safe_url = $securityManager->sanitizeURL($url);
```

## Content Security Policy

The framework includes a Content Security Policy implementation with nonce-based script and style restrictions for enhanced security.

### Configuration

```yaml
security:
  content_security_policy:
    enabled: true
    report_only: false
    report_uri: https://example.com/csp-report
    directives:
      default-src:
        - "'self'"
      script-src:
        - "'self'"
        - "'nonce-{NONCE}'"  # Automatically replaced with per-request nonce
        - "https://trusted-cdn.example.com"
      style-src:
        - "'self'"
        - "'nonce-{NONCE}'"  # Automatically replaced with per-request nonce
      img-src:
        - "'self'"
        - "data:"
      connect-src:
        - "'self'"
      frame-ancestors:
        - "'none'"
      form-action:
        - "'self'"
      base-uri:
        - "'self'"
      object-src:
        - "'none'"
      upgrade-insecure-requests: true
      block-all-mixed-content: true
```

### Nonce Generation

The SecurityManager automatically generates a cryptographically secure nonce for each request and makes it available for script and style tags:

```php
// Get the current request's CSP nonce
$nonce = $securityManager->getCSPNonce();

// Use it in script tags
echo '<script nonce="' . $nonce . '">console.log("Secure script");</script>';

// Use it in style tags
echo '<style nonce="' . $nonce . '">body { background-color: #f0f0f0; }</style>';
```

### WordPress Integration

When used with WordPress, the CSP implementation automatically adds appropriate sources for WordPress core functionality.

## Rate Limiting

The framework implements intelligent rate limiting to prevent abuse, brute force attacks, and DoS attempts.

### Features

- IP-based rate limiting
- User-based rate limiting
- API endpoint-specific limits
- Distributed rate limiting (via ValKey)
- Adaptive throttling based on system load

### Configuration

```yaml
security:
  rate_limiting:
    enabled: true
    default_limit: 60  # requests per minute
    ip_whitelist:
      - 127.0.0.1
      - 192.168.1.0/24
    endpoints:
      login:
        limit: 5       # attempts per 15 minutes
        window: 900    # seconds
      api:
        limit: 120     # requests per minute
        window: 60     # seconds
```

### Custom Rate Limits

```php
// Apply custom rate limit to an action
$result = $securityManager->rateLimitAction('custom_action', [
    'limit' => 10,
    'window' => 3600,
    'identifier' => $user_id  // Optional custom identifier
]);

if ($result['limited']) {
    // Rate limit exceeded
    $retry_after = $result['retry_after'];
}
```

## Security Monitoring and Logging

gCore provides security monitoring and logging capabilities.

### Features

- Real-time security event monitoring
- Centralized security logging
- Alert thresholds for different event types
- Integration with ValKey for distributed monitoring
- Dashboard visualization of security events

### Configuration

```yaml
security:
  monitoring:
    enabled: true
    log_level: warning  # debug, info, warning, error, critical
    retention_days: 30
    alert_thresholds:
      login_failures: 5
      blocked_requests: 10
      xss_attempts: 1
    alert_channels:
      - email
      - webhook
      - admin_notification
```

### Usage

```php
// Log security event
$securityManager->logSecurityEvent('login_failure', [
    'username' => $username,
    'ip' => $ip_address,
    'reason' => 'invalid_password'
], 'warning');

// Check security status
$status = $securityManager->getSecurityStatus();
$blocked_requests = $status['blocked_requests'];
$suspicious_ips = $status['suspicious_ips'];
```

## WordPress Integration

When used with WordPress, gCore enhances the default WordPress security.

### Features

- Enhanced user authentication
- Admin 2FA support
- Advanced capability management
- Security headers implementation
- Input sanitization beyond WordPress defaults
- Security dashboard integration

### Admin Dashboard

The gCore WordPress integration includes a dedicated security dashboard with:

- Security status overview
- Recent security events
- Security configuration options
- Security scans
- Log viewer

## Security Headers

gCore automatically applies security headers based on the configured security level.

### Implemented Headers

- Content-Security-Policy
- X-XSS-Protection
- X-Frame-Options
- X-Content-Type-Options
- Referrer-Policy
- Strict-Transport-Security
- Permissions-Policy
- X-Permitted-Cross-Domain-Policies

### Configuration

Headers can be configured in the `config/security/headers.yaml` file:

```yaml
security:
  headers:
    X-Frame-Options: SAMEORIGIN
    X-Content-Type-Options: nosniff
    X-XSS-Protection: "1; mode=block"
    Referrer-Policy: strict-origin-when-cross-origin
    Strict-Transport-Security: "max-age=31536000; includeSubDomains"
    Permissions-Policy: "camera=(), microphone=(), geolocation=()"
```

## Configuration Best Practices

### Security Level Selection

Choose the appropriate security level based on your needs:

- **Low**: For development and testing only
- **Medium**: For standard websites with minimal sensitive data
- **High**: For sites with sensitive data or user information
- **Extreme**: For high-security applications, e-commerce, or financial services

### Production Recommendations

For production environments, we recommend:

1. Set security level to at least `high`
2. Enable XSS prevention
3. Configure Content Security Policy
4. Enable rate limiting
5. Configure security monitoring
6. Set up notification channels for critical events
7. Use HTTPS throughout the site
8. If available, enable hardware security module integration

### WordPress-Specific Recommendations

When using with WordPress:

1. Enable the WordPressSecurityTrait
2. Implement security headers
3. Enable 2FA for administrators
4. Configure capability-based access control
5. Regularly review security logs

## Troubleshooting

### Common Issues

#### Rate Limiting Too Aggressive

If legitimate users are being rate limited:

1. Increase the default rate limit
2. Add specific IPs to the whitelist
3. Add custom rate limits for specific endpoints

#### CSP Blocking Legitimate Resources

If the Content Security Policy is blocking legitimate resources:

1. Check browser console for CSP violation reports
2. Add the required sources to the appropriate CSP directive
3. Consider using CSP report-only mode during testing

#### Hardware Security Module Connection Issues

If the HSM connection fails:

1. Check if the HSM is connected and recognized by the system
2. Verify the library path
3. Ensure the PIN is correct
4. Check if the slot ID is valid
5. Enable fallback to software option if HSM availability is intermittent

## FAQ

### Is gCore PCI DSS compliant?

gCore implements many security features that support PCI DSS compliance, but the full compliance depends on your specific implementation and environment configuration.

### Does gCore support GDPR requirements?

Yes, gCore includes features that help with GDPR compliance, including secure data handling, input sanitization, and proper error logging. However, full GDPR compliance requires additional organizational measures.

### Can I use gCore in high-security environments?

Yes, gCore is designed for use in high-security environments when configured correctly. The extreme security level with HSM integration is specifically designed for such environments.

### How does gCore handle API security?

gCore provides API security features, including:
- Rate limiting
- Token-based authentication
- Capability-based authorization
- Input validation
- Security monitoring

### Is gCore compatible with Web Application Firewalls (WAF)?

Yes, gCore works well with WAFs and implements complementary security measures. For best results, we recommend using gCore with a WAF like ModSecurity or AWS WAF.

---

## Additional Resources

- [gCore WordPress Integration Guide](Guide-WordPress.md)
- [Security Manager API Reference](managers/SecurityManager/README_SecurityManager.md)

---

For specific security questions or to report security vulnerabilities, please contact the gCore security team at security@gcore.dev.

**Note**: Security is a continuous process. Regularly update gCore and review security configurations as new versions become available.