import uuid
from datetime import datetime
from decimal import Decimal

from sqlalchemy import Boolean, DateTime, ForeignKey, Index, Numeric, String, Text, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column, relationship

from app.db.base import Base


class Customer(Base):
    __tablename__ = "customers"
    __table_args__ = (
        Index("ix_customers_name", "name"),
        Index("ix_customers_province_city", "province", "city"),
    )

    id: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), primary_key=True, default=uuid.uuid4)
    customer_code: Mapped[str] = mapped_column(String(32), unique=True, index=True, nullable=False)
    name: Mapped[str] = mapped_column(String(180), nullable=False)
    customer_type: Mapped[str] = mapped_column(String(24), default="distributor", nullable=False)
    phone: Mapped[str | None] = mapped_column(String(32), index=True)
    mobile: Mapped[str | None] = mapped_column(String(32), index=True)
    province: Mapped[str | None] = mapped_column(String(80), index=True)
    city: Mapped[str | None] = mapped_column(String(80))
    address: Mapped[str | None] = mapped_column(Text)
    postal_code: Mapped[str | None] = mapped_column(String(20))
    economic_code: Mapped[str | None] = mapped_column(String(32))
    national_id: Mapped[str | None] = mapped_column(String(32))
    source: Mapped[str | None] = mapped_column(String(40))
    assigned_to_user_id: Mapped[uuid.UUID | None] = mapped_column(UUID(as_uuid=True), ForeignKey("users.id", ondelete="SET NULL"), index=True)
    credit_limit: Mapped[Decimal] = mapped_column(Numeric(16, 2), default=Decimal("0"), nullable=False)
    payment_terms_days: Mapped[int] = mapped_column(default=0, nullable=False)
    is_active: Mapped[bool] = mapped_column(Boolean, default=True, nullable=False)
    notes: Mapped[str | None] = mapped_column(Text)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), nullable=False)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False)

    assigned_to = relationship("User", lazy="joined")
