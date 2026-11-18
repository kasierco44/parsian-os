from types import SimpleNamespace
from uuid import uuid4

from app.api.routes.customers import _customer_scope
from app.schemas.customer import CustomerCreate, CustomerUpdate


def test_customer_create_requires_code_and_name():
    payload = CustomerCreate(customer_code="PGT-1001", name="فروشگاه نمونه")
    assert payload.customer_type == "distributor"
    assert payload.payment_terms_days == 0


def test_customer_credit_limit_cannot_be_negative():
    try:
        CustomerCreate(customer_code="PGT-1002", name="فروشگاه نمونه", credit_limit="-1")
    except ValueError:
        return
    raise AssertionError("negative credit limit should be rejected")


def test_customer_update_ignores_unset_fields():
    payload = CustomerUpdate(city="کرج")
    assert payload.model_dump(exclude_unset=True, exclude_none=True) == {"city": "کرج"}


def test_visitor_customer_scope_is_limited_to_assigned_records():
    visitor_id = uuid4()
    visitor = SimpleNamespace(is_superuser=False, id=visitor_id, roles=[SimpleNamespace(code="visitor")])
    statement = _customer_scope(__import__("sqlalchemy").select(__import__("app.models.customer", fromlist=["Customer"]).Customer), visitor)
    compiled = str(statement.compile(compile_kwargs={"literal_binds": True}))
    assert "assigned_to_user_id" in compiled
    assert str(visitor_id) in compiled


def test_superuser_customer_scope_is_not_restricted():
    admin = SimpleNamespace(is_superuser=True, id=uuid4(), roles=[])
    from app.models.customer import Customer
    statement = _customer_scope(__import__("sqlalchemy").select(Customer), admin)
    assert "assigned_to_user_id" not in str(statement)
