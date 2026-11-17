# PARSIAN OS Backend

FastAPI service foundation. The first increment provides application configuration, API routing, liveness/readiness probes, PostgreSQL connectivity checks, container packaging, and basic tests. Business modules and authentication are intentionally not represented as complete yet.

## Local setup

```bash
cd backend
python -m venv .venv
# Windows: .venv\\Scripts\\activate
# macOS/Linux: source .venv/bin/activate
pip install -r requirements.txt
copy .env.example .env  # Windows PowerShell
# cp .env.example .env   # macOS/Linux
uvicorn app.main:app --reload
```

Set `DATABASE_URL` in `.env` to a reachable PostgreSQL database before calling the readiness endpoint. The liveness endpoint does not require a database connection.

## Endpoints

- `GET /` service root
- `GET /api/v1/health/live` process liveness
- `GET /api/v1/health/ready` database readiness (`503` when unavailable)
- `GET /api/v1/system/version` service metadata
- `GET /docs` interactive OpenAPI docs outside production

## Tests

```bash
pip install -r requirements.txt
pytest
```

The initial tests cover liveness and version metadata and do not require PostgreSQL. Database migrations, authentication, authorization, and business APIs are future increments.

## Configuration

Copy `.env.example` to `.env`; never commit real secrets. Use a dedicated least-privilege database account outside local development.
