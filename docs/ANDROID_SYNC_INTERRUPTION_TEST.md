# PARSIAN ONE — Android Sync Interruption Test

This is a Staging-only acceptance procedure. Never use a Production account or production data.

## Automated transport test

The Android unit test `SyncHttpTransportTest.midResponseDisconnectIsClassifiedForRetry` uses MockWebServer to close the connection while a response body is being read. It verifies that the resulting `IOException` is classified for WorkManager retry.

Run from the repository root:

```powershell
cd android
gradle --no-daemon :app:testSellerDebugUnitTest :app:testCustomerDebugUnitTest
```

## Emulator interruption rehearsal

1. Build and install the seller/customer debug APKs from the same commit on an Android emulator configured for API 26 or newer.
2. Point the app at a disposable Staging WordPress environment and sign in with a test user/device.
3. Disable network connectivity and create one customer update and one draft-proforma update. Confirm both are queued locally and are not reported as server-applied.
4. Restore connectivity. Start Sync, then use the emulator network console to apply 100% packet loss while the request/response is in flight. Repeat at least once during push and once during pull.
5. Restore network quality to normal and run Sync again. Confirm the same operation UUIDs are retried; the server contains one operation per UUID, no duplicate proforma/payment/invoice is created, and the pull cursor advances only after the inbox page is persisted.
6. Edit the same customer/proforma on Staging before manager approval. Confirm the manager queue shows a conflict with server/device values side by side.
7. Choose **Keep server** and verify the old device edit is marked rejected locally after the next pull. Repeat with **Rebase device changes**, approve the rebased operation separately, and verify the resulting server value.
8. Force-close the app during a pull response and reopen it. Confirm the inbox, cursor and local outbox acknowledgement remain consistent; rerun Sync and verify idempotency.
9. Save emulator logs, test account IDs (not passwords/tokens), operation UUIDs and the Staging commit SHA in the release evidence.

## Physical-device rehearsal

On a physical Android device, perform the same sequence by switching Airplane mode on/off during Sync. If the device/Android build does not permit automation of radio state, perform the toggle manually while observing the WorkManager state and server operation queue.

## Pass criteria

- No local operation is marked applied before the corresponding server event is durably persisted.
- A lost response and subsequent retry never create a second operation for the same UUID/payload.
- A stale device revision never overwrites a newer server revision.
- A conflict remains visible until a manager decision is made.
- Cursor, inbox records and outbox acknowledgements commit atomically.
- After network recovery, queued work resumes without manual database repair.

A successful MockWebServer unit test is not a substitute for the emulator/physical-device rehearsal. The device rehearsal must be performed and recorded before release.
