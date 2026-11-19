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
- Added journal register and trial-balance read endpoints.
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
