from typing import Any, Literal
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


EntityType = Literal["customer", "customer_note", "followup", "visit", "order_draft", "proforma_draft", "product_favorite", "task"]


class SyncOperationIn(BaseModel):
    model_config = ConfigDict(extra="forbid")
    id: UUID
    entity_type: EntityType
    entity_id: str = Field(min_length=1, max_length=100)
    operation: Literal["upsert", "delete"]
    payload: dict[str, Any] = Field(default_factory=dict)
    base_version: int | None = Field(default=None, ge=0)


class SyncPushIn(BaseModel):
    operations: list[SyncOperationIn] = Field(max_length=100)


class SyncOperationResult(BaseModel):
    id: UUID
    status: Literal["accepted", "duplicate", "conflict", "rejected"]
    version: int | None = None
    message: str | None = None


class SyncPushOut(BaseModel):
    results: list[SyncOperationResult]


class SyncChangeOut(BaseModel):
    entity_type: str
    entity_id: str
    payload: dict[str, Any]
    version: int
    deleted: bool


class SyncPullOut(BaseModel):
    changes: list[SyncChangeOut]
    next_cursor: int
