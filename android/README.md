# PARSIAN FINAL — Android clients

This Gradle project produces two distinct application variants from a shared codebase:

- `sellerDebug` / `sellerRelease`: PARSIAN seller CRM
- `customerDebug` / `customerRelease`: PARSIAN customer app

## Build

With Android SDK 35, JDK 17 and Gradle 8.7:

```bash
gradle :app:assembleSellerDebug :app:assembleCustomerDebug
```

Minimum Android version is API 26 (Android 8.0) for this initial compatibility baseline. Reconfirm against the actual device fleet before release.

## Current status

- Separate package IDs and role-specific Compose launch screens are scaffolded.
- Room, WorkManager, Retrofit and OkHttp dependencies are declared for the next offline/API implementation phase.
- Encrypted database, durable operation outbox, secure token storage, API calls, sync worker, UI workflows, signing and device tests are not implemented yet.
- A GitHub Actions workflow now attempts both debug variants on pushes/PRs. Its first run must be inspected before any APK is claimed. No signed release APK/AAB is claimed.

Do not distribute debug builds as production apps. Release signing material must be stored only in protected CI secrets and must never be committed.

## Native app screens (2026-11-19)

- Seller flavor now loads assigned customers, owned leads and the seller's proformas from the authenticated REST API. It can queue a new lead draft locally while offline; the server places it in pending review. A manager can approve the lead from the web review queue, after which the seller receives a delta event and the local outbox status changes to applied.
- Customer flavor now loads only the linked customer's overview, invoices, proformas and incoming payment records. The WordPress customer account must be linked to a customer record through the manager-only /customers/{id}/link-user endpoint.
- API site URLs entered on login are normalized to /wp-json/parsian-final/v1 and HTTPS is mandatory.
- API dates are rendered with Android ICU PersianCalendar and common workflow states are translated into Persian.
- The app still requires a successful CI build and real-device testing before any APK can be called install-ready.
