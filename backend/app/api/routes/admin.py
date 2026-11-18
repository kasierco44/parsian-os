from fastapi import APIRouter, Depends
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.authorization import require_permission
from app.db.session import get_db
from app.models.identity import Role, User
from app.schemas.identity import UserRead

router = APIRouter(prefix="/admin", tags=["administration"])


@router.get(
    "/users",
    response_model=list[UserRead],
    summary="List users (requires users.read)",
)
def list_users(
    _: User = Depends(require_permission("users.read")),
    db: Session = Depends(get_db),
) -> list[User]:
    return list(db.scalars(select(User).order_by(User.created_at.desc())).all())


@router.get(
    "/roles",
    summary="List roles (requires roles.read)",
)
def list_roles(
    _: User = Depends(require_permission("roles.read")),
    db: Session = Depends(get_db),
) -> list[dict[str, str | None]]:
    roles = db.scalars(select(Role).order_by(Role.code)).all()
    return [
        {"code": role.code, "name_fa": role.name_fa, "description": role.description}
        for role in roles
    ]
