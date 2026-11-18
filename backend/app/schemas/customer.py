from decimal import Decimal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class CustomerCreate(BaseModel):
    customer_code: str = Field(min_length=2, max_length=32, pattern=r"^[A-Za-z0-9_-]+$")
    name: str = Field(min_length=2, max_length=180)
    customer_type: str = Field(default="distributor", pattern=r"^(distributor|wholesaler|retailer|service|other)$")
    phone: str | None = Field(default=None, max_length=32)
    mobile: str | None = Field(default=None, max_length=32)
    province: str | None = Field(default=None, max_length=80)
    city: str | None = Field(default=None, max_length=80)
    address: str | None = None
    postal_code: str | None = Field(default=None, max_length=20)
    economic_code: str | None = Field(default=None, max_length=32)
    national_id: str | None = Field(default=None, max_length=32)
    source: str | None = Field(default=None, max_length=40)
    assigned_to_user_id: UUID | None = None
    credit_limit: Decimal = Field(default=Decimal("0"), ge=0, max_digits=16, decimal_places=2)
    payment_terms_days: int = Field(default=0, ge=0, le=365)
    notes: str | None = None


class CustomerUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=2, max_length=180)
    customer_type: str | None = Field(default=None, pattern=r"^(distributor|wholesaler|retailer|service|other)$")
    phone: str | None = Field(default=None, max_length=32)
    mobile: str | None = Field(default=None, max_length=32)
    province: str | None = Field(default=None, max_length=80)
    city: str | None = Field(default=None, max_length=80)
    address: str | None = None
    postal_code: str | None = Field(default=None, max_length=20)
    economic_code: str | None = Field(default=None, max_length=32)
    national_id: str | None = Field(default=None, max_length=32)
    source: str | None = Field(default=None, max_length=40)
    assigned_to_user_id: UUID | None = None
    credit_limit: Decimal | None = Field(default=None, ge=0, max_digits=16, decimal_places=2)
    payment_terms_days: int | None = Field(default=None, ge=0, le=365)
    is_active: bool | None = None
    notes: str | None = None


class CustomerRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: UUID
    customer_code: str
    name: str
    customer_type: str
    phone: str | None
    mobile: str | None
    province: str | None
    city: str | None
    address: str | None
    postal_code: str | None
    economic_code: str | None
    national_id: str | None
    source: str | None
    assigned_to_user_id: UUID | None
    credit_limit: Decimal
    payment_terms_days: int
    is_active: bool
    notes: str | None
