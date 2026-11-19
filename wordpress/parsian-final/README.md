# PARSIAN FINAL — WordPress Core

Initial central-platform implementation scaffold for the PARSIAN FINAL suite.

## Implemented source modules
- Activation-time custom schema: audit events, registered devices, sync operations, customers, leads, payments, cheques, products and stock movements.
- Manager, accountant, seller and customer roles with capability registration.
- REST endpoints:
  - `GET /parsian-final/v1/health` — public service health.
  - `GET /parsian-final/v1/me` — current user and granted capabilities.
  - `POST /parsian-final/v1/auth/login`, `POST /auth/logout` — WordPress cookie/session based login/logout (not mobile bearer-token auth).
  - `GET /customers`, `POST /leads`, `POST /proformas` — early CRM endpoints. Proforma currently validates a draft payload only.
  - `POST /payments`, `POST /cheques`, `GET /cheques` — records finance entries as pending; no ledger posting.
  - `GET /inventory/items`, `POST /inventory/movements` — stock movement requests remain pending; no stock balance mutation.
  - `POST /sync/devices`, `POST /sync/push`, `GET /sync/pull` — early authenticated device enrollment and sync queue.
- Deactivation intentionally preserves business data.

## Staging-only setup
1. Copy this directory to `wp-content/plugins/parsian-final`.
2. Activate **PARSIAN FINAL Core** on a disposable staging WordPress site.
3. Check `/wp-json/parsian-final/v1/health`.
4. Assign a test user a PARSIAN role and verify `/wp-json/parsian-final/v1/me`.
5. Exercise write endpoints only with synthetic test data.

## Known gaps — not production-ready
- Mobile/desktop bearer token issuance, refresh, revocation and secure device attestation are not implemented.
- Browser cookie authentication needs standard WordPress REST nonce/CSRF protections and HTTPS.
- Login throttling is a basic transient counter, not a hardened distributed rate limiter.
- CRM assignment model and customer-level query scoping require completion and tests.
- Proforma persistence, manager approval, final invoice conversion and immutable audit trail are not implemented.
- Finance posting, double-entry ledger, reconciliation, cheque lifecycle and supplier subledger are not implemented.
- Inventory balances, reservation, valuation and approval posting are not implemented.
- Sync pull currently exposes event records only; domain change events, per-user filtering, cursor signatures and conflict resolution need implementation.
- Device enrollment is account-authenticated but does not yet implement a separate manager approval/attestation process.
- Automated PHP lint, WordPress integration tests, security tests, staging installation, backup/restore and release packaging have not yet been run.

Do not install on production or use real customer/financial data until the listed controls and acceptance tests are complete.


## Sales and ledger workflow (v0.2.0 development)
- POST /proformas persists a draft proforma and its validated line items for an assigned active customer.
- GET /proformas, GET /proformas/{id} list and retrieve documents within the caller's scope.
- POST /proformas/{id}/approve requires the manager approval capability; it locks the draft, issues one final invoice, copies invoice lines, posts a balanced receivable/sales journal, and changes the proforma state in one database transaction.
- POST /payments/{id}/approve posts customer receipts or supplier payments to the general ledger after finance approval. Cash, bank/card and cheque clearing/payable accounts are mapped to the baseline chart.
- GET /ledger/journals and GET /ledger/trial-balance expose posted journal data to finance-authorized users.
- A version-gated schema upgrade creates the new sales and ledger tables and seeds baseline accounts on the next plugin load.

Accounting scope note: the current invoice journal recognizes gross sales and customer receivables only. Tax, discounts, COGS/inventory valuation, returns/credit notes, settlement reconciliation and period closing are not implemented. Issued invoices do not decrement stock. Payment approval is not a substitute for bank reconciliation.
