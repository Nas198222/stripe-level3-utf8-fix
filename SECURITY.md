# Security Policy

## Reporting a vulnerability

If you discover a security vulnerability in this project, please report it privately so it can be fixed before public disclosure.

**Email:** Hello@AladdinsHouston.com

Please include:
- A description of the vulnerability
- Steps to reproduce
- Affected versions (if known)
- Any proof-of-concept code or screenshots

You should expect a response within 5 business days. Once the issue is confirmed, we'll work on a fix and coordinate a disclosure timeline with you.

## Supported versions

Only the latest release on the `main` branch receives security updates. If you're running an older version, please update.

## Out of scope

- Vulnerabilities in third-party dependencies (please report those upstream)
- Issues that require physical access to the affected system
- Self-XSS, CSRF on logout endpoints, missing rate limits without demonstrable impact
