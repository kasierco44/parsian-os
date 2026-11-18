"""Widen journal debit and credit columns for large financial amounts.

Revision ID: 0012_journal_amounts_bigint
Revises: 0011_supplier_payables
"""
from alembic import op
import sqlalchemy as sa

revision = "0012_journal_amounts_bigint"
down_revision = "0011_supplier_payables"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.alter_column(
        "journal_lines", "debit",
        existing_type=sa.Integer(), type_=sa.BigInteger(),
        existing_nullable=False,
    )
    op.alter_column(
        "journal_lines", "credit",
        existing_type=sa.Integer(), type_=sa.BigInteger(),
        existing_nullable=False,
    )


def downgrade() -> None:
    op.alter_column(
        "journal_lines", "credit",
        existing_type=sa.BigInteger(), type_=sa.Integer(),
        existing_nullable=False,
    )
    op.alter_column(
        "journal_lines", "debit",
        existing_type=sa.BigInteger(), type_=sa.Integer(),
        existing_nullable=False,
    )
