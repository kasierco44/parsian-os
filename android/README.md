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
- This project has not yet been built by CI or on a local Android SDK. No APK is claimed.

Do not distribute debug builds as production apps. Release signing material must be stored only in protected CI secrets and must never be committed.
