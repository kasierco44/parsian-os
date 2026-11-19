# ADR-0001: WordPress Core, Independent Clients, and Offline Synchronization

- Status: Accepted as the target architecture
- Date: 2026-11-18
- Scope: PARSIAN OS platform architecture

## Context
The business requires one central system delivered as a WordPress plugin, plus independent customer and seller Android applications, and accounting and management products each available both as web panels and standalone Windows software. All clients must remain useful during internet outages and synchronize after connectivity returns.

## Decision
1. The PARSIAN OS WordPress plugin on pgtparts.ir is the central application and system of record.
2. All clients communicate with the plugin through versioned HTTPS REST APIs. No client connects directly to the central database.
3. Four independent products are delivered: Android Customer, Android Seller, Accounting (Web/PWA + Windows), and Management (Web/PWA + Windows).
4. Each client maintains a local database and durable operation queue. Android uses SQLite/Room and WorkManager; web/PWA uses IndexedDB and Service Worker; Windows desktop uses SQLite and a desktop shell (Tauri is the current implementation choice, subject to a packaging spike).
5. Synchronization uses unique operation IDs, idempotency keys, server versions/delta cursors, retry with backoff, visible operation states, and explicit conflict handling.
6. Offline financial, inventory, pricing, credit, and approval-dependent actions are drafts or pending operations. They are not represented as server-confirmed until validated and accepted by the central system.
7. Existing WordPress plugin and Android source are preserved. Changes require source inspection, tests, and a rollback path; no speculative replacement is allowed.

## Consequences
- WordPress hosting capacity, PHP compatibility, database backup, API rate limits, and background sync scheduling must be validated.
- Windows and Android clients need device registration, revocable credentials, encrypted local storage, and update/version policies.
- Offline behavior must be designed per business operation; cached stock and balances are snapshots, not live truth.
- Shared UI components may be reused, but each product remains independently installable and role-scoped.
- Tauri is a proposed Windows packaging path and must be validated with a minimal installer and secure local storage proof of concept before committing to production.

## Acceptance criteria
- A client can create an allowed offline operation, close/reopen, and retain it in its local outbox.
- Reconnection uploads the operation once, even after retries.
- A client downloads only changes it is authorized to see.
- Conflicts and server rejections are visible and recoverable.
- No financial settlement, inventory movement, or approval is falsely shown as final while offline.
- Windows accounting and management each install and launch independently without requiring a browser session.
