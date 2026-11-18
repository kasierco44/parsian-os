from datetime import date
from decimal import Decimal

import pytest
from pydantic import ValidationError

from app.schemas.product import ProductCreate, ProductPriceCreate, ProductUpdate


def test_product_requires_stable_sku_and_name():
    product = ProductCreate(sku="WIRE-405", name="وایر استاندارد پژو ۴۰۵")
    assert product.unit == "set"
    assert product.category == "automotive_parts"


def test_product_sku_rejects_spaces_and_invalid_characters():
    with pytest.raises(ValidationError):
        ProductCreate(sku="WIRE 405", name="وایر")


def test_product_price_requires_positive_irr_amount():
    price = ProductPriceCreate(amount="1150000", valid_from=date(2026, 11, 18))
    assert price.amount == Decimal("1150000")
    assert price.currency == "IRR"
    with pytest.raises(ValidationError):
        ProductPriceCreate(amount="0", valid_from=date(2026, 11, 18))


def test_product_price_period_is_validated():
    with pytest.raises(ValidationError):
        ProductPriceCreate(amount="1000", valid_from=date(2026, 11, 20), valid_to=date(2026, 11, 19))


def test_product_update_does_not_change_sku():
    update = ProductUpdate(name="نام جدید")
    assert update.model_dump(exclude_unset=True) == {"name": "نام جدید"}
