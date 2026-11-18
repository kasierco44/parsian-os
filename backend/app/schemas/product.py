from datetime import date
from decimal import Decimal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field, model_validator


class ProductCreate(BaseModel):
    sku: str = Field(min_length=2, max_length=40, pattern=r"^[A-Za-z0-9_-]+$")
    name: str = Field(min_length=2, max_length=180)
    brand: str | None = Field(default=None, max_length=80)
    category: str = Field(default="automotive_parts", min_length=2, max_length=60)
    unit: str = Field(default="set", min_length=1, max_length=20)
    vehicle_compatibility: str | None = Field(default=None, max_length=240)
    description: str | None = None


class ProductUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=2, max_length=180)
    brand: str | None = Field(default=None, max_length=80)
    category: str | None = Field(default=None, min_length=2, max_length=60)
    unit: str | None = Field(default=None, min_length=1, max_length=20)
    vehicle_compatibility: str | None = Field(default=None, max_length=240)
    description: str | None = None
    is_active: bool | None = None


class ProductRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    sku: str
    name: str
    brand: str | None
    category: str
    unit: str
    vehicle_compatibility: str | None
    description: str | None
    is_active: bool


class ProductPriceCreate(BaseModel):
    amount: Decimal = Field(gt=0, max_digits=16, decimal_places=2)
    currency: str = Field(default="IRR", pattern=r"^IRR$")
    price_type: str = Field(default="base", pattern=r"^(base|wholesale|representative)$")
    valid_from: date
    valid_to: date | None = None

    @model_validator(mode="after")
    def validate_period(self):
        if self.valid_to is not None and self.valid_to < self.valid_from:
            raise ValueError("valid_to must be on or after valid_from")
        return self


class ProductPriceRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    product_id: UUID
    amount: Decimal
    currency: str
    price_type: str
    valid_from: date
    valid_to: date | None


class ProductWithPrices(ProductRead):
    prices: list[ProductPriceRead] = []
