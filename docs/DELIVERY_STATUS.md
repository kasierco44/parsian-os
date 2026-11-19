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
