from types import SimpleNamespace
from uuid import uuid4

from app.api.routes.sync import _validate_proforma_draft
from app.models.customer import Customer
from app.models.product import Product


class FakeSession:
    def __init__(self, records):
        self.records = records

    def get(self, model, entity_id):
        return self.records.get((model, entity_id))


def test_proforma_draft_is_normalized_against_authoritative_catalog():
    user_id, customer_id, product_id = uuid4(), uuid4(), uuid4()
    user = SimpleNamespace(id=user_id, is_superuser=False, roles=[])
    customer = SimpleNamespace(id=customer_id, name="مشتری نمونه", is_active=True, assigned_to_user_id=user_id)
    product = SimpleNamespace(
        id=product_id, sku="WIRE-405", name="وایر ۴۰۵",
        is_active=True, unit_price=1250000, catalog_version=7,
    )
    db = FakeSession({(Customer, customer_id): customer, (Product, product_id): product})

    normalized, error = _validate_proforma_draft(db, user, {
        "customer_id": str(customer_id),
        "items": [{"product_id": str(product_id), "quantity": 2, "unit_price": 1200000}],
        "status": "final",
    })

    assert error is None
    assert normalized["status"] == "draft"
    assert normalized["customer_name"] == "مشتری نمونه"
    assert normalized["items"][0]["line_total"] == 2400000
    assert normalized["items"][0]["current_catalog_price"] == 1250000
    assert normalized["items"][0]["catalog_version"] == 7


def test_proforma_draft_rejects_customer_assigned_to_another_seller():
    user_id, other_user_id, customer_id, product_id = uuid4(), uuid4(), uuid4(), uuid4()
    user = SimpleNamespace(id=user_id, is_superuser=False, roles=[])
    customer = SimpleNamespace(id=customer_id, name="مشتری دیگر", is_active=True, assigned_to_user_id=other_user_id)
    product = SimpleNamespace(id=product_id, sku="WIRE-405", name="وایر ۴۰۵", is_active=True, unit_price=1, catalog_version=1)
    db = FakeSession({(Customer, customer_id): customer, (Product, product_id): product})

    normalized, error = _validate_proforma_draft(db, user, {
        "customer_id": str(customer_id),
        "items": [{"product_id": str(product_id), "quantity": 1}],
    })

    assert normalized is None
    assert "دسترسی" in error


def test_proforma_draft_rejects_inactive_or_unknown_product():
    user_id, customer_id, product_id = uuid4(), uuid4(), uuid4()
    user = SimpleNamespace(id=user_id, is_superuser=False, roles=[])
    customer = SimpleNamespace(id=customer_id, name="مشتری نمونه", is_active=True, assigned_to_user_id=user_id)
    product = SimpleNamespace(id=product_id, sku="WIRE-405", name="وایر ۴۰۵", is_active=False, unit_price=1, catalog_version=1)
    db = FakeSession({(Customer, customer_id): customer, (Product, product_id): product})

    normalized, error = _validate_proforma_draft(db, user, {
        "customer_id": str(customer_id),
        "items": [{"product_id": str(product_id), "quantity": 1}],
    })

    assert normalized is None
    assert "غیرفعال" in error
