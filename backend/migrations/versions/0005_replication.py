"""Add office/cloud replication journal and peer cursors.

Revision ID: 0005_replication
Revises: 0004_product_catalog
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0005_replication"
down_revision = "0004_product_catalog"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("customers", sa.Column("replication_version", sa.Integer(), nullable=False, server_default="0"))
    op.add_column("products", sa.Column("replication_version", sa.Integer(), nullable=False, server_default="0"))
    op.create_table(
        "replication_events",
        sa.Column("id", sa.BigInteger(), primary_key=True, autoincrement=True),
        sa.Column("event_uuid", postgresql.UUID(as_uuid=True), nullable=False),
        sa.Column("origin_node", sa.String(length=80), nullable=False),
        sa.Column("entity_type", sa.String(length=40), nullable=False),
        sa.Column("entity_id", sa.String(length=100), nullable=False),
        sa.Column("payload", sa.JSON(), nullable=False),
        sa.Column("version", sa.Integer(), nullable=False),
        sa.Column("deleted", sa.Boolean(), nullable=False, server_default=sa.false()),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.UniqueConstraint("origin_node", "event_uuid", name="uq_replication_origin_event"),
    )
    op.create_index("ix_replication_events_origin_node", "replication_events", ["origin_node"])
    op.create_table(
        "replication_state",
        sa.Column("peer_node", sa.String(length=80), primary_key=True),
        sa.Column("inbound_cursor", sa.BigInteger(), nullable=False, server_default="0"),
        sa.Column("outbound_cursor", sa.BigInteger(), nullable=False, server_default="0"),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )


def downgrade() -> None:
    op.drop_table("replication_state")
    op.drop_index("ix_replication_events_origin_node", table_name="replication_events")
    op.drop_table("replication_events")
    op.drop_column("products", "replication_version")
    op.drop_column("customers", "replication_version")
