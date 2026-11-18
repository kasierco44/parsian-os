from importlib.util import module_from_spec, spec_from_file_location
from pathlib import Path

import pytest
from pydantic import ValidationError

from app.main import app
from app.schemas.product import ProductAdminRead, ProductCreate, ProductRead, ProductUpdate


def test_product_create_validates_sku_and_price() -> None:
    product = ProductCreate(sku="TY-405-SI", name="وایر استاندارد ۴۰۵", unit_price=1150000)
    assert product.sku == "TY-405-SI"
    assert product.unit_price == 1150000


def test_product_create_rejects_negative_price() -> None:
    with pytest.raises(ValidationError):
        ProductCreate(sku="TY-405", name="وایر ۴۰۵", unit_price=-1)


def test_product_update_supports_catalog_deactivation() -> None:
    update = ProductUpdate(is_active=False)
    assert update.model_dump(exclude_unset=True) == {"is_active": False}


def test_customer_and_catalog_routes_are_registered() -> None:
    paths = {route.path for route in app.routes}
    assert "/api/v1/customers" in paths
    assert "/api/v1/catalog/manifest" in paths
    assert "/api/v1/catalog/products" in paths
    assert "/api/v1/catalog/products" in paths
    assert "/api/v1/users" in paths
    assert "/api/v1/replication/push" in paths
    assert "/api/v1/sales/orders" in paths
    assert "/api/v1/sales/orders/{order_id}/invoice" in paths
    assert "/api/v1/sales/invoices/{invoice_id}/receipts" in paths


def test_alembic_revision_chain_is_contiguous() -> None:
    versions = Path(__file__).parents[1] / "migrations" / "versions"
    expected = {
        "0001_identity_and_audit.py": (None, "0001_identity_audit"),
        "0002_offline_sync.py": ("0001_identity_audit", "0002_offline_sync"),
        "0003_customers.py": ("0002_offline_sync", "0003_customers"),
        "0004_product_catalog.py": ("0003_customers", "0004_product_catalog"),
        "0005_replication.py": ("0004_product_catalog", "0005_replication"),
        "0006_sales_cycle.py": ("0005_replication", "0006_sales_cycle"),
        "0007_payment_idempotency.py": ("0006_sales_cycle", "0007_payment_idempotency"),
    }
    for filename, (parent, revision) in expected.items():
        spec = spec_from_file_location(f"migration_{revision}", versions / filename)
        module = module_from_spec(spec)
        assert spec.loader is not None
        spec.loader.exec_module(module)
        assert module.down_revision == parent
        assert module.revision == revision


def test_seller_catalog_schema_does_not_expose_cost_or_inventory_controls() -> None:
    assert "cost_price" not in ProductRead.model_fields
    assert "track_inventory" not in ProductRead.model_fields
    assert "cost_price" in ProductAdminRead.model_fields
    assert "track_inventory" in ProductAdminRead.model_fields
