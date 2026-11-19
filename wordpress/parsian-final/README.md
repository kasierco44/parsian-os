# PARSIAN FINAL — WordPress Core

Central WordPress plugin source for PARSIAN FINAL. Development source version: **0.4.25**.

## Architecture

- WordPress REST API is the single system-of-record boundary for web, Android and Windows clients.
- Clients never connect directly to MySQL.
- Every write is guarded by server-side capabilities, domain validation, record scoping and audit events.
- Install and test on an isolated Staging site only. Production remains untouched until migrations, rollback, security and acceptance tests pass.

## Implemented source areas

- Versioned dbDelta schema for CRM, sales, finance, ledger, inventory, bank, cheque, token, device and sync tables; base chart-of-accounts seed.
- Roles and capabilities for manager, accountant, seller and customer.
- WordPress-hosted role-aware web workspace with bundled assets and REST nonce; Windows Tauri bearer-token login uses configured CORS origins and Windows Credential Manager.
- CRM customer/lead endpoints with seller assignment scoping; manager-only seller assignment and customer-account linking.
- Proformas, manager approval, invoice conversion and balanced sales/receivable journal posting.
- Payment register, approval, allocations, party statements and customer portal read views.
- Double-entry journals, trial balance, party subledger and aging report.
- Inventory movement approval, stock ledger, reservation and fulfillment/COGS foundations.
- Cheque lifecycle and event history.
- Bank account register, CSV/XLSX statement import, statement-line views, one-to-one and grouped reconciliation allocation APIs.
- Opaque access/refresh tokens, rotation, token-family revocation, device registration and device revocation.
- Offline sync outbox/inbox API, idempotency hash, delta event pull, manager review queue, offline lead create/update, task completion and proforma draft approval. These flows are source-level implementations and still require isolated Staging acceptance tests.
- Manager-only JSON database snapshot export with SHA-256 validation (non-destructive; not a restore system).

## Important API routes

Base: `/wp-json/parsian-final/v1`

- Identity: `GET /health`, `GET /me`, `POST /auth/login`, `POST /auth/refresh`, `POST /auth/logout`, `POST /auth/revoke-device`
- Sync: `GET|POST /sync/devices`, `POST /sync/push`, `GET /sync/pull`, `GET /sync/operations`, `POST /sync/operations/{id}/approve`, `POST /sync/operations/{id}/reject`
- CRM: `GET /customers`, `GET /leads`, `POST /customers/{id}/assign-seller`, `POST /customers/{id}/link-user`
- Sales: `POST /proformas`, `GET /proformas`, `GET /proformas/{id}`, `POST /proformas/{id}/approve`
- Finance: `GET /payments`, `POST /payments`, `POST /payments/{id}/approve`, `GET /cheques`, `GET /ledger/journals`, `GET /ledger/trial-balance`, `GET /ledger/party-statement`
- Bank: `GET /bank/accounts`, `POST /bank/import`, `GET /bank/statement-lines`, `GET /bank/reconciliation-summary`, `GET /bank/journal-candidates`, `POST /bank/reconcile`
- Customer portal: `GET /portal/overview`, `GET /portal/invoices`, `GET /portal/proformas`, `GET /portal/payments`
- Backup: `GET /backup/export`, `POST /backup/validate`

## Staging acceptance

Follow `docs/STAGING_ACCEPTANCE_RUNBOOK.md` before running migrations or fixture tests. The runbook requires a Staging database backup, full PHP lint, schema verification, both WP-CLI acceptance harnesses and a REST health check.

## Known limitations — not production-ready

- The latest schema source has not yet been executed against Staging. Whole-plugin PHP lint and both WP-CLI acceptance harnesses still need a verified pass.
- No APK or Windows MSI/NSIS installer is confirmed. Android and Windows builds, clean-device/VM installation, update, uninstall and signing tests remain outstanding.
- Android has encrypted Room outbox/inbox, token storage, seller/customer read views, offline lead create/update, task completion and proforma draft entry. Aggregate-version conflict resolution, robust retry/dead-letter handling and full business workflows remain incomplete.
- Windows Tauri has remote bearer authentication and Credential Manager storage but no local encrypted SQLite outbox/inbox or full offline workflow yet.
- The manager review queue has handlers for `crm.lead.create`, `crm.lead.update`, `crm.task.complete` and `sales.proforma.draft`. Runtime acceptance on an isolated Staging database is still outstanding.
- Bank statement upload and reconciliation APIs/UI exist, but CSV/XLSX fixtures, opening/closing balance controls and end-to-end matching tests remain outstanding.
- Accounting still needs full tax/discount treatment, returns/credit notes, period close and full COGS/stock valuation acceptance.
- Backup export is limited to 10,000 rows per table and 20 MiB; it excludes WordPress uploads/media, credentials and device tokens. Validation does not restore data. Full backup requires host-level database plus files backup.

## Safety

Do not install this development build on Production, do not merge PR #21, and do not treat source commits or a green syntax check as proof of operational readiness.


### Offline task and proforma workflow (0.4.25)
- Added task storage and manager assignment endpoint (`POST /tasks`); sellers can list only their assigned tasks (`GET /tasks`).
- Manager-approved offline `crm.task.complete` updates the assigned task in one transaction and emits a delta-sync event.
- Manager-approved offline `sales.proforma.draft` validates customer assignment, active products, quantities and amounts, creates a draft proforma only, and records its event and operation result transactionally. It does not issue an invoice or reserve stock.
