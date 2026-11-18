"""Add persistent visitor follow-ups.

Revision ID: 0004_followups
Revises: 0003_customers
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0004_followups"
down_revision = "0003_customers"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "followups",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True, nullable=False),
        sa.Column("customer_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("customers.id", ondelete="CASCADE"), nullable=False),
        sa.Column("owner_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("created_by_user_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("users.id", ondelete="RESTRICT"), nullable=False),
        sa.Column("title", sa.String(length=180), nullable=False),
        sa.Column("note", sa.Text()),
        sa.Column("status", sa.String(length=20), server_default="open", nullable=False),
        sa.Column("due_at", sa.DateTime(timezone=True), nullable=False),
        sa.Column("completed_at", sa.DateTime(timezone=True)),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.CheckConstraint("status IN ('open', 'completed', 'cancelled')", name="ck_followups_status"),
    )
    op.create_index("ix_followups_owner_due", "followups", ["owner_user_id", "due_at"])
    op.create_index("ix_followups_customer_created", "followups", ["customer_id", "created_at"])


def downgrade() -> None:
    op.drop_index("ix_followups_customer_created", table_name="followups")
    op.drop_index("ix_followups_owner_due", table_name="followups")
    op.drop_table("followups")
