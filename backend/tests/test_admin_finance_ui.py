from pathlib import Path


def test_supplier_finance_admin_panel_contains_required_workflows():
    panel = Path(__file__).parents[2] / "admin-finance" / "index.html"
    source = panel.read_text(encoding="utf-8")
    for marker in (
        'id="tab-dashboard"',
        'id="supplierForm"',
        'id="openingForm"',
        'id="billForm"',
        'id="paymentForm"',
        'id="tab-calendar"',
        'id="tab-ledger"',
        'id="tab-cashflow"',
        '/suppliers/reports/cash-flow',
        'Idempotency-Key',
        'role_codes',
    ):
        assert marker in source
