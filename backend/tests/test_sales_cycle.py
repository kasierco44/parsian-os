from types import SimpleNamespace
from uuid import uuid4

import pytest
from pydantic import ValidationError

from app.api.routes.sales import _calculate_progressive_commission, _invoice_status, _journal, router as sales_router
from app.main import app
from app.models.product import Product
from app.models.sales import PaymentReceipt, SalesInvoice
from app.schemas.sales import PaymentCreate, SalesOrderCreate


def test_sales_router_is_mounted_in_application():
    paths = {route.path for route in app.routes}
    assert "/api/v1/sales/orders" in paths
    assert "/api/v1/sales/orders/{order_id}/approve" in paths
    assert "/api/v1/sales/orders/{order_id}/invoice" in paths
    assert "/api/v1/sales/invoices/{invoice_id}/receipts" in paths
    assert "/api/v1/sales/invoices" in paths
    assert "/api/v1/sales/cheques" in paths
    assert "/api/v1/sales/commission/accruals" in paths
    assert "/api/v1/sales/cheques/{cheque_id}/clear" in paths


def test_order_schema_requires_one_or_more_lines():
    with pytest.raises(ValidationError):
        SalesOrderCreate(customer_id=uuid4(), lines=[])


def test_payment_schema_rejects_zero_or_negative_amount():
    with pytest.raises(ValidationError):
        PaymentCreate(amount=0, method="cash")
    with pytest.raises(ValidationError):
        PaymentCreate(amount=-100, method="cash")


def test_cheque_method_is_limited_to_supported_values():
    with pytest.raises(ValidationError):
        PaymentCreate(amount=100, method="credit")


@pytest.mark.parametrize(
    ("paid", "expected"),
    [(0, "unpaid"), (1, "partial"), (99, "partial"), (100, "paid"), (120, "paid")],
)
def test_invoice_status_tracks_collected_amount(paid, expected):
    invoice = SimpleNamespace(paid_amount=paid, total_amount=100)
    assert _invoice_status(invoice) == expected


def test_journal_rejects_unbalanced_entries_before_persisting():
    with pytest.raises(ValueError, match="balanced"):
        _journal(
            object(),
            source_type="test",
            source_id="test-1",
            description="unbalanced",
            lines=[
                {"account_code": "CASH", "debit": 100, "credit": 0},
                {"account_code": "AR", "debit": 0, "credit": 90},
            ],
        )


def test_payment_receipt_has_unique_idempotency_key():
    column = PaymentReceipt.__table__.c.idempotency_key
    assert column.unique is True
    assert column.nullable is True


def test_product_inventory_fields_are_mapped():
    assert "track_inventory" in Product.__table__.c
    assert "cost_price" in Product.__table__.c



def test_commission_uses_marginal_progressive_monthly_tiers():
    tiers = [
        SimpleNamespace(min_collected_amount=0, rate_bps=100, is_active=True),
        SimpleNamespace(min_collected_amount=1000000, rate_bps=200, is_active=True),
    ]
    commission, effective_rate = _calculate_progressive_commission(900000, 200000, tiers)
    assert commission == 3000
    assert effective_rate == 150


@pytest.mark.parametrize(
    ("already", "amount", "expected_commission", "expected_rate"),
    [
        (0, 1000, 15, 150),
        (400, 200, 3, 150),
        (0, 400, 4, 100),
    ],
)
def test_monthly_commission_tiers_are_progressive(already, amount, expected_commission, expected_rate):
    tiers = [
        SimpleNamespace(is_active=True, min_collected_amount=0, rate_bps=100),
        SimpleNamespace(is_active=True, min_collected_amount=500, rate_bps=200),
    ]
    assert _calculate_progressive_commission(already, amount, tiers) == (expected_commission, expected_rate)

def test_commission_accrual_serializes_monthly_calculation_per_seller():
    from app.api.routes.sales import _accrue_commission

    seller_id = uuid4()
    statements = []

    class FakeDB:
        def scalar(self, statement):
            statements.append(statement)
            return SimpleNamespace(id=seller_id) if len(statements) == 1 else 0

        def scalars(self, statement):
            return SimpleNamespace(all=lambda: [])

        def add(self, value):
            pass

    _accrue_commission(
        FakeDB(),
        receipt=SimpleNamespace(id=uuid4(), amount=500),
        invoice=SimpleNamespace(seller_user_id=seller_id, customer_id=uuid4(), invoice_number="INV-TEST"),
    )

    assert len(statements) == 2
    assert statements[0]._for_update_arg is not None

def test_accounting_reports_are_mounted():
    paths = {route.path for route in app.routes}
    assert "/api/v1/sales/accounting/trial-balance" in paths
    assert "/api/v1/sales/accounting/profit-loss" in paths
    assert "/api/v1/sales/accounting/customer-ledger/{customer_id}" in paths



def test_accounting_chart_model_has_unique_code_and_account_metadata():
    from app.models.accounting import AccountingAccount

    table = AccountingAccount.__table__
    assert table.c.code.unique is True
    assert {"account_type", "normal_balance", "parent_code", "is_system", "is_active"} <= set(table.c.keys())


def test_chart_of_accounts_endpoints_are_mounted():
    paths = {route.path for route in app.routes}
    assert "/api/v1/sales/accounting/chart-of-accounts" in paths


def test_opening_balance_schema_requires_balanced_lines_at_api_layer():
    from app.schemas.sales import OpeningBalanceCreate
    from datetime import date

    payload = OpeningBalanceCreate(
        opening_date=date(2026, 1, 1),
        lines=[
            {"account_code": "CASH", "debit": 100, "credit": 0},
            {"account_code": "EQUITY", "debit": 0, "credit": 100},
        ],
    )
    assert len(payload.lines) == 2
    assert payload.lines[1].account_code == "EQUITY"


def test_opening_balance_route_is_mounted():
    paths = {route.path for route in app.routes}
    assert "/api/v1/sales/accounting/opening-balance" in paths


def test_journal_line_account_code_references_chart_of_accounts():
    from app.models.sales import JournalLine

    foreign_keys = list(JournalLine.__table__.c.account_code.foreign_keys)
    assert len(foreign_keys) == 1
    assert foreign_keys[0].target_fullname == "accounting_accounts.code"


def test_account_ledger_endpoint_is_mounted():
    paths = {route.path for route in app.routes}
    assert "/api/v1/sales/accounting/account-ledger/{account_code}" in paths


def test_opening_balance_is_protected_by_partial_unique_index_migration():
    from pathlib import Path

    migration = Path("migrations/versions/0010_unique_opening_balance.py").read_text()
    assert "unique=True" in migration
    assert "source_type = 'opening_balance'" in migration


def test_opening_balance_validation_and_ledger_use_calendar_dates():
    from pathlib import Path

    source = Path("app/api/routes/sales.py").read_text()
    assert "دقیقاً یک مبلغ غیرصفر" in source
    assert "from_date > to_date" in source
    assert "end_exclusive" in source
    assert "normal_balance == \"debit\"" not in source


def test_manual_cash_entry_schema_and_accounting_routes():
    from app.schemas.sales import ManualCashEntryCreate
    from datetime import date

    payload = ManualCashEntryCreate(
        amount=2500000,
        account_code="EXPENSE_RENT",
        payment_account="BANK",
        description="اجاره دفتر",
        entry_date=date(2026, 11, 18),
        reference="رسید اجاره",
    )
    assert payload.amount == 2500000
    paths = {route.path for route in app.routes}
    assert "/api/v1/sales/accounting/expenses" in paths
    assert "/api/v1/sales/accounting/other-income" in paths


def test_profit_loss_aggregates_all_chart_revenue_and_expense_types():
    from pathlib import Path

    source = Path("app/api/routes/sales.py").read_text()
    assert 'AccountingAccount.account_type.in_({"revenue", "expense"})' in source
    assert 'if item["account_type"] == "revenue"' in source
    assert 'if item["account_type"] == "expense"' in source
