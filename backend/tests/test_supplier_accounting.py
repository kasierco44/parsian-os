from datetime import date

import pytest
from pydantic import ValidationError

from app.main import app
from app.models.sales import JournalLine
from app.models.suppliers import Supplier, SupplierBill, SupplierPayment
from app.schemas.suppliers import SupplierBillCreate, SupplierCreate, SupplierPaymentCreate, SupplierOpeningBalanceCreate


def test_supplier_accounting_routes_are_mounted():
    paths = {route.path for route in app.routes}
    assert "/api/v1/suppliers" in paths
    assert "/api/v1/suppliers/opening-balance" in paths
    assert "/api/v1/suppliers/bills" in paths
    assert "/api/v1/suppliers/payments" in paths
    assert "/api/v1/suppliers/payments/{payment_id}/clear" in paths
    assert "/api/v1/suppliers/payments/{payment_id}/bounce" in paths
    assert "/api/v1/suppliers/{supplier_id}/ledger" in paths
    assert "/api/v1/suppliers/reports/cash-flow" in paths


def test_supplier_models_include_unique_idempotency_and_due_dates():
    assert Supplier.__tablename__ == "suppliers"
    assert SupplierBill.__table__.c.idempotency_key.unique is True
    assert SupplierPayment.__table__.c.idempotency_key.unique is True
    assert "due_date" in SupplierBill.__table__.c
    assert "due_date" in SupplierPayment.__table__.c
    assert "supplier_id" in JournalLine.__table__.c


def test_supplier_bill_schema_validates_positive_amount():
    payload = SupplierBillCreate(
        supplier_id="00000000-0000-0000-0000-000000000001",
        amount=2500000, account_code="EXPENSE_OFFICE",
        bill_date=date(2026, 11, 1), due_date=date(2026, 12, 1),
        description="خرید ملزومات اداری",
    )
    assert payload.amount == 2500000
    with pytest.raises(ValidationError):
        SupplierBillCreate(
            supplier_id="00000000-0000-0000-0000-000000000001",
            amount=0, account_code="EXPENSE_OFFICE",
            bill_date=date(2026, 11, 1), due_date=date(2026, 12, 1),
            description="خرید ملزومات اداری",
        )


def test_supplier_payment_schema_only_allows_supported_methods():
    payload = SupplierPaymentCreate(
        bill_id="00000000-0000-0000-0000-000000000001",
        amount=100000, method="cheque", payment_date=date(2026, 11, 18),
        due_date=date(2026, 12, 18), bank_name="ملت", cheque_number="123456",
    )
    assert payload.method == "cheque"
    with pytest.raises(ValidationError):
        SupplierPaymentCreate(
            bill_id="00000000-0000-0000-0000-000000000001",
            amount=100000, method="credit", payment_date=date(2026, 11, 18),
        )


def test_supplier_payables_migration_adds_subledger_and_cheque_account():
    from pathlib import Path

    source = Path("migrations/versions/0011_supplier_payables.py").read_text()
    assert 'create_table(\n        "suppliers"' in source
    assert "supplier_id" in source
    assert "CHEQUES_PAYABLE" in source
    assert "supplier_payments" in source


def test_supplier_opening_balance_schema_requires_positive_amount():
    payload = SupplierOpeningBalanceCreate(
        supplier_id="00000000-0000-0000-0000-000000000001",
        amount=4500000000, opening_date=date(2026, 11, 1), due_date=date(2026, 12, 1),
    )
    assert payload.amount == 4500000000
    with pytest.raises(ValidationError):
        SupplierOpeningBalanceCreate(
            supplier_id="00000000-0000-0000-0000-000000000001",
            amount=0, opening_date=date(2026, 11, 1), due_date=date(2026, 12, 1),
        )
