from datetime import date
from uuid import UUID

from pydantic import BaseModel, Field


class SupplierCreate(BaseModel):
    code: str = Field(min_length=2, max_length=40, pattern=r"^[A-Za-z0-9_-]+$")
    name: str = Field(min_length=2, max_length=180)
    phone: str | None = Field(default=None, max_length=40)
    tax_id: str | None = Field(default=None, max_length=60)
    address: str | None = Field(default=None, max_length=2000)
    notes: str | None = Field(default=None, max_length=2000)


class SupplierBillCreate(BaseModel):
    supplier_id: UUID
    supplier_invoice_number: str | None = Field(default=None, max_length=80)
    amount: int = Field(gt=0, le=10_000_000_000_000)
    account_code: str = Field(min_length=1, max_length=40)
    bill_date: date
    due_date: date
    description: str = Field(min_length=3, max_length=300)


class SupplierPaymentCreate(BaseModel):
    bill_id: UUID
    amount: int = Field(gt=0, le=10_000_000_000_000)
    method: str = Field(pattern=r"^(cash|bank_transfer|cheque)$")
    payment_date: date
    due_date: date | None = None
    bank_name: str | None = Field(default=None, max_length=100)
    cheque_number: str | None = Field(default=None, max_length=80)
    reference: str | None = Field(default=None, max_length=120)



class SupplierOpeningBalanceCreate(BaseModel):
    supplier_id: UUID
    amount: int = Field(gt=0, le=10_000_000_000_000)
    opening_date: date
    description: str = Field(default="مانده افتتاحیه تأمین‌کننده", min_length=3, max_length=300)
