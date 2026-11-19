# PARSIAN FINAL — Client Platform Implementation Plan

Status: architecture and acceptance contract; implementation not yet accepted
Date: 2026-11-19
Branch: feature/parsian-final-core-platform
PR: #21 (Draft)

## Non-negotiable platform boundary

- The WordPress PARSIAN FINAL REST API is the sole system-of-record boundary for all clients in this delivery architecture.
- No Android, browser, PWA, or Windows client may connect directly to MySQL or PostgreSQL.
- Every endpoint enforces authorization and record scope on the server. Hiding a screen or button is not authorization.
- Client-side financial, cheque, stock, approval, and invoice actions remain drafts until the server validates and commits them.
- Offline snapshots show their last synchronization time and are never represented as current balances or available stock.

## 1. Offline synchronization protocol

### Durable local data
Each client maintains an encrypted local database and a durable outbox. Every queued operation contains:
- operation_uuid (client-generated UUID, globally unique)
- device_uuid and authenticated user identity
- aggregate_type and aggregate_id (when editing an existing record)
- operation_type and schema_version
- base_server_version (optimistic concurrency token)
- payload and payload_sha256
- client_created_at and retry metadata

The outbox record is committed to local storage before the UI reports that an operation is queued. It survives process termination, device restart, and network loss.

### Server acceptance and idempotency
- The server enforces a unique constraint on operation_uuid and binds it to the submitting user and device.
- The server stores a canonical payload hash. Reuse of an operation_uuid with a different payload is rejected as an idempotency conflict; it must not silently return the first result.
- Push responses are per-operation: accepted, pending_approval, applied, conflict, rejected, or retryable_error.
- A retry with the same UUID and same payload returns the original operation result and never repeats its business effect.
- Server business writes and the operation result must commit atomically in one database transaction.
- Batch processing is bounded and each operation has an independently recoverable outcome.

### Delta pull and cursors
- Pull uses an opaque, server-issued cursor tied to the authenticated principal and permitted data scope, not a client-supplied global row ID.
- The cursor advances only across events the caller is authorized to receive; pagination must not skip events.
- Events are versioned and contain aggregate version, event ID, schema version, and tombstone information for deletes.
- The server re-checks the user's current role, customer assignment, and device revocation for every push and pull request.

### Conflict policy
- CRM notes and follow-up text: allow field-level merge where safe; otherwise return both server and client versions for user resolution.
- Customer ownership, credit limits, prices, product master data, stock, invoice, payment, cheque, approval, and accounting records: server-authoritative; stale base versions return a conflict and never overwrite newer values.
- Financial and inventory actions are never auto-merged.
- Conflict resolutions create new auditable operations; original operations remain immutable.

### Retry and operations
- Exponential backoff with jitter, bounded retry windows, and a visible dead-letter/retry state.
- Connectivity changes trigger WorkManager/background sync on Android and a controlled queue worker on Windows/web.
- User can inspect pending, synced, rejected, and conflicted items; retry must preserve the original operation UUID and payload.
- Device revocation blocks subsequent push/pull and triggers local session/key invalidation on next contact.

### Acceptance tests
- Kill the app after local enqueue; verify operation survives restart.
- Submit identical operation 10 times; verify exactly one business effect.
- Reuse UUID with changed payload; verify hard conflict.
- Simulate timeout after server commit; retry and verify original result without duplicate posting.
- Submit stale versions concurrently from two devices; verify deterministic conflict and no lost update.
- Revoke device/session; verify push and pull are rejected.
- Change seller customer assignment; verify the next pull no longer returns unassigned customer data.
- Paginate large event streams; verify no missing or duplicate event IDs.

## 2. Secure mobile identity and device sessions

- Short-lived access token; rotating refresh token stored hashed server-side and bound to user/device/session.
- Refresh token rotation detects reuse; reused or revoked token family is invalidated.
- Server-side session and device registry supports per-device revoke, all-device logout, expiry, and audit.
- Login throttling, generic credential errors, HTTPS-only transport, secure password policy, and server-side role checks.
- Android stores secrets in Android Keystore-backed encrypted storage; Windows uses OS-protected credential storage.
- Do not place tokens in logs, analytics, crash reports, URLs, or ordinary preferences.
- Cookie-based WordPress browser login remains separate from native mobile token authentication.

## 3. Role-specific panels

### Manager
- Sales and collection dashboard; configurable seller targets.
- Customer and seller assignment; user/device/session management.
- Proforma approval, pricing/credit controls, stock visibility, audit trail.
- Financial reports, bank reconciliation, backups, and operational alerts.

### Accountant
- Customer/supplier subledgers, journals, trial balance, aging and statements.
- Payments, cheques, bank statement import/matching, reconciliation and period controls.
- Read/write access only to finance capabilities explicitly granted; no user/role administration by default.

### Seller CRM
- Assigned customers only, leads, follow-ups, visit notes, tasks, proformas and collection drafts.
- No stock quantities, valuation, supplier finance, other sellers' customers, or manager reports unless an explicit capability grants access.
- Seller target and commission visibility only for their own permitted scope.

All panels are Persian, RTL, Jalali-date aware, Persian-digit capable, and display amounts in toman. All API errors, loading, empty, offline, stale-data and conflict states must be designed.

## 4. Android applications

### Seller app
- Assigned-customer CRM, lead capture, visits, tasks, follow-ups, product catalogue snapshot, proforma drafts and payment/cheque collection drafts.
- Room/SQLite encrypted at rest, WorkManager durable outbox, Android Keystore token storage.
- No warehouse access by default.

### Customer app
- Customer identity and approved profile, catalogue, own orders/proformas/invoices, own payment status, support and notifications.
- Customer-scoped access enforced by server; no other customer's data.
- Offline catalogue and own-document snapshots with explicit last-updated labels.

Build requirements:
- Kotlin, maintained Android Gradle Plugin, minimum Android version declared after device compatibility review.
- Unit, repository, API contract, offline/replay, security and UI tests.
- Signed APK for controlled testing and signed AAB for Play distribution only after release approval.

## 5. Windows applications

- Shared API/domain contracts with web/mobile; no direct database access.
- Prefer a Tauri desktop shell with a web UI after a technical proof of concept; use SQLite encrypted/protected at rest and OS credential vault.
- Manager and accountant roles may be installed as separate role-aware workspaces; seller CRM may run as a restricted workspace.
- Offline queue follows the same operation UUID, payload hash, version, retry and conflict contract.
- Installer must support upgrade, uninstall without deleting business data by default, signed binaries, logs without secrets, and documented backup/restore.

## 6. Delivery sequence and release gates

1. Finish bank reconciliation and complete financial subledger/aging acceptance tests.
2. Implement and test mobile token lifecycle, session revocation and device registry.
3. Implement server idempotency hash, transactional sync operation processing, opaque scoped cursors, version checks and conflict APIs.
4. Implement encrypted local outbox/inbox and sync workers; pass offline/replay/conflict test suite.
5. Build role-aware web panels against the versioned API.
6. Build Android seller and customer apps; test on physical Android devices.
7. Build Windows proof of concept, then role workspaces and signed installer.
8. Staging installation, migration/rollback, backup/restore, penetration/security review, end-to-end acceptance and signed release artifacts.



## Current implementation snapshot (2026-11-19)

### WordPress authentication and sync source
- Native auth routes have been added: login issues opaque 15-minute access and 30-day refresh tokens; token hashes are stored server-side; refresh rotation, token-family logout, device revocation, and Bearer user resolution are implemented in source.
- Sync push persists an allowlisted operation with payload hash, accepts identical retries idempotently, and rejects changed-payload reuse. Accepted offline operations remain pending review and are not automatically posted to finance, inventory, or sales.
- Sync pull still uses a numeric event cursor and its scope is not yet assignment-aware for every domain. Aggregate-version conflict resolution, transactional command application/approval, complete client outbox/inbox, retry scheduling, and client-side revocation handling remain incomplete.
- WP-CLI acceptance harnesses exist for auth/sync and finance/ledger, but neither has been executed against a staging WordPress/MySQL database. Source presence is not runtime validation.

### Web client status
- An initial role-aware Persian RTL web shell now exists at `web/parsian-panels/`. It checks the public health endpoint and reads the current user's identity/capabilities from `/me`.
- This is a UI scaffold only. The operational manager, accountant and seller workflows, nonce injection by the WordPress host, browser tests, and staging deployment remain to be completed.

No Android APK, Windows installer, production deployment, or end-to-end offline-sync acceptance is currently claimed.


### Android implementation status (2026-11-19)
- The initial Gradle project uses separate seller/customer product flavors and role-specific Compose launch screens.
- Room, WorkManager, Retrofit and OkHttp dependencies are declared, but encrypted local persistence, token vault, API repositories, durable outbox/inbox, retry worker, and business workflows remain to be implemented.
- A GitHub Actions workflow attempts both debug APK variants. Until its run succeeds and artifacts are inspected, no APK is considered built or delivered.
