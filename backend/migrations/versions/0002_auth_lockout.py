"""Add persistent authentication lockout state.

Revision ID: 0002_auth_lockout
Revises: 0001_identity_audit
"""
from alembic import op
import sqlalchemy as sa

revision = "0002_auth_lockout"
down_revision = "0001_identity_audit"
branch_labels = None
depends_on = None


def upgrade() -> None:
    op.add_column("users", sa.Column("failed_login_attempts", sa.Integer(), nullable=False, server_default="0"))
    op.add_column("users", sa.Column("locked_until", sa.DateTime(timezone=True), nullable=True))


def downgrade() -> None:
    op.drop_column("users", "locked_until")
    op.drop_column("users", "failed_login_attempts")
