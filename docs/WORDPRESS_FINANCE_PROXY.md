# WordPress Finance API Proxy

This change adds a server-side, allowlisted transport from the WordPress finance plugin to the canonical PARSIAN OS API.

## Route

WordPress REST endpoint:

- `POST /wp-json/parsian-finance/v1/backend/proxy`
- Requires an authenticated WordPress user with `parsian_view_finance` and a valid `X-WP-Nonce`.
- Requires a Backend bearer token stored in the current WordPress session.
- Revalidates the Backend identity using `GET /auth/me` before forwarding each request.
- Backend supplier routes continue to enforce manager/accountant permissions independently.

Request body:

```json
{
  "method": "GET",
  "path": "suppliers",
  "query": { "active_only": true }
}
```

For a write:

```json
{
  "method": "POST",
  "path": "suppliers/bills",
  "idempotency_key": "wp-finance-bill-unique-0001",
  "payload": {
    "supplier_id": "00000000-0000-0000-0000-000000000000",
    "amount": 125000000,
    "account_code": "OFFICE_EXPENSE",
    "bill_date": "2026-11-18",
    "due_date": "2026-12-18",
    "description": "نمونه آزمایشی"
  }
}
```

The UUID above is illustrative only. The API proxy never invents or resolves supplier/bill identifiers.

## Allowlisted upstream routes

- `GET suppliers`
- `GET suppliers/charge-accounts` (active leaf asset/expense accounts selectable for supplier bills)
- `POST suppliers`
- `POST suppliers/opening-balance`
- `GET|POST suppliers/bills`
- `GET|POST suppliers/payments`
- `POST suppliers/payments/{uuid}/clear`
- `POST suppliers/payments/{uuid}/bounce`
- `GET suppliers/reports/cash-flow`
- `GET suppliers/{uuid}/ledger`

Only a fixed route allowlist is accepted. Arbitrary URLs, paths containing traversal markers, unsupported methods, and unsupported query keys are rejected. The proxy only accepts HTTPS upstream configuration and retains TLS certificate verification.

## Idempotency

The proxy requires an idempotency key for bill and payment creation. It forwards the key as the upstream `Idempotency-Key` header. Supplier creation and cheque state transitions are protected by the Backend's own validation/state-transition rules; cheque clear/bounce endpoints are not retryable with a caller-supplied idempotency key.

## Scope and rollout boundary

This is transport infrastructure only. It does **not** yet switch the existing finance forms and CRUD routes from local WordPress tables to the Backend. Until the UI and route handlers are migrated in the next phase, existing local-table CRUD remains active. Do not treat the plugin as centrally connected for financial operations and do not enter production financial data through this intermediate build.

## Validation

The repository workflow runs PHP syntax checks and builds the plugin ZIP. A live API test requires the real HTTPS Backend URL and test manager/accountant accounts, which are not available in repository CI. No deployment or live financial write test is claimed.

## Central UI migration

The finance panel UI now reads supplier, bill, payment, and selectable charge-account data through the authenticated Backend proxy. Supplier, bill, and payment creation are sent to the central API; bill/payment creation uses idempotency keys. Payments are linked to a selected bill UUID, and cheque clear/bounce transitions call the central state-transition endpoints. The UI requires an authenticated Backend manager/accountant session before loading central finance data.

The legacy WordPress REST CRUD callbacks and legacy tables remain in the plugin for backward compatibility, but the updated panel UI no longer calls them. Do not use legacy REST routes directly. Existing legacy rows are not automatically migrated or reconciled into the Backend; perform a separately reviewed migration before relying on historical balances. Opening-balance entry is not exposed in this UI revision.
