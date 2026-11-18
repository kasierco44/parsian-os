"""Add order-to-cash sales, receipts, commissions, inventory and ledger foundation.

Revision ID: 0006_sales_cycle
Revises: 0005_replication
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0006_sales_cycle"
down_revision = "0005_replication"
branch_labels = None
depends_on = None

UUID = postgresql.UUID(as_uuid=True)


def upgrade() -> None:
    op.add_column("products", sa.Column("track_inventory", sa.Boolean(), nullable=False, server_default=sa.false()))
    op.add_column("products", sa.Column("cost_price", sa.Integer(), nullable=True))

    op.create_table("sales_orders",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("order_number", sa.String(40), nullable=False, unique=True),
        sa.Column("customer_id", UUID, sa.ForeignKey("customers.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("seller_user_id", UUID, sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("status", sa.String(24), nullable=False, server_default="draft"),
        sa.Column("note", sa.Text()),
        sa.Column("subtotal", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("discount_amount", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("total_amount", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("approved_by_user_id", UUID, sa.ForeignKey("users.id", ondelete="SET NULL")),
        sa.Column("approved_at", sa.DateTime(timezone=True)),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_index("ix_sales_orders_order_number", "sales_orders", ["order_number"])
    op.create_index("ix_sales_orders_customer_id", "sales_orders", ["customer_id"])
    op.create_index("ix_sales_orders_seller_user_id", "sales_orders", ["seller_user_id"])
    op.create_index("ix_sales_orders_status", "sales_orders", ["status"])

    op.create_table("sales_order_lines",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("order_id", UUID, sa.ForeignKey("sales_orders.id", ondelete="CASCADE"), nullable=False),
        sa.Column("product_id", UUID, sa.ForeignKey("products.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("sku_snapshot", sa.String(80), nullable=False),
        sa.Column("name_snapshot", sa.String(180), nullable=False),
        sa.Column("quantity", sa.Integer(), nullable=False),
        sa.Column("unit_price", sa.Integer(), nullable=False),
        sa.Column("discount_amount", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("line_total", sa.Integer(), nullable=False))
    op.create_index("ix_sales_order_lines_order_id", "sales_order_lines", ["order_id"])

    op.create_table("sales_invoices",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("invoice_number", sa.String(40), nullable=False, unique=True),
        sa.Column("order_id", UUID, sa.ForeignKey("sales_orders.id", ondelete="RESTRICT"), nullable=False, unique=True),
        sa.Column("customer_id", UUID, sa.ForeignKey("customers.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("seller_user_id", UUID, sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("total_amount", sa.Integer(), nullable=False),
        sa.Column("paid_amount", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("status", sa.String(24), nullable=False, server_default="unpaid"),
        sa.Column("issued_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_index("ix_sales_invoices_invoice_number", "sales_invoices", ["invoice_number"])
    op.create_index("ix_sales_invoices_customer_id", "sales_invoices", ["customer_id"])
    op.create_index("ix_sales_invoices_seller_user_id", "sales_invoices", ["seller_user_id"])

    op.create_table("payment_receipts",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("receipt_number", sa.String(40), nullable=False, unique=True),
        sa.Column("idempotency_key", sa.String(100), nullable=True, unique=True),
        sa.Column("invoice_id", UUID, sa.ForeignKey("sales_invoices.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("amount", sa.Integer(), nullable=False),
        sa.Column("method", sa.String(24), nullable=False),
        sa.Column("status", sa.String(24), nullable=False),
        sa.Column("reference", sa.String(120)),
        sa.Column("received_by_user_id", UUID, sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("received_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_index("ix_payment_receipts_invoice_id", "payment_receipts", ["invoice_id"])

    op.create_table("cheques",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("receipt_id", UUID, sa.ForeignKey("payment_receipts.id", ondelete="RESTRICT"), nullable=False, unique=True),
        sa.Column("bank_name", sa.String(100), nullable=False),
        sa.Column("cheque_number", sa.String(80), nullable=False),
        sa.Column("due_date", sa.Date(), nullable=False),
        sa.Column("drawer_name", sa.String(180), nullable=False),
        sa.Column("status", sa.String(24), nullable=False, server_default="received"),
        sa.Column("cleared_at", sa.DateTime(timezone=True)))
    op.create_index("ix_cheques_cheque_number", "cheques", ["cheque_number"])

    op.create_table("commission_tiers",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("min_collected_amount", sa.Integer(), nullable=False, unique=True),
        sa.Column("rate_bps", sa.Integer(), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()))
    op.create_table("commission_accruals",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("receipt_id", UUID, sa.ForeignKey("payment_receipts.id", ondelete="RESTRICT"), nullable=False, unique=True),
        sa.Column("seller_user_id", UUID, sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("collected_amount", sa.Integer(), nullable=False),
        sa.Column("rate_bps", sa.Integer(), nullable=False),
        sa.Column("commission_amount", sa.Integer(), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_index("ix_commission_accruals_seller_user_id", "commission_accruals", ["seller_user_id"])

    op.create_table("inventory_balances",
        sa.Column("product_id", UUID, sa.ForeignKey("products.id", ondelete="RESTRICT"), primary_key=True),
        sa.Column("quantity_on_hand", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_table("inventory_movements",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("product_id", UUID, sa.ForeignKey("products.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("invoice_id", UUID, sa.ForeignKey("sales_invoices.id", ondelete="RESTRICT")),
        sa.Column("movement_type", sa.String(24), nullable=False),
        sa.Column("quantity_delta", sa.Integer(), nullable=False),
        sa.Column("reference", sa.String(100), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_index("ix_inventory_movements_product_id", "inventory_movements", ["product_id"])

    op.create_table("journal_entries",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("entry_number", sa.String(40), nullable=False, unique=True),
        sa.Column("source_type", sa.String(32), nullable=False),
        sa.Column("source_id", sa.String(100), nullable=False),
        sa.Column("description", sa.String(300), nullable=False),
        sa.Column("posted_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False))
    op.create_index("ix_journal_entries_entry_number", "journal_entries", ["entry_number"])
    op.create_table("journal_lines",
        sa.Column("id", UUID, primary_key=True),
        sa.Column("entry_id", UUID, sa.ForeignKey("journal_entries.id", ondelete="CASCADE"), nullable=False),
        sa.Column("account_code", sa.String(40), nullable=False),
        sa.Column("debit", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("credit", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("customer_id", UUID, sa.ForeignKey("customers.id", ondelete="RESTRICT")),
        sa.Column("seller_user_id", UUID, sa.ForeignKey("users.id", ondelete="RESTRICT")))
    op.create_index("ix_journal_lines_entry_id", "journal_lines", ["entry_id"])


def downgrade() -> None:
    op.drop_index("ix_journal_lines_entry_id", table_name="journal_lines")
    op.drop_table("journal_lines")
    op.drop_index("ix_journal_entries_entry_number", table_name="journal_entries")
    op.drop_table("journal_entries")
    op.drop_index("ix_inventory_movements_product_id", table_name="inventory_movements")
    op.drop_table("inventory_movements")
    op.drop_table("inventory_balances")
    op.drop_index("ix_commission_accruals_seller_user_id", table_name="commission_accruals")
    op.drop_table("commission_accruals")
    op.drop_table("commission_tiers")
    op.drop_index("ix_cheques_cheque_number", table_name="cheques")
    op.drop_table("cheques")
    op.drop_index("ix_payment_receipts_invoice_id", table_name="payment_receipts")
    op.drop_table("payment_receipts")
    op.drop_index("ix_sales_invoices_seller_user_id", table_name="sales_invoices")
    op.drop_index("ix_sales_invoices_customer_id", table_name="sales_invoices")
    op.drop_index("ix_sales_invoices_invoice_number", table_name="sales_invoices")
    op.drop_table("sales_invoices")
    op.drop_index("ix_sales_order_lines_order_id", table_name="sales_order_lines")
    op.drop_table("sales_order_lines")
    op.drop_index("ix_sales_orders_status", table_name="sales_orders")
    op.drop_index("ix_sales_orders_seller_user_id", table_name="sales_orders")
    op.drop_index("ix_sales_orders_customer_id", table_name="sales_orders")
    op.drop_index("ix_sales_orders_order_number", table_name="sales_orders")
    op.drop_table("sales_orders")
    op.drop_column("products", "cost_price")
    op.drop_column("products", "track_inventory")
