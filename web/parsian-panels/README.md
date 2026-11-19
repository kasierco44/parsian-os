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
