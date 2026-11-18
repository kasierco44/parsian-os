# PARSIAN OS Integration Contract — WordPress, CRM, Sales and Finance

Status: design contract for the integration phase. This document does not claim that the WordPress plugin or production site is connected.

## 1. System of record

The FastAPI/PostgreSQL backend is the authoritative system of record for operational and financial data. WordPress is a presentation/integration client only. Android and future desktop clients call the same versioned API.

The standalone WordPress Finance 0.1.0 tables (`wp_parsian_suppliers`, `wp_parsian_supplier_bills`, `wp_parsian_supplier_payments`) are legacy/staging data stores. They must not receive live financial transactions after cutover. Do not dual-write to WordPress and PostgreSQL.

## 2. Canonical data ownership

| Domain | Authoritative backend resource | Main API |
|---|---|---|
| Identity / roles | users, roles, permissions | `/api/v1/auth/*`, `/api/v1/users` |
| CRM customers | customers + assigned user | `/api/v1/customers` |
| Product catalogue | products + private media | `/api/v1/catalog/*` |
| Sales | orders, order lines, invoices | `/api/v1/sales/orders`, `/api/v1/sales/invoices` |
| Customer receipts | receipts, cheques, journal entries | `/api/v1/sales/invoices/{id}/receipts`, `/api/v1/sales/cheques/*` |
| Supplier finance | suppliers, bills, payments | `/api/v1/suppliers/*` |
| Accounting | chart of accounts, journal entries/lines | `/api/v1/sales/accounting/*` |
| Inventory | balances and movements | `/api/v1/sales/inventory/*` |
| Commission | tiers and accruals from cleared collections | `/api/v1/sales/commission/*` |

Money is stored as integer Rial in the current API contracts. UI conversion to Toman must be explicit and must never change the stored amount. Dates are ISO Gregorian in API payloads; Jalali display/input belongs in the UI layer.

## 3. Cross-module transaction rules

1. An order is a draft until submitted and manager-approved.
2. A final invoice is issued only from an approved order.
3. Invoice issuance, stock deduction, cost-of-goods posting, and journal entry must commit or roll back as one database transaction.
4. Customer receipt, invoice paid amount, journal entry, and collection-based commission accrual must commit or roll back together.
5. A received cheque is not a cleared collection. It affects cheque receivable status but must not accrue collected-sales commission until cleared.
6. Supplier bill and its balanced journal entry are one transaction.
7. Supplier payment and its balanced journal entry are one transaction. An issued supplier cheque moves the liability from accounts payable to cheques payable; it is not a cash/bank payment until cleared.
8. Every retryable write endpoint must require a unique `Idempotency-Key`; reusing a key with different business data must return HTTP 409.
9. All writes must be attributed to the authenticated backend user and recorded in the audit log. Clients must not be allowed to submit an arbitrary actor ID.
10. No client or WordPress plugin may write directly to PostgreSQL or another module's tables.

## 4. WordPress integration boundary

The installed WordPress Finance 0.1.0 plugin is currently a standalone UI with its own WordPress tables and WordPress capability checks. It is not yet an API client for the central backend. Its supplier/bill/payment data must therefore be treated as test-only until the adapter is implemented and validated.

The adapter must:
- use HTTPS and a server-side credential/token flow; never expose backend credentials or long-lived secrets in browser JavaScript;
- map each WordPress staff account to one backend user UUID; do not infer a backend identity from a caller-supplied header;
- enforce the same backend permissions on every request; hiding controls in WordPress is not authorization;
- proxy backend supplier, bill, payment, and summary resources without writing duplicate WordPress financial records;
- use idempotency keys for all financial POST requests;
- preserve backend UUIDs and document numbers in the WordPress UI;
- handle 401/403/409/422/5xx responses without silently retrying or showing a false success;
- keep API base URL and credentials in server-side WordPress options/environment configuration, never in page source.

Preferred initial connection is a dedicated backend integration principal with narrowly scoped finance permissions and an explicit, audited mapping from the authenticated WordPress user to a backend user. The backend must verify that mapping; the WordPress client cannot assert it unilaterally. If this identity bridge is not available, the adapter must use individual backend user authentication rather than a shared manager account.

## 5. Existing WordPress data migration

No automatic import is authorized. Before migration:
1. Export and checksum the WordPress supplier, bill, payment, and audit tables.
2. Take and verify a full WordPress files + database backup.
3. Produce a dry-run mapping report: WordPress integer IDs to backend UUIDs, duplicate supplier names/codes, unmatched supplier references, amount/date normalization, and cheque statuses.
4. Resolve duplicates and confirm whether each amount is Rial or Toman.
5. Import suppliers and bills first, then payments, preserving original document references and provenance.
6. Reconcile per-supplier opening balance, bills, cleared payments, issued cheques, bounced cheques, and the general ledger.
7. Obtain explicit manager approval before cutover.
8. Keep the original export read-only for audit; do not delete source records.

## 6. Integration acceptance tests

- WordPress manager can view/create only resources allowed by backend manager permissions.
- WordPress accountant can view/create supplier bills and payments but cannot post supplier opening balances or approve sales orders.
- Seller cannot see supplier finance, internal cost price, stock balances, or other sellers' assigned customers.
- Duplicate payment submission with the same idempotency key creates one payment and one journal entry.
- Same key with a different amount or supplier/invoice returns 409.
- A cheque in issued/received state is excluded from cleared cash collections and collection-based commission.
- Clearing/bouncing a cheque creates the correct journal reversal/settlement exactly once.
- A final invoice updates stock and ledger exactly once.
- All financial entries balance: total debit equals total credit.
- A WordPress/API outage never creates a local-only successful financial posting.
- Backup restore and reconciliation pass in staging before production cutover.

## 7. Delivery sequence

A. Freeze WordPress Finance plugin writes to test data; retain read-only access for inspection.
B. Implement and test backend integration identity and WordPress server-side API adapter.
C. Replace the WordPress plugin's local finance reads/writes with backend API calls.
D. Run migration dry-run and financial reconciliation on a staging copy.
E. Connect CRM/sales views to the same backend identity and customer/order resources.
F. Connect production/inventory workflows only after product, unit, warehouse, BOM, and cost rules are confirmed.
G. Cut over only after manager acceptance, verified backups, and rollback rehearsal.

## 8. Explicit non-goals of this contract

This document does not perform production deployment, create credentials, migrate data, merge pull requests, or certify the current installation as production-ready. Those require separate implementation and acceptance evidence.
