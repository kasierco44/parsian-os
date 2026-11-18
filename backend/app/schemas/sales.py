from datetime import date, datetime
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class OrderLineIn(BaseModel):
    product_id: UUID
    quantity: int = Field(ge=1, le=100000)
    unit_price: int | None = Field(default=None, ge=0)
    discount_amount: int = Field(default=0, ge=0)


class SalesOrderCreate(BaseModel):
    customer_id: UUID
    note: str | None = Field(default=None, max_length=2000)
    lines: list[OrderLineIn] = Field(min_length=1, max_length=100)


class SalesOrderLineRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    product_id: UUID
    sku_snapshot: str
    name_snapshot: str
    quantity: int
    unit_price: int
    discount_amount: int
    line_total: int


class SalesOrderRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    order_number: str
    customer_id: UUID
    seller_user_id: UUID
    status: str
    note: str | None
    subtotal: int
    discount_amount: int
    total_amount: int
    approved_by_user_id: UUID | None
    approved_at: datetime | None
    created_at: datetime
    lines: list[SalesOrderLineRead]


class OrderDecision(BaseModel):
    note: str | None = Field(default=None, max_length=1000)


class InvoiceRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    invoice_number: str
    order_id: UUID
    customer_id: UUID
    seller_user_id: UUID
    total_amount: int
    paid_amount: int
    status: str
    issued_at: datetime


class PaymentCreate(BaseModel):
    amount: int = Field(gt=0)
    method: str = Field(pattern=r"^(cash|bank_transfer|cheque)$")
    reference: str | None = Field(default=None, max_length=120)
    bank_name: str | None = Field(default=None, max_length=100)
    cheque_number: str | None = Field(default=None, max_length=80)
    due_date: date | None = None
    drawer_name: str | None = Field(default=None, max_length=180)


class PaymentRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    receipt_number: str
    invoice_id: UUID
    amount: int
    method: str
    status: str
    reference: str | None
    received_by_user_id: UUID
    received_at: datetime


class ChequeDecision(BaseModel):
    note: str | None = Field(default=None, max_length=500)


class CommissionTierIn(BaseModel):
    min_collected_amount: int = Field(ge=0)
    rate_bps: int = Field(ge=0, le=10000)
    is_active: bool = True


class CommissionTierRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    min_collected_amount: int
    rate_bps: int
    is_active: bool


class InventoryAdjustment(BaseModel):
    product_id: UUID
    quantity_delta: int = Field(ne=0, ge=-1000000, le=1000000)
    reason: str = Field(min_length=2, max_length=180)


class InventoryBalanceRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    product_id: UUID
    quantity_on_hand: int


class ChequeRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    receipt_id: UUID
    bank_name: str
    cheque_number: str
    due_date: date
    drawer_name: str
    status: str
    cleared_at: datetime | None


class CommissionAccrualRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    receipt_id: UUID
    seller_user_id: UUID
    collected_amount: int
    rate_bps: int
    commission_amount: int
    created_at: datetime


class OpeningBalanceLine(BaseModel):
    account_code: str = Field(min_length=1, max_length=40)
    debit: int = Field(default=0, ge=0)
    credit: int = Field(default=0, ge=0)


class OpeningBalanceCreate(BaseModel):
    opening_date: date
    description: str = Field(default="مانده افتتاحیه", min_length=3, max_length=300)
    lines: list[OpeningBalanceLine] = Field(min_length=2, max_length=200)


class ManualCashEntryCreate(BaseModel):
    amount: int = Field(gt=0, le=10_000_000_000_000)
    account_code: str = Field(min_length=1, max_length=40)
    payment_account: str = Field(pattern=r"^(CASH|BANK)$")
    description: str = Field(min_length=3, max_length=300)
    entry_date: date
    reference: str | None = Field(default=None, max_length=120)
