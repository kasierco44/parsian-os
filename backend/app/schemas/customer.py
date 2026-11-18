from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class CustomerCreate(BaseModel):
    name: str = Field(min_length=2, max_length=180)
    phone: str | None = Field(default=None, max_length=32)
    city: str | None = Field(default=None, max_length=100)
    province: str | None = Field(default=None, max_length=100)
    address: str | None = Field(default=None, max_length=500)
    customer_type: str = Field(default="wholesaler", pattern=r"^(wholesaler|retailer|distributor|other)$")
    notes: str | None = Field(default=None, max_length=2000)


class CustomerRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: UUID
    name: str
    phone: str | None
    city: str | None
    province: str | None
    address: str | None
    customer_type: str
    notes: str | None
    status: str
    is_active: bool
    assigned_to_user_id: UUID
    created_at: datetime
    updated_at: datetime


class CustomerUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=2, max_length=180)
    phone: str | None = Field(default=None, max_length=32)
    city: str | None = Field(default=None, max_length=100)
    province: str | None = Field(default=None, max_length=100)
    address: str | None = Field(default=None, max_length=500)
    customer_type: str | None = Field(default=None, pattern=r"^(wholesaler|retailer|distributor|other)$")
    notes: str | None = Field(default=None, max_length=2000)
    status: str | None = Field(default=None, pattern=r"^(active|inactive|follow_up|blocked)$")
    is_active: bool | None = None
    assigned_to_user_id: UUID | None = None
