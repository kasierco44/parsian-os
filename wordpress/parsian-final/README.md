# PARSIAN FINAL — WordPress Core

Central WordPress plugin source for the PARSIAN FINAL platform. Plugin source version: **0.4.3**.

## Architecture
- WordPress REST API is the single system-of-record boundary for web, Android and Windows clients.
- Clients must never connect directly to the WordPress MySQL database.
- All writes require server-side capability checks, record-level scope, validation and auditability.
- Install and test only on an isolated staging WordPress site until migrations, rollback, security and acceptance tests pass.

## Implemented source areas
- Schema/install and version-gated upgrades.
- Roles and capabilities: manager, accountant, seller, customer.
- WordPress-hosted PARSIAN OS menu page with role-aware web workspace, bundled CSS/JS assets and server-generated REST nonce.
- CRM customer/lead endpoints with seller scoping in server-side queries.
- Persisted proformas, manager approval, invoice conversion and balanced sales/receivable journal posting.
- Payment approval, customer receipt allocations and party statements.
- Double-entry journal register, trial balance and customer/supplier subledger reporting.
- Inventory movement approval, stock ledger, weighted-average costing, invoice reservations and fulfillment/COGS posting.
- Cheque lifecycle and event history.
- Bank account, statement import batch and reconciliation allocation source.
- Opaque Bearer access/refresh token issuance, rotation, token-family revocation and device revocation.
- Initial offline sync device registration, operation allowlist, payload-hash idempotency and delta event pull.

## Important API routes
Base: `/wp-json/parsian-final/v1`

- `GET /health`, `GET /me`
- `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `POST /auth/revoke-device`
- `GET|POST /sync/devices`, `POST /sync/push`, `GET /sync/pull`
- `POST /proformas`, `GET /proformas`, `GET /proformas/{id}`, `POST /proformas/{id}/approve`
- `POST /payments/{id}/approve`, `GET /ledger/journals`, `GET /ledger/trial-balance`, `GET /ledger/party-statement`
- Bank routes include account management, statement import batches and reconciliation; consult `includes/class-bank.php` for current request schema.

## Staging-only setup
1. Back up the staging database and files.
2. Copy this directory to `wp-content/plugins/parsian-final`.
3. Activate **PARSIAN FINAL Core** on disposable staging only.
4. Check `/wp-json/parsian-final/v1/health` and verify the reported plugin/schema versions.
5. Create synthetic test users for manager, accountant and seller roles.
6. Run the guarded WP-CLI acceptance harnesses only against the isolated staging database:
   - `tests/finance-ledger-acceptance.php`
   - `tests/auth-sync-acceptance.php`
7. Rehearse rollback and backup restore before any production consideration.

## Known limitations — not production-ready
- Source implementation is not equivalent to successful WordPress/MySQL runtime validation. The acceptance harnesses and migrations still require staging execution.
- Bank CSV/XLSX parsing, duplicate-file/row handling, partial and many-to-many reconciliation require fixture and integration tests; opening/closing statement balance controls and period close remain outstanding.
- Accounting still needs full tax/discount treatment, returns/credit notes, period close and complete reconciliation acceptance.
- Sync pull still uses a numeric cursor and is not fully bound to per-customer assignment scopes. Aggregate-version conflict resolution, transactional processing/approval of queued operations, retention and retry policies are incomplete.
- Web panel currently provides read views for CRM customers/leads, proformas, trial balance, journals and bank accounts plus manager proforma approval. Create/edit, payments, cheques, reconciliation, full customer self-service and browser acceptance tests remain incomplete.
- Android flavors are scaffolded; encrypted local DB, token vault, outbox/inbox, WorkManager sync and business workflows remain incomplete.
- Windows clients, installer, signed release artifacts, backup/restore acceptance and end-to-end security tests are not complete.

Do not use real customer, cheque or bank data in test fixtures. Do not install this development branch on the production site.
