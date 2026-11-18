from pydantic import ValidationError
import pytest

from app.schemas.customer import CustomerCreate


def test_customer_create_accepts_wholesaler_fields() -> None:
    customer = CustomerCreate(
        name="پخش قطعات نمونه",
        phone="09120000000",
        city="اراک",
        province="مرکزی",
    )
    assert customer.name == "پخش قطعات نمونه"
    assert customer.customer_type == "wholesaler"


def test_customer_create_rejects_short_name() -> None:
    with pytest.raises(ValidationError):
        CustomerCreate(name="الف")


def test_customer_create_rejects_unknown_customer_type() -> None:
    with pytest.raises(ValidationError):
        CustomerCreate(name="مشتری معتبر", customer_type="warehouse_admin")
