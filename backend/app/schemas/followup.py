from datetime import datetime
from uuid import UUID

from pydantic import BaseModel, ConfigDict, Field


class FollowUpCreate(BaseModel):
    customer_id: UUID
    title: str = Field(min_length=2, max_length=180)
    note: str | None = None
    due_at: datetime
    owner_user_id: UUID | None = None


class FollowUpUpdate(BaseModel):
    title: str | None = Field(default=None, min_length=2, max_length=180)
    note: str | None = None
    due_at: datetime | None = None
    status: str | None = Field(default=None, pattern=r"^(open|completed|cancelled)$")


class FollowUpRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: UUID
    customer_id: UUID
    owner_user_id: UUID
    created_by_user_id: UUID
    title: str
    note: str | None
    status: str
    due_at: datetime
    completed_at: datetime | None
    created_at: datetime
    updated_at: datetime
