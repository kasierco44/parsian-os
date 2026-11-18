from types import SimpleNamespace
from uuid import uuid4

from app.api.routes.sync import _apply_offline_order_draft
from app.models.customer import Customer
from app.models.product import Product
from app.models.sales import SalesOrder


class FakeSession:
    def __init__(self, records):
        self.records = records
        self.added = []

    def get(self, model, entity_id):
        return self.records.get((model, entity_id))

    def add(self, value):
        self.added.append(value)

    def flush(self):
        return None


def test_offline_multiline_order_becomes_server_side_draft():
    user_id, customer_id, product_a_id, product_b_id, order_id = [uuid4() for _ in range(5)]
    user = SimpleNamespace(id=user_id, is_superuser=False, roles=[])
    customer = SimpleNamespace(id=customer_id, name="عمده‌فروش", is_active=True, assigned_to_user_id=user_id)
    product_a = SimpleNamespace(id=product_a_id, sku="TY-405", name="وایر ۴۰۵", is_active=True, unit_price=1100000)
    product_b = SimpleNamespace(id=product_b_id, sku="TY-SI", name="وایر زیمنس", is_active=True, unit_price=1200000)
    db = FakeSession({
        (Customer, customer_id): customer,
        (Product, product_a_id): product_a,
        (Product, product_b_id): product_b,
    })

    normalized, error = _apply_offline_order_draft(db, user, str(order_id), {
        "customer_id": str(customer_id),
        "lines": [
            {"product_id": str(product_a_id), "quantity": 3},
            {"product_id": str(product_b_id), "quantity": 2, "unit_price": 1150000, "discount_amount": 50000},
        ],
        "note": "سفارش آزمایشی",
    })

    assert error is None
    order = next(item for item in db.added if isinstance(item, SalesOrder))
    assert order.status == "draft"
    assert len(order.lines) == 2
    assert normalized["subtotal"] == 5600000
    assert normalized["discount_amount"] == 50000
    assert normalized["total_amount"] == 5550000


def test_offline_order_rejects_customer_owned_by_another_seller():
    user_id, other_id, customer_id, product_id, order_id = [uuid4() for _ in range(5)]
    user = SimpleNamespace(id=user_id, is_superuser=False, roles=[])
    customer = SimpleNamespace(id=customer_id, name="مشتری", is_active=True, assigned_to_user_id=other_id)
    product = SimpleNamespace(id=product_id, sku="TY-405", name="وایر ۴۰۵", is_active=True, unit_price=100)
    db = FakeSession({(Customer, customer_id): customer, (Product, product_id): product})

    normalized, error = _apply_offline_order_draft(db, user, str(order_id), {
        "customer_id": str(customer_id),
        "lines": [{"product_id": str(product_id), "quantity": 1}],
    })

    assert normalized is None
    assert "دسترسی" in error
