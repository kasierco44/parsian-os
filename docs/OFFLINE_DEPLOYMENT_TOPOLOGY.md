# PARSIAN OS — Offline Deployment Topology

## Target topology

PARSIAN OS is designed for three independent connectivity conditions:

1. **Office Internet outage, office LAN available:** office PCs and the office server continue to work against the local PostgreSQL/API node.
2. **Remote seller has no route to either node:** the Android client uses its encrypted local database, cached customer/catalog data, and durable outbox.
3. **Office LAN/server is unavailable:** each device can only use its own last synchronized local data. No client can read data that was never downloaded to it.

The intended production topology is:

- Office node: API + PostgreSQL + private media storage on the office LAN.
- Cloud node: API + PostgreSQL + private media storage for remote users and disaster recovery.
- Android/web clients: local encrypted cache + durable outbox + pull cursor.
- Replication service: authenticated, append-only, idempotent office-to-cloud and cloud-to-office journal replication.

## Non-negotiable data rules

- Never clear an outbox item until the receiving server acknowledges its operation ID.
- Every mutation has a globally unique operation ID; retries must be idempotent.
- Keep local records and media when a request fails, the app closes, or a server is unavailable.
- Preserve tombstones until every replication peer has acknowledged the deletion.
- Pull using durable cursors; commit cached changes and cursor advancement atomically.
- Do not silently resolve conflicting edits. Surface the conflict and preserve both versions for review.
- Product photos and documents are immutable versioned media objects; metadata carries checksum, size, MIME type, and version.
- Store media outside PostgreSQL in private object storage/NAS; only metadata and object keys belong in the database.

## Business authority while offline

The client may create local drafts for customer notes, visits, follow-ups, tasks, and proformas. Final invoice numbers, posted receipts, cheque settlement, stock movements, account balances, and commission accruals remain pending until the authoritative server validates and commits them. A local draft must never be displayed as a settled financial transaction.

## Security boundaries

- Encrypt the mobile database at rest before production rollout; Expo SQLite without SQLCipher is not sufficient for production-sensitive customer/financial data.
- Keep tokens in platform secure storage; never place credentials in SQLite or logs.
- Apply role, province/assignment, and record-level authorization on every API read and write.
- A seller must not receive warehouse quantities or accounting records merely because they are cached.
- Authenticate server-to-server replication with rotated credentials or mutual TLS; do not expose PostgreSQL directly to the Internet.
- Back up PostgreSQL and media independently and test restore procedures.

## Implementation status

The Android branch currently contains a persistent SQLite outbox/cache, push/pull API foundation, task/follow-up offline persistence, and network-restoration sync trigger. The API sync journal is scoped to the authenticated user. The Android build enables SQLCipher and migrates the earlier plaintext SQLite cache into an encrypted database while preserving the legacy database until the copy succeeds. A signed two-node replication API and worker now transfer customer, product, product-media, and task/follow-up events; users are mapped by username when UUIDs differ. Replication of user/role administration, financial ledgers, inventory postings, cheque settlements, and final invoices remains outside this first replication pass.

The existing docker-compose.yml is a development single-node API/PostgreSQL stack. It is not an office/cloud replication deployment and must not be treated as high availability.

## Release acceptance tests

1. Create a follow-up in airplane mode; force-close and reopen the app; verify the item remains.
2. Reconnect; verify one server record exists after repeated sync retries.
3. Simulate API timeout during push; verify the outbox item remains pending.
4. Pull a tombstone; verify the item is hidden but its deletion marker is retained.
5. Create concurrent edits on two devices; verify a conflict is visible and neither edit is silently discarded.
6. Disconnect office WAN while keeping LAN; verify office clients continue against the office node.
7. Disconnect office LAN and cloud WAN; verify remote clients retain their local data.
8. Publish a new product image; verify clients download only the new media version and preserve the previous cache until checksum validation succeeds.
9. Verify seller credentials cannot fetch warehouse/accounting records.
10. Restore the office database and media backup into an isolated environment and verify integrity.
