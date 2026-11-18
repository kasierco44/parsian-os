from uuid import UUID

from pydantic import BaseModel, ConfigDict, EmailStr, Field


class UserCreate(BaseModel):
    username: str = Field(min_length=3, max_length=80, pattern=r"^[a-zA-Z0-9_.-]+$")
    email: EmailStr | None = None
    full_name: str = Field(min_length=2, max_length=160)
    password: str = Field(min_length=12, max_length=128)


class UserRead(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: UUID
    username: str
    email: str | None
    full_name: str
    is_active: bool
    must_change_password: bool
    is_superuser: bool = False
    role_codes: list[str] = Field(default_factory=list)


class UserProvisionRequest(BaseModel):
    username: str = Field(min_length=3, max_length=80, pattern=r"^[a-zA-Z0-9_.-]+$")
    email: EmailStr | None = None
    full_name: str = Field(min_length=2, max_length=160)
    temporary_password: str = Field(min_length=12, max_length=128)
    role_code: str = Field(pattern=r"^(seller|accountant|manager)$")
