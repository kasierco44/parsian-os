"""Create assigned CRM customers.

Revision ID: 0003_customers
Revises: 0002_offline_sync
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0003_customers"
down_revision = "0002_offline_sync"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "customers",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True, nullable=False),
        sa.Column("assigned_to_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("created_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("name", sa.String(length=180), nullable=False),
        sa.Column("phone", sa.String(length=32)),
        sa.Column("city", sa.String(length=100)),
        sa.Column("province", sa.String(length=100)),
        sa.Column("address", sa.String(length=500)),
        sa.Column("customer_type", sa.String(length=40), nullable=False, server_default="wholesaler"),
        sa.Column("notes", sa.String(length=2000)),
        sa.Column("status", sa.String(length=24), nullable=False, server_default="active"),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_customers_assigned_to_user_id", "customers", ["assigned_to_user_id"])
    op.create_index("ix_customers_name", "customers", ["name"])
    op.create_index("ix_customers_phone", "customers", ["phone"])
    op.create_index("ix_customers_city", "customers", ["city"])
    op.create_index("ix_customers_province", "customers", ["province"])


def downgrade() -> None:
    op.drop_index("ix_customers_province", table_name="customers")
    op.drop_index("ix_customers_city", table_name="customers")
    op.drop_index("ix_customers_phone", table_name="customers")
    op.drop_index("ix_customers_name", table_name="customers")
    op.drop_index("ix_customers_assigned_to_user_id", table_name="customers")
    op.drop_table("customers")
