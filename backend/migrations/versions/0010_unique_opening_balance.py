"""Prevent duplicate opening-balance journal documents.

Revision ID: 0010_unique_opening_balance
Revises: 0009_journal_account_fk
"""
from alembic import op
import sqlalchemy as sa

revision = "0010_unique_opening_balance"
down_revision = "0009_journal_account_fk"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_index(
        "uq_journal_entries_single_opening_balance",
        "journal_entries",
        ["source_type"],
        unique=True,
        postgresql_where=sa.text("source_type = 'opening_balance'"),
    )


def downgrade() -> None:
    op.drop_index(
        "uq_journal_entries_single_opening_balance",
        table_name="journal_entries",
    )
