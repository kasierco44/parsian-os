import uuid
from datetime import datetime

from sqlalchemy import BigInteger, DateTime, Integer, JSON, String, UniqueConstraint, func
from sqlalchemy.dialects.postgresql import UUID
from sqlalchemy.orm import Mapped, mapped_column

from app.db.base import Base


class ReplicationEvent(Base):
    __tablename__ = "replication_events"
    __table_args__ = (UniqueConstraint("origin_node", "event_uuid", name="uq_replication_origin_event"),)

    id: Mapped[int] = mapped_column(BigInteger, primary_key=True, autoincrement=True)
    event_uuid: Mapped[uuid.UUID] = mapped_column(UUID(as_uuid=True), nullable=False)
    origin_node: Mapped[str] = mapped_column(String(80), nullable=False, index=True)
    entity_type: Mapped[str] = mapped_column(String(40), nullable=False)
    entity_id: Mapped[str] = mapped_column(String(100), nullable=False)
    payload: Mapped[dict] = mapped_column(JSON, nullable=False, default=dict)
    version: Mapped[int] = mapped_column(Integer, nullable=False)
    deleted: Mapped[bool] = mapped_column(default=False, nullable=False)
    created_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), nullable=False)


class ReplicationState(Base):
    __tablename__ = "replication_state"

    peer_node: Mapped[str] = mapped_column(String(80), primary_key=True)
    inbound_cursor: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0)
    outbound_cursor: Mapped[int] = mapped_column(BigInteger, nullable=False, default=0)
    updated_at: Mapped[datetime] = mapped_column(DateTime(timezone=True), server_default=func.now(), onupdate=func.now(), nullable=False)
