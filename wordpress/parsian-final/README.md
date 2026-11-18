# PARSIAN FINAL — WordPress Core

Initial implementation scaffold for the central WordPress plugin.

## Current scope
- Activation-time, version-stamped custom tables for audit events, registered devices and durable sync operations.
- Dedicated manager, accountant, seller and customer roles with capability-based authorization.
- Versioned REST endpoints:
  - `GET /wp-json/parsian-final/v1/health` (public service health only)
  - `GET /wp-json/parsian-final/v1/me` (authenticated users with `parsian_access_core`)
- Deactivation deliberately preserves data.

## Install on a disposable staging WordPress site
1. Copy this directory into `wp-content/plugins/parsian-final`.
2. Activate **PARSIAN FINAL Core**.
3. Check `/wp-json/parsian-final/v1/health`.
4. Sign in as a user assigned one of the PARSIAN roles and check `/wp-json/parsian-final/v1/me`.

## Security and release status
This is an early scaffold, not a production-ready accounting/CRM system. The current REST authentication relies on WordPress's active authentication context; mobile token issuance/refresh, device enrollment, nonce/CSRF policy, rate limiting, data endpoints and field-level authorization remain to be implemented and tested. Do not expose this on production before security review and staging tests.
