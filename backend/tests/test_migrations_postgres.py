import os
import subprocess
import sys
from pathlib import Path

import pytest
from sqlalchemy import create_engine, inspect, text

DATABASE_URL = os.getenv("TEST_DATABASE_URL")
pytestmark = pytest.mark.skipif(not DATABASE_URL, reason="TEST_DATABASE_URL is not configured")


def test_alembic_upgrade_creates_identity_lockout_customer_and_product_schema() -> None:
    backend = Path(__file__).resolve().parents[1]
    env = os.environ.copy()
    env["DATABASE_URL"] = DATABASE_URL
    env["APP_ENV"] = "test"
    subprocess.run(
        [sys.executable, "-m", "alembic", "upgrade", "head"],
        cwd=backend,
        env=env,
        check=True,
        capture_output=True,
        text=True,
    )
    engine = create_engine(DATABASE_URL)
    try:
        inspector = inspect(engine)
        expected_tables = {
            "users", "roles", "permissions", "user_roles", "role_permissions",
            "audit_events", "customers", "products", "product_prices",
        }
        assert expected_tables <= set(inspector.get_table_names())
        user_columns = {column["name"] for column in inspector.get_columns("users")}
        assert {"failed_login_attempts", "locked_until", "must_change_password"} <= user_columns
        with engine.connect() as connection:
            revision = connection.execute(text("SELECT version_num FROM alembic_version")).scalar_one()
        assert revision == "0004_products"
    finally:
        engine.dispose()
