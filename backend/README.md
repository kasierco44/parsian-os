# PARSIAN OS Backend

FastAPI service foundation with PostgreSQL/SQLAlchemy, Alembic migrations, identity/role/permission models, audit events, and an initial bearer-token authentication flow.

## Local setup

```bash
cd backend
python -m venv .venv
# Windows PowerShell: .venv\\Scripts\\Activate.ps1
# macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
copy .env.example .env  # Windows PowerShell; use cp on macOS/Linux
uvicorn app.main:app --reload
```

Set `DATABASE_URL` to a reachable PostgreSQL database and replace `JWT_SECRET` with a long random secret. Production startup refuses the development-only JWT secret.

## Available endpoints

- `GET /api/v1/health/live`: process liveness; no database dependency.
- `GET /api/v1/health/ready`: database readiness.
- `GET /api/v1/system/version`: service metadata.
- `POST /api/v1/auth/login`: accepts JSON `{ "username": "...", "password": "..." }` and returns a bearer access token.
- `GET /api/v1/auth/me`: returns the authenticated user's public profile; requires `Authorization: Bearer <token>`.

There is intentionally no public registration endpoint. Create the initial administrator only through the trusted CLI bootstrap command described below; it seeds the initial RBAC catalog and requires a password rotation on first login.

## Database migrations

```bash
alembic upgrade head
alembic current
```

The first migration creates users, roles, permissions, their many-to-many mappings, and audit events. Review and back up before applying to any persistent environment.

## Tests

```bash
pytest
```

The automated test suite includes authentication and authorization policy coverage, customer scope tests, and a PostgreSQL migration-from-empty integration test in CI.

## Security notes

Passwords are stored only as Argon2 hashes through pwdlib. Access tokens are signed with HS256 and have a configurable lifetime. Never commit `.env`, production credentials, or real customer data.


## Initial administrator and access management

After applying migrations to the target database, create the first administrator from a trusted server terminal. Do not expose bootstrap credentials in source control or run this command from an untrusted workstation.

PowerShell example (run from `backend/`):

```powershell
$env:BOOTSTRAP_ADMIN_USERNAME = "your-admin-login"
$env:BOOTSTRAP_ADMIN_FULL_NAME = "System Administrator"
$env:BOOTSTRAP_ADMIN_PASSWORD = Read-Host "Temporary password"
python -m scripts.bootstrap_admin
Remove-Item Env:BOOTSTRAP_ADMIN_PASSWORD
```

The password must contain at least 12 characters. The bootstrap command refuses to create another superuser if one already exists and marks the initial account to change its password. Keep the initial credentials in an approved password manager.

Administration endpoints are under `/api/v1/admin` and require a valid bearer token plus the documented permission or superuser status:
- `GET /users`, `POST /users`
- `PATCH /users/{user_id}/roles`, `PATCH /users/{user_id}/status`
- `GET /roles`, `POST /roles`, `GET /permissions`
- `PUT /roles/{role_code}/permissions`
- `GET /audit-events?limit=100&offset=0`

New staff accounts are created with `must_change_password=true`. The password-change endpoint is available at `POST /api/v1/auth/password/change`. A user with `must_change_password=true` can use this endpoint but is blocked from `/me` and protected administration endpoints until the password is changed. The current role and permission catalog is a starting point; validate each role's exact business access before assigning real staff.


## Authentication hardening

- A temporary-password account receives an access token at login but can only call `POST /api/v1/auth/password/change` until the password is rotated. The request requires `current_password` and a new password of at least 12 characters.
- Five consecutive failed password checks lock the account for 15 minutes. A successful login resets the counter; expired locks are cleared on the next login attempt.
- Account lockout state is stored in PostgreSQL. Apply migration `0002_auth_lockout` before deploying this revision.
- Mutating HTTP requests are recorded as audit events with request ID, method, path, response status and client IP. Request bodies, passwords, bearer tokens and query strings are deliberately excluded.
- Every response includes `X-Request-ID` for support correlation. If running behind a reverse proxy, configure trusted proxy handling at the infrastructure layer before relying on client IP values.

## PostgreSQL CI and deployment

Pull requests run the backend test suite against an ephemeral PostgreSQL 16 service. The migration integration test applies all Alembic revisions to an empty test database and verifies the final schema. The integration test is skipped locally unless `TEST_DATABASE_URL` is set.

Before production deployment:

1. Back up the target database and verify a restore.
2. Set `APP_ENV=production`, a unique `JWT_SECRET` of at least 32 characters, a production `DATABASE_URL`, and exact HTTPS frontend origins in `CORS_ORIGINS`.
3. Apply migrations with `alembic upgrade head` and verify `alembic current`.
4. Run the one-time administrator bootstrap from a trusted server terminal; do not place the temporary password in shell history or source control.
5. Sign in, change the temporary password immediately, verify role boundaries with non-admin test accounts, and inspect audit events.
6. Confirm the GitHub Actions checks are green before merging.

This backend change is not a production deployment. Configure TLS, secrets management, backups, monitoring, trusted proxy settings, and operational access controls in the hosting environment before exposing the API publicly.


## Customer CRM foundation

Customer endpoints are mounted under `/api/v1/customers`:

- `GET /customers` — paginated search by customer name/code/phone/mobile; optional province, city and active filters.
- `POST /customers` — create a customer with a unique customer code.
- `GET /customers/{customer_id}` — read one customer.
- `PATCH /customers/{customer_id}` — update explicitly supplied fields.

All endpoints require the corresponding `customers.read` or `customers.write` permission. Visitor-role accounts are restricted to customer records assigned to their own user ID; visitor accounts cannot assign or transfer customer ownership to another user. The customer master record includes type, contact information, province/city/address, identifiers, lead source, assigned user, credit limit and payment-term days. Financial transactions and balances are not part of this first CRM migration.

Apply migration `0003_customers` after the authentication migrations. The bootstrap command now seeds role-specific permissions for accountant, seller, visitor and customer roles. Review these defaults against the company's access policy before issuing staff accounts.


## Production Docker Compose deployment

The repository includes a non-root API image, PostgreSQL 16 Compose service, readiness health checks, and automatic Alembic migrations at API container startup. This is a deployment template; it does not deploy itself or provision a public domain/TLS certificate.

On the Linux server, install Docker Engine and the Compose plugin, then:

```bash
cd backend
cp .env.production.example .env
# Edit .env and replace every placeholder with unique production values.
# Use a long URL-safe database password to avoid URL-encoding issues in DATABASE_URL.
docker compose -f compose.production.yml config --quiet
docker compose -f compose.production.yml up -d --build
docker compose -f compose.production.yml ps
curl -fsS http://127.0.0.1:8000/api/v1/health/ready
```

The API binds only to localhost port 8000. Put a TLS-enabled reverse proxy (for example, Nginx or Caddy) in front of it and proxy HTTPS traffic to `127.0.0.1:8000`. Do not expose PostgreSQL publicly. Restrict CORS to the actual HTTPS web admin origins; native Android requests do not use browser CORS enforcement.

Create the first administrator only after the API and migrations are healthy. Run the bootstrap command from a trusted server terminal using environment variables, then remove the temporary password from the shell environment. Never place real credentials in GitHub commits, chat messages, or the repository.

After deployment, configure the GitHub Actions repository variable `PARSIAN_API_BASE_URL` to the public HTTPS API base ending in `/api/v1`, then rebuild the Android app. Verify `/api/v1/health/ready`, administrator password rotation, a visitor login, and that a visitor cannot read another visitor's customers before onboarding staff.

Back up PostgreSQL before upgrades. The Compose volume is persistent but is not a backup; configure encrypted off-host backups and test restores. Production deployment and device-level acceptance tests remain separate operational steps.
