from typing import Any
from uuid import UUID

from pydantic import BaseModel, Field


class ReplicationEventIn(BaseModel):
    event_uuid: UUID
    origin_node: str = Field(min_length=1, max_length=80)
    entity_type: str = Field(min_length=1, max_length=40)
    entity_id: str = Field(min_length=1, max_length=100)
    payload: dict[str, Any] = Field(default_factory=dict)
    version: int = Field(ge=1)
    deleted: bool = False


class ReplicationPushIn(BaseModel):
    events: list[ReplicationEventIn] = Field(max_length=200)


class ReplicationEventOut(ReplicationEventIn):
    pass


class ReplicationPullOut(BaseModel):
    events: list[ReplicationEventOut]
    next_cursor: int
