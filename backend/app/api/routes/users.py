from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.core.security import hash_password
from app.db.session import get_db
from app.models.identity import Role, User
from app.schemas.identity import UserProvisionRequest, UserRead
from app.services.audit import record_audit_event

router = APIRouter(prefix="/users", tags=["user administration"])
ROLE_NAMES = {"seller": "ویزیتور", "accountant": "حسابدار", "manager": "مدیر"}


@router.post("", response_model=UserRead, status_code=status.HTTP_201_CREATED)
def provision_user(payload: UserProvisionRequest, actor: User = Depends(get_current_user), db: Session = Depends(get_db)):
    is_manager = actor.is_superuser or any(role.code in {"manager", "admin", "owner"} for role in actor.roles)
    if not is_manager:
        raise HTTPException(status_code=403, detail="فقط مدیر مجاز به ایجاد کاربر است.")
    if payload.role_code == "manager" and not actor.is_superuser:
        raise HTTPException(status_code=403, detail="ایجاد مدیر جدید فقط با دسترسی مدیر ارشد ممکن است.")
    role = db.scalar(select(Role).where(Role.code == payload.role_code))
    if role is None:
        role = Role(code=payload.role_code, name_fa=ROLE_NAMES[payload.role_code], is_system=True)
        db.add(role)
        db.flush()
    user = User(
        username=payload.username,
        email=str(payload.email) if payload.email else None,
        full_name=payload.full_name,
        password_hash=hash_password(payload.temporary_password),
        is_active=True,
        must_change_password=True,
    )
    user.roles.append(role)
    db.add(user)
    try:
        db.flush()
        record_audit_event(db, action="user_provisioned", entity_type="user", actor_user_id=actor.id, entity_id=str(user.id), details={"role": payload.role_code})
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="نام کاربری یا ایمیل قبلاً ثبت شده است.") from None
    db.refresh(user)
    return user
