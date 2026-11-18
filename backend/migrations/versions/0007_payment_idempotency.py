"""Add idempotency keys to payment receipts.

Revision ID: 0007_payment_idempotency
Revises: 0006_sales_cycle
"""
from alembic import op
import sqlalchemy as sa

revision = "0007_payment_idempotency"
down_revision = "0006_sales_cycle"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("payment_receipts", sa.Column("idempotency_key", sa.String(length=120), nullable=True))
    op.create_index("ix_payment_receipts_idempotency_key", "payment_receipts", ["idempotency_key"], unique=True)


def downgrade() -> None:
    op.drop_index("ix_payment_receipts_idempotency_key", table_name="payment_receipts")
    op.drop_column("payment_receipts", "idempotency_key")
