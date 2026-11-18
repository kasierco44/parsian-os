from pydantic import BaseModel, Field

class LoginInput(BaseModel):
    username: str = Field(min_length=1, max_length=80)
    password: str = Field(min_length=1, max_length=256)

class TokenOutput(BaseModel):
    access_token: str
    token_type: str = "bearer"

class UserOutput(BaseModel):
    id: int
    username: str
    is_active: bool
    roles: list[str]
    permissions: list[str]
