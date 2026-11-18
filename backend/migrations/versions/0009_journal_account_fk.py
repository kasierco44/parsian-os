"""Enforce journal account references against the chart of accounts.

Revision ID: 0009_journal_account_fk
Revises: 0008_chart_of_accounts
"""
from alembic import op

revision = "0009_journal_account_fk"
down_revision = "0008_chart_of_accounts"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_foreign_key(
        "fk_journal_lines_account_code_accounting_accounts",
        "journal_lines", "accounting_accounts",
        ["account_code"], ["code"], ondelete="RESTRICT",
    )


def downgrade() -> None:
    op.drop_constraint(
        "fk_journal_lines_account_code_accounting_accounts",
        "journal_lines", type_="foreignkey",
    )
