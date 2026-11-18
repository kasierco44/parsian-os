"""Create chart of accounts and seed the initial PARSIAN account catalog.

Revision ID: 0008_chart_of_accounts
Revises: 0007_payment_idempotency
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0008_chart_of_accounts"
down_revision = "0007_payment_idempotency"
branch_labels = None
depends_on = None

accounts = [
    ("1000", "دارایی‌ها", "asset", "debit", None),
    ("1010", "صندوق", "asset", "debit", "1000"),
    ("1020", "بانک", "asset", "debit", "1000"),
    ("1100", "حساب‌های دریافتنی", "asset", "debit", "1000"),
    ("1200", "موجودی کالا", "asset", "debit", "1000"),
    ("2000", "بدهی‌ها", "liability", "credit", None),
    ("2010", "حساب‌های پرداختنی", "liability", "credit", "2000"),
    ("2020", "پورسانت پرداختنی", "liability", "credit", "2000"),
    ("3000", "حقوق مالکانه", "equity", "credit", None),
    ("4000", "درآمدها", "revenue", "credit", None),
    ("4010", "فروش کالا", "revenue", "credit", "4000"),
    ("5000", "بهای تمام‌شده و هزینه‌ها", "expense", "debit", None),
    ("5010", "بهای تمام‌شده کالای فروش‌رفته", "expense", "debit", "5000"),
    ("5020", "هزینه پورسانت فروش", "expense", "debit", "5000"),
    ("5030", "کسری و ضایعات انبار", "expense", "debit", "5000"),
    ("5040", "تعدیلات موجودی", "revenue", "credit", "4000"),
]

def upgrade() -> None:
    op.create_table(
        "accounting_accounts",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True),
        sa.Column("code", sa.String(40), nullable=False, unique=True),
        sa.Column("name", sa.String(160), nullable=False),
        sa.Column("account_type", sa.String(20), nullable=False),
        sa.Column("normal_balance", sa.String(8), nullable=False),
        sa.Column("parent_code", sa.String(40), sa.ForeignKey("accounting_accounts.code", ondelete="RESTRICT")),
        sa.Column("is_system", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.CheckConstraint("account_type IN ('asset','liability','equity','revenue','expense')", name="ck_accounting_accounts_type"),
        sa.CheckConstraint("normal_balance IN ('debit','credit')", name="ck_accounting_accounts_normal_balance"),
    )
    op.create_index("ix_accounting_accounts_code", "accounting_accounts", ["code"], unique=True)
    table = sa.table(
        "accounting_accounts",
        sa.column("id", postgresql.UUID(as_uuid=True)),
        sa.column("code", sa.String),
        sa.column("name", sa.String),
        sa.column("account_type", sa.String),
        sa.column("normal_balance", sa.String),
        sa.column("parent_code", sa.String),
        sa.column("is_system", sa.Boolean),
        sa.column("is_active", sa.Boolean),
    )
    import uuid
    op.bulk_insert(table, [
        {"id": uuid.uuid4(), "code": code, "name": name, "account_type": kind,
         "normal_balance": balance, "parent_code": parent, "is_system": True, "is_active": True}
        for code, name, kind, balance, parent in accounts
    ])


def downgrade() -> None:
    op.drop_index("ix_accounting_accounts_code", table_name="accounting_accounts")
    op.drop_table("accounting_accounts")
