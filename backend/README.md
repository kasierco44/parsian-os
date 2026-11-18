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

New staff accounts are created with `must_change_password=true`. The password-change endpoint and enforcement in the authentication flow are still required before production use. The current role and permission catalog is a starting point; validate each role's exact business access before assigning real staff.
