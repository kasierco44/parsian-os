from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class ProductCreate(BaseModel):
    sku: str = Field(min_length=2, max_length=80, pattern=r"^[A-Za-z0-9_.-]+$")
    name: str = Field(min_length=2, max_length=180)
    category: str = Field(default="wire", max_length=100)
    vehicle_model: str | None = Field(default=None, max_length=120)
    description: str | None = None
    unit_price: int | None = Field(default=None, ge=0)
    cost_price: int | None = Field(default=None, ge=0)
    track_inventory: bool = False


class ProductUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=2, max_length=180)
    category: str | None = Field(default=None, max_length=100)
    vehicle_model: str | None = Field(default=None, max_length=120)
    description: str | None = None
    unit_price: int | None = Field(default=None, ge=0)
    cost_price: int | None = Field(default=None, ge=0)
    track_inventory: bool | None = None
    is_active: bool | None = None


class ProductRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: UUID
    sku: str
    name: str
    category: str
    vehicle_model: str | None
    description: str | None
    unit_price: int | None
    cost_price: int | None
    track_inventory: bool
    is_active: bool
    catalog_version: int
    updated_at: datetime


class ProductMediaRead(BaseModel):
    id: UUID
    product_id: UUID
    file_name: str
    mime_type: str
    size_bytes: int
    checksum: str
    version: int
    download_url: str


class CatalogManifest(BaseModel):
    version: int
    products: list[ProductRead]
    media: list[ProductMediaRead]
