import logging
from contextlib import asynccontextmanager
from uuid import uuid4

from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware

from app.api.routes.admin import router as admin_router
from app.api.routes.auth import router as auth_router
from app.api.routes.customers import router as customers_router
from app.api.routes.followups import router as followups_router
from app.api.routes.health import router as system_router
from app.core.config import get_settings
from app.db.session import get_session_factory
from app.models.audit_event import AuditEvent

settings = get_settings()
logging.basicConfig(
    level=getattr(logging, settings.log_level.upper(), logging.INFO),
    format="%(asctime)s %(levelname)s %(name)s %(message)s",
)


@asynccontextmanager
async def lifespan(_: FastAPI):
    if settings.app_env == "production":
        if settings.jwt_secret == "development-only-change-this-secret" or len(settings.jwt_secret) < 32:
            raise RuntimeError("Production JWT_SECRET must be a unique secret of at least 32 characters")
        if not settings.cors_origin_list or "*" in settings.cors_origin_list:
            raise RuntimeError("Production CORS_ORIGINS must be an explicit allowlist; wildcard is forbidden")
    logging.getLogger(__name__).info("Starting %s (%s)", settings.app_name, settings.app_env)
    yield
    logging.getLogger(__name__).info("Shutting down %s", settings.app_name)


app = FastAPI(
    title=settings.app_name,
    version=settings.app_version,
    description="API foundation for the PARSIAN OS business platform.",
    lifespan=lifespan,
    docs_url="/docs" if settings.app_env != "production" else None,
    redoc_url="/redoc" if settings.app_env != "production" else None,
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origin_list,
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
    allow_headers=["Authorization", "Content-Type", "X-Request-ID"],
)

app.include_router(system_router, prefix=settings.api_v1_prefix)
app.include_router(auth_router, prefix=settings.api_v1_prefix)
app.include_router(admin_router, prefix=settings.api_v1_prefix)
app.include_router(customers_router, prefix=settings.api_v1_prefix)
app.include_router(followups_router, prefix=settings.api_v1_prefix)


@app.middleware("http")
async def audit_mutating_requests(request: Request, call_next):
    request_id = request.headers.get("X-Request-ID") or str(uuid4())
    response = await call_next(request)
    response.headers["X-Request-ID"] = request_id
    if request.method in {"POST", "PUT", "PATCH", "DELETE"}:
        try:
            client_ip = request.client.host if request.client else None
            with get_session_factory()() as db:
                db.add(
                    AuditEvent(
                        action=f"http.{request.method.lower()}",
                        entity_type="http_request",
                        entity_id=request.url.path[:80],
                        request_id=request_id,
                        ip_address=client_ip,
                        details={
                            "method": request.method,
                            "path": request.url.path,
                            "status_code": response.status_code,
                        },
                    )
                )
                db.commit()
        except Exception:
            logging.getLogger(__name__).exception("Failed to persist request audit event")
    return response


@app.get("/", include_in_schema=False)
def root() -> dict[str, str]:
    return {"service": settings.app_name, "status": "running", "docs": "/docs"}
