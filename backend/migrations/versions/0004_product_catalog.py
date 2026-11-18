"""Create versioned product catalog and private media metadata.

Revision ID: 0004_product_catalog
Revises: 0003_customers
"""
from alembic import op
import sqlalchemy as sa
from sqlalchemy.dialects import postgresql

revision = "0004_product_catalog"
down_revision = "0003_customers"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.create_table(
        "products",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True, nullable=False),
        sa.Column("sku", sa.String(length=80), nullable=False),
        sa.Column("name", sa.String(length=180), nullable=False),
        sa.Column("category", sa.String(length=100), nullable=False, server_default="wire"),
        sa.Column("vehicle_model", sa.String(length=120)),
        sa.Column("description", sa.Text()),
        sa.Column("unit_price", sa.Integer()),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("catalog_version", sa.Integer(), nullable=False, server_default="1"),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_products_sku", "products", ["sku"], unique=True)
    op.create_index("ix_products_name", "products", ["name"])
    op.create_table(
        "catalog_state",
        sa.Column("id", sa.Integer(), primary_key=True),
        sa.Column("version", sa.Integer(), nullable=False, server_default="0"),
        sa.Column("updated_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_table(
        "product_media",
        sa.Column("id", postgresql.UUID(as_uuid=True), primary_key=True, nullable=False),
        sa.Column("product_id", postgresql.UUID(as_uuid=True), sa.ForeignKey("products.id", ondelete="CASCADE"), nullable=False),
        sa.Column("storage_key", sa.String(length=500), nullable=False),
        sa.Column("file_name", sa.String(length=180), nullable=False),
        sa.Column("mime_type", sa.String(length=100), nullable=False),
        sa.Column("size_bytes", sa.Integer(), nullable=False),
        sa.Column("checksum_sha256", sa.String(length=64), nullable=False),
        sa.Column("media_version", sa.Integer(), nullable=False, server_default="1"),
        sa.Column("catalog_version", sa.Integer(), nullable=False),
        sa.Column("is_active", sa.Boolean(), nullable=False, server_default=sa.true()),
        sa.Column("created_at", sa.DateTime(timezone=True), server_default=sa.func.now(), nullable=False),
    )
    op.create_index("ix_product_media_product_id", "product_media", ["product_id"])
    op.create_index("ix_product_media_catalog_version", "product_media", ["catalog_version"])


def downgrade() -> None:
    op.drop_index("ix_product_media_catalog_version", table_name="product_media")
    op.drop_index("ix_product_media_product_id", table_name="product_media")
    op.drop_table("product_media")
    op.drop_table("catalog_state")
    op.drop_index("ix_products_name", table_name="products")
    op.drop_index("ix_products_sku", table_name="products")
    op.drop_table("products")
