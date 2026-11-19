# PARSIAN FINAL — Delivery Status

Last reviewed: 2026-11-19
Repository: `kasierco44/parsian-os`
Working branch: `feature/parsian-final-core-platform`
Pull request: #21 (draft; not merged)

## Important release statement

This repository state is a development baseline, not a production release. No production WordPress site has been modified. Do not install this branch on the live site or treat it as accounting, inventory, or offline-sync production software until the release gates below pass.

## Latest engineering updates (2026-11-19)

- Sync pull now scopes non-manager/non-accountant event streams to the authenticated actor; operation payload size and cross-user duplicate UUID handling are checked, and device last-seen is updated. This is an initial mitigation, not a complete tenant/assignment-aware sync model.
- Inventory movement requests now require an active product and positive bounded quantity, use pagination for item lists, and emit an audit event. They remain pending and do not change stock balances.
- Added GitHub Actions workflows for PHP syntax lint and development ZIP packaging. Their runs have not yet been observed; no successful lint or artifact is claimed.

## Sales and ledger implementation update (2026-11-19)

- Added persisted proforma and line-item tables, invoice and invoice-line tables, chart of accounts, journal headers and journal lines.
- Added seller-scoped draft creation/list/detail and manager-only approval. Approval locks the proforma, creates exactly one invoice, posts balanced debit receivables / credit sales journal lines, records audit events, and changes document state within one database transaction.
- Added finance-authorized payment approval for customer receipts and supplier payments, with balanced journal posting and idempotent posted-state response.
- Added journal register, trial-balance and customer/supplier party-statement read endpoints.
- Added version-gated schema upgrade and baseline chart-of-account seeding.
- PHP lint passed and a development ZIP package was built by GitHub Actions for the prior code head; rerun workflows for this latest schema-upgrade commit before distributing.

Accounting limitations remain: tax, discounts, cost-of-goods-sold, stock decrement/valuation, returns/credit notes, reconciliation and period close are not implemented.

## Implemented in this branch

- WordPress plugin bootstrap and activation-time schema creation.
- Initial roles and capability definitions for manager, accountant, seller, and customer.
- Initial REST endpoints for health, identity, CRM leads/customers, finance records, inventory requests, and sync device/operation registration.
- CRM customer assignment filtering is performed in the SQL query for seller-level users.
- Seller lead listing is scoped to the current owner; lead creation emits an audit/sync event.
- Payment and cheque inputs receive stricter validation; pending payment/cheque creation emits audit/sync events.
- Architecture and roadmap documents define WordPress as the single system of record, API-only clients, and an offline outbox/inbox approach.

## Verified limitations

- No WordPress staging installation or WordPress integration test has been performed for this branch.
- No PHP lint/security test result is available for the latest plugin changes.
- The proforma endpoint validates a draft payload only; it does not persist a proforma, implement manager approval, create a final invoice, or post accounting/inventory entries.
- Payment and cheque records remain pending; there is no double-entry general ledger, reconciliation, supplier subledger, cheque lifecycle, or approval/posting endpoint yet.
- Inventory movements are pending requests; stock balances, reservations, valuation, and posting are not complete.
- Sync endpoints are an initial scaffold; per-user delta filtering, signed cursors, full durable client outbox/inbox, conflict resolution, and retry/backoff are not complete.
- Mobile access-token/refresh-token lifecycle and revocation are not implemented; current authentication is WordPress cookie/session oriented.
- Customer and lead management screens, accountant/admin web panels, Android customer app, Android seller app, Windows desktop apps, and PWA clients are not complete in this branch.

## Android build workflow finding

The GitHub Actions run associated with the remote-sales Android workflow failed before dependency installation or Gradle build. The log shows the runner could not enter `mobile/remote-sales-crm` because that directory does not exist in the checked-out revision. Consequently, no APK was built and no APK artifact was uploaded. This is a repository/workflow-path issue, not evidence of a successful or failed Gradle compilation.

## Release gates

1. Finish and test plugin schema migrations, REST validation, authorization, audit behavior, and idempotency.
2. Implement persisted proforma lifecycle and manager approval; final invoice conversion must be atomic and auditable.
3. Implement double-entry ledger, supplier/customer subledgers, payment/cheque lifecycle, and reconciliation with tests.
4. Implement stock ledger, reservations, stock valuation, and approval/posting controls.
5. Complete mobile token/device security and sync conflict/replay protections.
6. Build and test all web/mobile/Windows clients against the versioned API.
7. Run PHP lint, automated unit/integration tests, security review, staging install/upgrade/rollback, backup/restore, and acceptance tests.
8. Produce signed/versioned release artifacts (installable WordPress ZIP, Android APK/AAB, and Windows installer) and verify each artifact download.

## Production safety

Keep the existing live site and its database untouched until a staging backup, migration rehearsal, rollback rehearsal, and explicit acceptance test are complete.


## Verification snapshot (2026-11-19)

- PHP lint workflow completed successfully for code head `58bfcb54fcd91dd31ddb81383d204a7a499433e8`.
- Development WordPress ZIP packaging workflow completed successfully for the same code head. Artifact: `parsian-final-wordpress-plugin-dev` (GitHub Actions run `44462986920`; artifact expires 2026-12-03).
- The branch head has since advanced with documentation-only commit `a0ad4e2b82dcfe867e20dcb2a2ae174d483f0c41`; the plugin source included in the artifact matches the latest plugin code commit.
- Android workflow still fails before Gradle because its configured working directory `mobile/remote-sales-crm` is absent from this branch. No APK artifact exists.
- ZIP creation and PHP lint are verified; WordPress staging install, runtime/API integration, accounting acceptance tests, and backup/restore are not verified. Treat the ZIP as a development package only, not a production-ready accounting release.


## Latest verification after party subledger endpoint

- PHP lint: passed, GitHub Actions run `44463175271`, code head `eb4263e5e00e9f5125eeb29d7f87622d5d1dab74`.
- Development ZIP: built and uploaded successfully, GitHub Actions run `44463175263`, artifact `parsian-final-wordpress-plugin-dev`, artifact ID `14501425379`, SHA-256 `25c2d672c0a63c7a940bdbd067d27231c21f2d1d1449895e83495db51e4e2498` (expires 2026-12-03).
- This confirms PHP syntax and packaging only. No live/staging WordPress runtime test or finance acceptance test has been run.


## Inventory implementation update (2026-11-19, plugin schema 0.3.0)

- Added append-only stock ledger and invoice-linked stock reservation tables.
- Stock item responses now include on-hand, reserved, available, inventory value and weighted-average unit cost.
- Inventory movements remain pending until an authorized user approves them. Incoming movements require a supplier identifier and unit acquisition cost; outgoing movement cost is calculated from the current weighted average.
- Approved movements write a stock-ledger entry and a balanced journal entry (inventory/AP for receipts; expense/inventory for manual issues).
- Added invoice reservation, incremental reservation, reservation release and invoice fulfillment endpoints.
- Invoice fulfillment requires the full invoice quantity to be reserved, consumes the reservation, posts weighted-average COGS, credits inventory and debits COGS, and marks the invoice fulfilled in one database transaction.
- Added inventory valuation report and GL accounts 1300 (inventory) and 5100 (cost of goods sold).
- Plugin version/schema version bumped to 0.3.0. Existing schema changes use dbDelta and guarded column migrations.

### Inventory API added
- POST /parsian-final/v1/inventory/movements/{id}/approve
- POST /parsian-final/v1/inventory/reservations
- POST /parsian-final/v1/inventory/reservations/{id}/release
- POST /parsian-final/v1/inventory/invoices/{id}/fulfill
- GET /parsian-final/v1/inventory/valuation

### Verification and remaining controls
- GitHub Actions PHP lint passed on code head 7b1a6c65321e68b43dfb82d4ee9ceef445ff60c0 (run 44464189870).
- PHP lint is syntax validation only. No WordPress staging install, DB migration execution, endpoint integration tests, concurrency tests, or inventory acceptance test has been run.
- Stock supplier identifiers are currently numeric references; supplier master-data validation and purchase-order matching remain to be connected.
- This is weighted-average inventory costing, not FIFO. Tax, landed-cost allocation, returns, stock counts/adjustments with approval policy, multi-warehouse locations, and automated reservation on invoice approval remain follow-up work.
- No Android APK, Windows installer, or production release is claimed.


## Customer receipt allocation update

- Added a payment-allocation table linking posted incoming customer receipts to posted invoices.
- Added allocation endpoint with transaction locks and limits against both unallocated receipt value and remaining invoice balance.
- Incremental allocations to the same payment/invoice pair are supported.
- Added invoice balance and customer outstanding endpoints.
- Allocation is a subledger operation only: it intentionally does not create another GL journal because the receipt has already credited trade receivables when posted.
- Invoice status is not overwritten by collection status; inventory fulfillment remains an independent state transition.
- PHP lint/package workflows on the newest finance commits reported failure before producing runner step details (job logs unavailable). The last confirmed successful PHP lint and development package were on code head 7b1a6c65321e68b43dfb82d4ee9ceef445ff60c0, before the payment-allocation change. Re-run validation is required; do not treat the newer commits as verified.


## Cheque lifecycle and bank reconciliation foundation (2026-11-19)

- Added cheque event history table and bank statement line table, plus safe schema migrations for cheque party/payment linkage and lifecycle metadata.
- Bumped WordPress plugin/schema version to 0.4.0 and registered the cheque/reconciliation service.
- Cheque creation now requires explicit party mapping (received/customer; issued/supplier); optional payment linkage is validated against a posted cheque-method payment with matching direction, party and amount.
- Added explicit state transition endpoint and append-only cheque event history.
- Supported received-cheque states: pending -> deposited -> cleared/bounced, with return paths. Supported issued-cheque states: pending -> issued -> presented -> cleared/bounced, plus cancellation before presentation.
- Clearing a received cheque transfers value from cheques receivable (1220) to bank (1210). Bouncing/returning a deposited received cheque restores customer receivable (1100) against cheque receivable (1220).
- Clearing an issued cheque settles cheque payable (2200) against bank (1210). Bouncing/cancelling an issued cheque restores supplier payable (2100) against cheque payable (2200).
- All financial state transitions use row locks, a cheque event ID as the unique journal source, and an audit event in the same DB transaction.
- Added manual bank statement line intake, exact amount/direction matching to a posted journal's bank account (1210), duplicate-match protection and reconciliation summary.
- API routes: POST /cheques/{id}/transition; GET /cheques/{id}/history; POST/GET /bank/statement-lines; POST /bank/statement-lines/{id}/match; GET /bank/reconciliation-summary.

### Current limitations (do not treat as full bank reconciliation release)
- Statement intake currently accepts one normalized line per request; CSV/Excel upload parsing, bank-account master data, statement opening/closing balances, fees/interest mapping, split/combined transaction matching and period close are not implemented.
- Matching is exact amount + direction against one posted journal entry on ledger account 1210. It does not yet support partial or many-to-one matches.
- Cheque transition endpoints are implemented but no live WordPress database, bank sandbox, or integration acceptance tests have been executed.
- Payment allocation, cheque lifecycle, and bank reconciliation still require end-to-end tests for duplicate requests, concurrent transitions, bounced/returned scenarios, and GL/subledger tie-out.


### Cheque lifecycle hardening update

- Added a link-payment endpoint for pending cheques that were initially recorded before their posted payment; it validates amount, direction, method, party and posted status.
- A cheque cannot be deposited/issued, cleared, bounced, returned from a posted state, or cancelled after issue without a matching posted cheque payment.
- Returning a received cheque (whether before or after deposit), bouncing it, bouncing an issued cheque, or cancelling an issued cheque creates a reversing journal and marks the linked payment reversed with reversal timestamp and event reference.
- Payment reversal is recorded against the cheque transition event; the original journal remains immutable.
- Remaining: explicit cheque replacement/reissue workflow, endorsement/transfer, partial cheque collections, bank charges and a formal bank-account master/batch import workflow.

### CI status after finance lifecycle changes
- The GitHub Actions PHP lint and plugin packaging runs triggered by the latest commits returned failure before exposing any runner steps or job logs (logs_url is null). This is not evidence of a PHP syntax error or a successful check; CI execution is currently inconclusive and requires runner/workflow diagnostics.
- No ZIP artifact from the current 0.4.0 branch has been verified. The earlier development ZIP belongs to an older code head and must not be used as the current build.


### Allocation and reversal consistency

- Payment allocations now carry active/reversed state and reversal event metadata.
- Invoice balances and customer outstanding reports count active allocations only.
- When a linked cheque is bounced, returned or cancelled, the corresponding payment is marked reversed and its active invoice allocations are reversed in the same transaction as the cheque reversal journal.
- Added pending-cheque payment linking for workflows where the cheque record is entered after the payment journal.
- This closes the key subledger error where a bounced cheque could otherwise leave an invoice incorrectly appearing paid.


## Party ledger and receivables aging update (2026-11-19)

- Extended GET /parsian-final/v1/ledger/party-statement with from_date/to_date filters, opening balance before the selected period, period debit/credit totals, closing balance, stable chronological running balance, and page/per_page pagination.
- Added total item/page counts to the party statement response.
- Added GET /parsian-final/v1/ledger/receivables-aging with invoice-level outstanding balances grouped into 0–30, 31–60, 61–90 and over-90-day buckets.
- Aging currently measures elapsed days since invoice issue date because the invoice schema does not yet contain a contractual due_date. It must not be interpreted as overdue aging until due-date support is added.
- Corrected trial balance aggregation so journal lines belonging to non-posted entries are excluded from debit/credit totals.
- This update is source-level only at this point. PHP lint, migration/runtime checks, database-backed statement tests, and aging tie-out are still required before this module is accepted.


## Due-date based receivables aging update (2026-11-19)

- Added nullable due_date fields to proformas and invoices, including guarded upgrade migrations for existing WordPress installations.
- Proforma creation accepts an optional validated YYYY-MM-DD due_date; manager approval carries that date to the final invoice.
- Receivables aging now uses days past due, with buckets: not due, 1–30, 31–60, 61–90, and over 90 days.
- Legacy invoices without a due_date use issued_at date as a clearly disclosed fallback. Their aging is therefore an estimate until due dates are backfilled.
- These code changes have not yet passed PHP lint or database migration/integration tests. The latest Actions workflow run available for the ledger change is not present; a prior lint attempt failed without runner logs. Do not treat the change as validated.


## Receivables aging correction (2026-11-19)

- Fixed the customer-wide receivables aging totals to aggregate the same five buckets returned by the per-customer aging calculation: not due, 1–30, 31–60, 61–90, and over 90 days.
- Corrected the report basis metadata to state that aging uses invoice due date, falling back to issue date when due date is absent.
- Commit: 8579ef3b23c47daad7a45d0289c2660d2ccd59a2.
- This is a targeted logic correction only. It has not yet been validated against a WordPress database or automated finance fixtures.

## Remaining bank reconciliation work

- Current bank statement intake is one normalized statement line per request; no CSV/Excel batch parser or import-batch audit trail exists yet.
- Current matching permits one statement line to match one journal entry only. One-to-many and many-to-one allocations, bank account master records, opening/closing balance controls, bank fees/interest mappings, and period close remain outstanding.
- Before production use, cheque/payment reversals, statement matching, and customer aging must pass integration tests against the actual schema and representative transaction fixtures.


## Bank reconciliation schema foundation (2026-11-19)

- Added bank account master table with stable account reference, masked account number, linked ledger account, opening balance/date, currency, and active flag.
- Added import batch tracking with file SHA-256, row counters, status, and importing user.
- Added reconciliation match-allocation table keyed by statement line and journal entry, with match-group UUID and allocated amount. This schema permits a future many-to-many matching workflow.
- Added bank account and import batch references to existing statement lines through guarded ALTER TABLE migrations.
- Commit: e77f09145165578130439fbe6e3f1b7e96a5ab76.
- Important: this commit is schema groundwork only. Bank account CRUD, CSV/XLSX parsing, allocation validation/settlement endpoints, opening/closing balance verification, and integration tests are not yet implemented. The existing exact one-line/one-journal matcher remains the only active matching behavior until those services are added.


## Bank API implementation update (2026-11-19)

- Added `class-bank.php` and registered it in the plugin bootstrap.
- Added finance-authorized bank-account list/create/read/update endpoints. Each account must map to an active general-ledger bank account (account code 1210); account references are unique and opening balance/date are validated.
- Added multipart CSV/XLSX statement import with a 10 MiB limit, file SHA-256 duplicate-batch detection, row-level external transaction duplicate detection, import-batch counters and row error reporting. XLSX requires PHP ZipArchive and SimpleXML; Excel serial dates are normalized.
- Added allocation-based reconciliation endpoint. It locks statement lines and posted journals, checks statement-line and journal capacities, validates direction and bank-account-to-GL mapping per allocation pair, and persists allocations in a transaction with a match-group UUID. Partial statement-line matches are represented explicitly.
- Existing single-line manual intake/match routes remain for backward compatibility. New batch-import and allocation routes are additive.
- New routes: GET/POST `/bank/accounts`; GET/POST/PATCH `/bank/accounts/{id}`; POST `/bank/import`; GET `/bank/import-batches/{id}`; POST `/bank/reconcile`.
- Source commits: `ff6f55b3e1381a458ca26dc306174ae1ccfb6902`, `688824aba8204ea50cddaf6ee247a9515f9deb72`, `7c518fd9d89cefe107b7478104a1812921613344`, `b77a5071f97b912c488ca4d8ec10bd123ccc9658`, `79ed44d88264d6b876edcff5e572c941fa72db32`, `052b266bd30ebd2577269faf40c0e60d6f418805`.
- Verification status: no PHP lint, WP database migration, CSV/XLSX fixture, concurrency, or reconciliation integration test has yet been observed for these commits. Treat this as implemented source, not a validated release. The legacy matcher still needs a compatibility review against the new allocation table; bank opening/closing statement balance controls and period close are still outstanding.


## Ledger and receivables acceptance test harness (2026-11-19)

- Added `wordpress/parsian-final/tests/finance-ledger-acceptance.php`, a WP-CLI integration harness that creates isolated synthetic journals and invoices, verifies opening balance, period debit/credit, closing balance, chronological running balance, and all five receivables-aging buckets, then removes its fixtures.
- The harness refuses to run unless `PARSIAN_ALLOW_FINANCE_TESTS=true` is explicitly defined and refuses a WordPress environment marked production.
- The harness has been added to source but has **not** been executed against a staging WordPress database. A successful test result is not claimed.
- Updated the legacy exact-match endpoint to write into the new reconciliation allocation table in the same transaction, so legacy matches consume allocation capacity and cannot be silently re-used by the new matcher.
- Follow-up verification still required: PHP lint on the full plugin, isolated staging migration, execution of the WP-CLI harness, and bank CSV/XLSX fixtures including duplicate-file, duplicate-row, malformed-row, partial and multi-journal cases.


## Token authentication and offline sync hardening (2026-11-19)

- Plugin version/schema version bumped to `0.4.2`; activation migration creates `parsian_auth_tokens` with SHA-256 token hashes, token family, user/device binding, expiry and revocation metadata.
- `/auth/login` now authenticates WordPress credentials without issuing a browser login cookie and returns a 15-minute opaque Bearer access token plus a 30-day refresh token. Tokens are stored only as SHA-256 hashes.
- `/auth/refresh` rotates refresh tokens; reuse of a revoked refresh token is rejected. Bearer access tokens are resolved through WordPress `determine_current_user` and checked against token expiry and device revocation.
- `/auth/logout` revokes the token family; `/auth/revoke-device` revokes a device and all its tokens. Login can create/bind a device UUID and platform.
- Sync now exposes the current user's device registry, limits accepted operation types to an explicit allowlist, persists a SHA-256 digest of each operation payload, treats identical retries idempotently, and rejects reuse of an operation UUID with changed payload/device/user. Offline operations remain `pending_review`; they are not posted to accounting, inventory or sales automatically.
- Added guarded WP-CLI acceptance harness `tests/auth-sync-acceptance.php` for login, access-token resolution, refresh rotation/reuse rejection, queued sync retry, changed-payload conflict and device revocation. It requires explicit opt-in and refuses production.
- Added `tests/finance-ledger-acceptance.php` earlier for subledger and aging acceptance.
- Added GitHub Actions workflow `.github/workflows/parsian-php-lint.yml` to lint every plugin PHP file on relevant pushes/PRs.
- **Verification status:** the GitHub Actions runs associated with the lint workflow returned failure before exposing any job steps/logs (`logs_url=null`, empty steps); retry behaved the same. Therefore PHP lint is **not confirmed passing**. No isolated WordPress/MySQL staging database is connected here, so neither WP-CLI acceptance harness nor dbDelta migration has been executed. No DB integration test is claimed.
- **Remaining sync work:** domain-specific conflict resolution using aggregate versions, server-side processing/approval of pending operations, delta access scoping by assigned customer/representative, retention/cleanup and Android/Windows client persistence/retry tests remain outstanding.
- Latest source commits include `82bc5a508cb2635ba165baa9f1c44d56dc59240b` (auth), `56dcfb9387fdfceb05e2c130a90475495a9f0510` (token schema), `ecffac3f4f7b6c515ca14cda4d9693d60514e3b7` (sync idempotency), `001cf3e5a2fb04c7692dd7b9ddc378c9df6be42d` (sync schema), `94d4b9f17fce33fd8778495fa245591f35358d2a` and `2d1e421d61a0267e0e55b6a926419230773eedc9` (auth/sync test harness).


- The finance aging acceptance harness was corrected to assert against its own synthetic customer rather than assuming the entire staging database contains no other receivables.
- Latest follow-up commits: `df9cd318e064da767c615778f000f39333c9b914` (remove duplicate lint workflow) and `341032dc5030832011fd0cd3d611b0542aa3d137` (scope aging test assertions).


## Local syntax check detail (2026-11-19)

- PHP CLI 8.4 was available in the working environment. Manual `php -l` checks passed for the current source copies of `class-auth.php`, `class-sync.php`, `parsian-final.php`, `tests/auth-sync-acceptance.php`, and `tests/finance-ledger-acceptance.php`.
- This is a targeted syntax check, **not** a full-plugin lint: the modified installer and bank reconciliation source were not linted in the local CLI session. The GitHub Actions lint runs still failed before exposing job steps/logs, so whole-plugin lint remains unverified.
- The working environment has no WP-CLI, MySQL client, or PHP MySQL driver available. Consequently the migration and database acceptance harnesses cannot be executed here. They require an isolated WordPress + MySQL staging environment.
