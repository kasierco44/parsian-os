# PARSIAN FINAL — Web Panels

Initial responsive Persian RTL shell for manager, accountant, seller, and customer workspaces.

## Run
Serve this directory from the same origin as WordPress. Before loading app.js, the hosting integration may inject:

```html
<script>
window.PARSIAN_CONFIG = {
  apiRoot: '/wp-json/parsian-final/v1',
  nonce: 'WORDPRESS_REST_NONCE_FROM_AUTHENTICATED_SESSION'
};
</script>
```

Do not hard-code credentials or tokens. WordPress cookie authentication for REST requests requires a valid REST nonce. The public health endpoint checks reachability; `/me` determines the authenticated user and capabilities.

## Security boundaries
- UI capability filtering is presentation only, not authorization.
- Every data endpoint must independently enforce capabilities and record-level scope.
- Seller customer assignment must be enforced in server queries, never in JavaScript.
- Do not put secrets, customer data, financial records, or real tokens in local storage.
- No fabricated KPI values or sample financial balances are displayed.

## Current scope
- Responsive Persian RTL shell and industrial theme.
- Role-aware navigation based on WordPress `/me` response.
- WordPress health and identity connectivity checks.
- Read-only CRM views for customers and leads; proforma list with manager approval action; accountant/manager views for trial balance, journal register and bank accounts.
- Create/edit forms, payment/cheque workflows, statement upload and reconciliation UI, Jalali date controls and end-to-end browser tests remain incomplete.

Development UI scaffold only; not production-ready.

## Customer and backup workspaces (2026-11-19)

- Customer role now has read-only self-service views for its linked account's invoices, proformas and posted incoming payments.
- Manager role has a database snapshot export and checksum validation workspace, customer/seller assignment controls, and an offline-operation review queue. The only currently executable offline approval handler is lead creation; other operation types can be rejected but are not applied. The manager review queue shows pending operations and writes review events back to the originating seller's delta stream. Validation is non-destructive; backup restore is not implemented. The export is capped at 10,000 rows per table and 20 MiB and excludes WordPress uploads/media and authentication tokens.
- Windows Tauri uses a separate bearer-token login path; the WordPress admin panel continues to use same-origin cookie + REST nonce authentication.
- Web tables use Persian digits, Jalali dates and Persian labels for common workflow states.

- Accountant and manager roles also have a paginated payment register with a server-authorized approval action and a cheque register. Payment posting still occurs only in the WordPress finance endpoint and creates the balanced journal there.

- Accountant and manager roles can upload CSV/XLSX bank statements to a selected bank account. Import records are normalized and deduplicated; reconciliation remains a separate workflow and is not performed automatically by upload.

- Bank reconciliation workspace now shows unmatched statement rows, posted journal candidates and summary counts, and can submit a one-to-one allocation. The server reconciliation API also supports grouped many-to-many allocations with over-allocation and bank-ledger checks.
