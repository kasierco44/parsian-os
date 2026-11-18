# PARSIAN OS Backend

FastAPI service foundation with PostgreSQL/SQLAlchemy, Alembic migrations, identity/role/permission models, audit events, and bearer-token authentication.

## Local setup

```bash
cd backend
python -m venv .venv
# Windows PowerShell: .venv\\Scripts\\Activate.ps1
# macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
copy .env.example .env
uvicorn app.main:app --reload
```

Set `DATABASE_URL` to a reachable PostgreSQL database and replace `JWT_SECRET` with a long random secret.

## Persistent visitor follow-ups

Migration `0004_followups` adds a PostgreSQL-backed follow-up register. Apply it with `alembic upgrade head`.

Endpoints under `/api/v1/followups`:
- `GET /followups` — list and filter follow-ups by customer and status.
- `POST /followups` — create a follow-up with customer, owner, title, note and timezone-aware due date.
- `GET /followups/{id}` — retrieve a follow-up.
- `PATCH /followups/{id}` — update its title, note, due date or status.

All endpoints require `sales.read` or `sales.write`. Visitor accounts can only create follow-ups for customers assigned to themselves, cannot choose another owner, and can only read/update their own follow-ups. Non-visitor users with the relevant permission can manage follow-ups across the organization. Status values are `open`, `completed`, and `cancelled`; a closed follow-up cannot be reopened. Every create/update writes an entity-level audit event in addition to the request audit middleware.

The API requires `due_at` as an ISO-8601 datetime with timezone, for example `2026-11-20T10:00:00+03:30`. Times without an explicit timezone are rejected.

## Other foundations

- Authentication: `POST /api/v1/auth/login`, `GET /api/v1/auth/me`, `POST /api/v1/auth/password/change`.
- Customers: `GET/POST /api/v1/customers`, `GET/PATCH /api/v1/customers/{id}`.
- Administration: `/api/v1/admin` user, role, permission and audit endpoints.
- Tests: run `pytest -q` from `backend/`. CI runs against PostgreSQL 16 and validates the production Docker image.

## Production status

This repository change is not a production deployment. Configure TLS, secrets management, backups, monitoring and the actual API domain on the hosting server before connecting real sales data. Do not commit production credentials or real customer data.
