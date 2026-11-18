from uuid import UUID

from pydantic import BaseModel, ConfigDict, EmailStr, Field


class AdminUserCreate(BaseModel):
    username: str = Field(min_length=3, max_length=80, pattern=r"^[a-zA-Z0-9_.-]+$")
    email: EmailStr | None = None
    full_name: str = Field(min_length=2, max_length=160)
    password: str = Field(min_length=12, max_length=128)
    role_codes: list[str] = Field(default_factory=list, max_length=20)


class RoleCreate(BaseModel):
    code: str = Field(min_length=2, max_length=64, pattern=r"^[a-z][a-z0-9_.-]+$")
    name_fa: str = Field(min_length=2, max_length=100)
    description: str | None = Field(default=None, max_length=255)


class RoleAssignment(BaseModel):
    role_codes: list[str] = Field(max_length=20)


class PermissionAssignment(BaseModel):
    permission_codes: list[str] = Field(max_length=100)


class UserStatusUpdate(BaseModel):
    is_active: bool


class PermissionRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: UUID
    code: str
    description: str | None
