"""Create customer master data table.

Revision ID: 0003_customers
Revises: 0002_auth_lockout
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0003_customers"
down_revision = "0002_auth_lockout"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "customers",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True, nullable=False),
        sa.Column("customer_code", sa.String(length=32), nullable=False),
        sa.Column("name", sa.String(length=180), nullable=False),
        sa.Column("customer_type", sa.String(length=24), server_default="distributor", nullable=False),
        sa.Column("phone", sa.String(length=32)),
        sa.Column("mobile", sa.String(length=32)),
        sa.Column("province", sa.String(length=80)),
        sa.Column("city", sa.String(length=80)),
        sa.Column("address", sa.Text()),
        sa.Column("postal_code", sa.String(length=20)),
        sa.Column("economic_code", sa.String(length=32)),
        sa.Column("national_id", sa.String(length=32)),
        sa.Column("source", sa.String(length=40)),
        sa.Column("assigned_to_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="SET NULL")),
        sa.Column("credit_limit", sa.Numeric(precision=16, scale=2), server_default="0", nullable=False),
        sa.Column("payment_terms_days", sa.Integer(), server_default="0", nullable=False),
        sa.Column("is_active", sa.Boolean(), server_default=sa.true(), nullable=False),
        sa.Column("notes", sa.Text()),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_customers_customer_code", "customers", ["customer_code"], unique=True)
    op.create_index("ix_customers_name", "customers", ["name"])
    op.create_index("ix_customers_phone", "customers", ["phone"])
    op.create_index("ix_customers_mobile", "customers", ["mobile"])
    op.create_index("ix_customers_province", "customers", ["province"])
    op.create_index("ix_customers_province_city", "customers", ["province", "city"])
    op.create_index("ix_customers_assigned_to_user_id", "customers", ["assigned_to_user_id"])


def downgrade() -> None:
    op.drop_index("ix_customers_assigned_to_user_id", table_name="customers")
    op.drop_index("ix_customers_province_city", table_name="customers")
    op.drop_index("ix_customers_province", table_name="customers")
    op.drop_index("ix_customers_mobile", table_name="customers")
    op.drop_index("ix_customers_phone", table_name="customers")
    op.drop_index("ix_customers_name", table_name="customers")
    op.drop_index("ix_customers_customer_code", table_name="customers")
    op.drop_table("customers")
