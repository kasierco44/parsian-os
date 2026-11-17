# PARSIAN OS Backend

FastAPI service foundation with PostgreSQL/SQLAlchemy, Alembic migrations, initial identity/role/permission and audit models. Authentication endpoints and business workflows are not yet implemented.

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

Set `DATABASE_URL` in `.env` to a reachable PostgreSQL database. The liveness endpoint does not require database access; readiness does.

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

The initial automated tests cover liveness and version metadata only. Run integration tests against a disposable PostgreSQL database before treating migrations as production-ready.

## Security notes

Passwords are represented only as password hashes in the schema. Password hashing and token issuance must use the approved service layer; no default administrator or production credential is seeded. Never commit `.env` or real secrets.
