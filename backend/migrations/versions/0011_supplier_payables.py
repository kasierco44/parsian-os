"""Add supplier subledger, bills, outgoing payments and cheque liabilities.

Revision ID: 0011_supplier_payables
Revises: 0010_unique_opening_balance
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql
import uuid

revision = "0011_supplier_payables"
down_revision = "0010_unique_opening_balance"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "suppliers",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("code", sa.String(40), nullable=False, unique=True),
        sa.Column("name", sa.String(180), nullable=False),
        sa.Column("phone", sa.String(40)),
        sa.Column("tax_id", sa.String(60)),
        sa.Column("address", sa.Text()),
        sa.Column("notes", sa.Text()),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
    )
    op.create_index("ix_suppliers_code", "suppliers", ["code"], unique=True)
    op.create_index("ix_suppliers_name", "suppliers", ["name"])

    op.add_column("journal_lines", sa.Column("supplier_id", postgresql.UUID(as_uuid=True), nullable=True))
    op.create_foreign_key("fk_journal_lines_supplier_id_suppliers", "journal_lines", "suppliers", ["supplier_id"], ["id"], ondelete="RESTRICT")
    op.create_index("ix_journal_lines_supplier_id", "journal_lines", ["supplier_id"])

    op.create_table(
        "supplier_bills",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("bill_number", sa.String(50), nullable=False, unique=True),
        sa.Column("idempotency_key", sa.String(120), nullable=False, unique=True),
        sa.Column("supplier_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("suppliers.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("supplier_invoice_number", sa.String(80)),
        sa.Column("amount", sa.BigInteger(), nullable=False),
        sa.Column("account_code", sa.String(40), sa.ForeignKey("accounting_accounts.code", ondelete="RESTRICT"), nullable=False),
        sa.Column("bill_date", sa.Date(), nullable=False),
        sa.Column("due_date", sa.Date(), nullable=False),
        sa.Column("description", sa.String(300), nullable=False),
        sa.Column("created_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.CheckConstraint("amount > 0", name="ck_supplier_bills_amount_positive"),
    )
    op.create_index("ix_supplier_bills_supplier_id", "supplier_bills", ["supplier_id"])
    op.create_index("ix_supplier_bills_due_date", "supplier_bills", ["due_date"])
    op.create_index("ix_supplier_bills_bill_number", "supplier_bills", ["bill_number"], unique=True)
    op.create_index("ix_supplier_bills_idempotency_key", "supplier_bills", ["idempotency_key"], unique=True)

    op.create_table(
        "supplier_payments",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("payment_number", sa.String(50), nullable=False, unique=True),
        sa.Column("idempotency_key", sa.String(120), nullable=False, unique=True),
        sa.Column("bill_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("supplier_bills.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("supplier_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("suppliers.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("amount", sa.BigInteger(), nullable=False),
        sa.Column("method", sa.String(20), nullable=False),
        sa.Column("status", sa.String(20), nullable=False),
        sa.Column("payment_date", sa.Date(), nullable=False),
        sa.Column("due_date", sa.Date()),
        sa.Column("bank_name", sa.String(100)),
        sa.Column("cheque_number", sa.String(80)),
        sa.Column("reference", sa.String(120)),
        sa.Column("created_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("created_at", sa.DateTime(timezone=True), nullable=False, server_default=sa.func.now()),
        sa.Column("cleared_at", sa.DateTime(timezone=True)),
        sa.CheckConstraint("amount > 0", name="ck_supplier_payments_amount_positive"),
        sa.CheckConstraint("method IN ('cash', 'bank_transfer', 'cheque')", name="ck_supplier_payments_method"),
        sa.CheckConstraint("status IN ('issued', 'cleared', 'bounced')", name="ck_supplier_payments_status"),
    )
    op.create_index("ix_supplier_payments_bill_id", "supplier_payments", ["bill_id"])
    op.create_index("ix_supplier_payments_supplier_id", "supplier_payments", ["supplier_id"])
    op.create_index("ix_supplier_payments_due_date", "supplier_payments", ["due_date"])
    op.create_index("ix_supplier_payments_cheque_number", "supplier_payments", ["cheque_number"])
    op.create_index("ix_supplier_payments_payment_number", "supplier_payments", ["payment_number"], unique=True)
    op.create_index("ix_supplier_payments_idempotency_key", "supplier_payments", ["idempotency_key"], unique=True)

    op.execute(
        "INSERT INTO accounting_accounts (id, code, name, account_type, normal_balance, parent_code, is_system, is_active) "
        f"VALUES ('{uuid.uuid4()}', 'CHEQUES_PAYABLE', 'چک‌های پرداختنی', 'liability', 'credit', '2000', true, true)"
    )


def downgrade() -> None:
    op.execute("DELETE FROM journal_entries WHERE source_type IN ('supplier_bill', 'supplier_payment', 'supplier_cheque_cleared', 'supplier_cheque_bounced', 'supplier_opening_balance')")
    op.drop_constraint("fk_journal_lines_supplier_id_suppliers", "journal_lines", type_="foreignkey")
    op.drop_index("ix_journal_lines_supplier_id", table_name="journal_lines")
    op.drop_column("journal_lines", "supplier_id")
    op.execute("DELETE FROM journal_lines WHERE account_code = 'CHEQUES_PAYABLE'")
    op.execute("DELETE FROM accounting_accounts WHERE code = 'CHEQUES_PAYABLE'")
    op.drop_index("ix_supplier_payments_idempotency_key", table_name="supplier_payments")
    op.drop_index("ix_supplier_payments_payment_number", table_name="supplier_payments")
    op.drop_index("ix_supplier_payments_cheque_number", table_name="supplier_payments")
    op.drop_index("ix_supplier_payments_due_date", table_name="supplier_payments")
    op.drop_index("ix_supplier_payments_supplier_id", table_name="supplier_payments")
    op.drop_index("ix_supplier_payments_bill_id", table_name="supplier_payments")
    op.drop_table("supplier_payments")
    op.drop_index("ix_supplier_bills_idempotency_key", table_name="supplier_bills")
    op.drop_index("ix_supplier_bills_bill_number", table_name="supplier_bills")
    op.drop_index("ix_supplier_bills_due_date", table_name="supplier_bills")
    op.drop_index("ix_supplier_bills_supplier_id", table_name="supplier_bills")
    op.drop_table("supplier_bills")
    op.drop_index("ix_suppliers_name", table_name="suppliers")
    op.drop_index("ix_suppliers_code", table_name="suppliers")
    op.drop_table("suppliers")
