# PARSIAN OS Backend

FastAPI + PostgreSQL service for PARSIAN OS. It includes bearer authentication, assigned CRM customers, an append-only offline sync journal, versioned product catalog manifests, and private product media downloads.

## Local setup

```bash
cd backend
python -m venv .venv
# Windows PowerShell: .venv\Scripts\Activate.ps1
# macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
alembic upgrade head
uvicorn app.main:app --reload
```

Set `DATABASE_URL` to PostgreSQL and replace `JWT_SECRET` with a long random secret. The API prefix defaults to `/api/v1`.

## Main endpoints

- `GET /api/v1/health/live` and `GET /api/v1/health/ready`: process and database health.
- `POST /api/v1/auth/login`, `GET /api/v1/auth/me`: bearer authentication.
- `POST /api/v1/auth/password/change`: completes the required temporary-password change.
- `POST /api/v1/users`: manager-only creation of seller/accountant accounts; newly provisioned users must change their temporary password.
- `GET /api/v1/customers?q=&limit=&offset=`: assigned customers for sellers; managers and superusers can list all.
- `POST /api/v1/customers`: create a customer assigned to the authenticated user.
- `PATCH /api/v1/customers/{id}`: update an assigned customer; only managers can reassign it to another active user.
- `GET /api/v1/customers/{id}`: authorized customer detail.
- `POST /api/v1/sync/push`, `GET /api/v1/sync/pull`: persistent outbox push and cursor-based pull.
- `GET /api/v1/catalog/manifest?since_version=0`: versioned product and media manifest.
- `POST /api/v1/catalog/products`, `PATCH /api/v1/catalog/products/{id}`: manager-only catalog management.
- `POST /api/v1/catalog/products/{id}/media`: manager-only raw image/PDF upload (12 MiB maximum; send the MIME type and `X-File-Name` headers).
- `GET /api/v1/catalog/media/{id}/download`: authenticated private media download.

Sellers receive only customers assigned to their user ID. Product stock and accounting balances are not included in the seller catalog API.

## Migrations

```bash
alembic upgrade head
alembic current
```

The migration chain creates identity/audit, offline sync, assigned customers, and the versioned product catalog. Back up the database before applying migrations to an existing environment.

## Docker deployment (single node)

At repository root, copy `.env.example` to `.env`, set strong unique secrets, and set the initial bootstrap administrator credentials. Then run:

```bash
docker compose up -d --build
docker compose logs -f api
```

The image includes Alembic migrations and runs `alembic upgrade head` before starting the API. The one-time bootstrap command creates a superuser and manager role only if the configured username does not already exist. Keep the bootstrap password private and remove it from the environment after the first successful creation.

The default API bind address is loopback. Put a TLS reverse proxy in front of it. For direct office-LAN access, set `API_BIND_ADDRESS=0.0.0.0` and restrict inbound firewall access to the office network. Product media is stored in the private Docker volume `catalog_media`; back it up separately from PostgreSQL.

This Compose file deploys one node. For an office/cloud pair, give each server a different `PARSIAN_NODE_ID`, configure the same high-entropy `REPLICATION_SHARED_SECRET`, and set each node's `REPLICATION_PEER_NODE_ID` and `REPLICATION_PEER_URL` to the other node. The peer URL must include the API prefix (for example `https://cloud.example/api/v1`). Enable the worker with `docker compose --profile replication up -d --build` on both nodes. The worker uses signed HMAC requests over HTTPS, durable peer cursors, idempotent event IDs, and transfers product media with SHA-256 verification. User identities are mapped by username if UUIDs differ, so provision the same usernames on both nodes before replicating their customer/task data. PostgreSQL and media remain private; do not expose PostgreSQL publicly.

The replication worker currently materializes customer, product, product-media, and offline task/follow-up journal events. It is an initial two-node replication implementation, not a substitute for a full conflict-resolution UI or disaster-recovery test. It does not yet replicate user/role administration, financial ledgers, inventory postings, cheque settlements, or final invoices. Do not run the same node ID on both servers.

## Offline behavior and limits

The mobile app stores customer/task/product cache and a durable outbox. Customer creation, follow-ups, and task completion can be queued while offline. Financial postings, stock movements, final invoice numbering, cheque settlement, and commission accrual must remain pending until the authoritative server confirms them.

The sync journal is currently user-scoped. Cross-user assigned-record replication, office/cloud replication worker, device revocation, SQLCipher key lifecycle, and end-to-end conflict resolution are not yet production-complete. Product media downloads are private and versioned; the Android client checks downloaded byte size before caching but the full cryptographic client-side checksum verification still needs to be added.

## Tests

```bash
pytest -q
```

Automated tests cover password/token utilities, schema validation, route registration, and migration revision continuity. Database-backed integration tests, real PostgreSQL migration/rollback tests, role-isolation tests, and end-to-end offline recovery tests remain release gates.
