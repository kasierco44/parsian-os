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

There is intentionally no public registration endpoint and no seeded administrator. User provisioning and role/permission administration must be implemented as a separately authorized workflow before real users can sign in.

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

The automated tests cover liveness/version metadata and password/token utility behavior. Database-backed authentication integration tests and migration-from-empty/rollback tests are still required.

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
