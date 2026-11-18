from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, status
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.core.audit import record_audit
from app.core.authorization import require_permission, require_superuser
from app.core.security import hash_password
from app.db.session import get_db
from app.models.identity import Permission, Role, User
from app.schemas.admin import (
    AdminUserCreate,
    PermissionAssignment,
    PermissionRead,
    RoleAssignment,
    RoleCreate,
    UserStatusUpdate,
)
from app.schemas.identity import UserRead

router = APIRouter(prefix="/admin", tags=["administration"])


@router.get("/users", response_model=list[UserRead], summary="List users (requires users.read)")
def list_users(
    _: User = Depends(require_permission("users.read")),
    db: Session = Depends(get_db),
) -> list[User]:
    return list(db.scalars(select(User).order_by(User.created_at.desc())).all())


@router.post("/users", response_model=UserRead, status_code=status.HTTP_201_CREATED)
def create_user(
    payload: AdminUserCreate,
    actor: User = Depends(require_superuser()),
    db: Session = Depends(get_db),
) -> User:
    roles = list(db.scalars(select(Role).where(Role.code.in_(payload.role_codes))).all())
    found_codes = {role.code for role in roles}
    missing = sorted(set(payload.role_codes) - found_codes)
    if missing:
        raise HTTPException(status_code=422, detail={"unknown_role_codes": missing})
    user = User(
        username=payload.username,
        email=str(payload.email) if payload.email else None,
        full_name=payload.full_name,
        password_hash=hash_password(payload.password),
        is_active=True,
        is_superuser=False,
        must_change_password=True,
        roles=roles,
    )
    db.add(user)
    try:
        db.flush()
        record_audit(
            db, action="user.created", entity_type="user", entity_id=user.id,
            actor_user_id=actor.id, details={"username": user.username, "role_codes": sorted(found_codes)},
        )
        db.commit()
        db.refresh(user)
        return user
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="Username or email already exists") from None


@router.patch("/users/{user_id}/roles", response_model=UserRead)
def set_user_roles(
    user_id: UUID,
    payload: RoleAssignment,
    actor: User = Depends(require_superuser()),
    db: Session = Depends(get_db),
) -> User:
    user = db.get(User, user_id)
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")
    if user.is_superuser and user.id == actor.id:
        raise HTTPException(status_code=409, detail="You cannot change your own superuser account roles")
    roles = list(db.scalars(select(Role).where(Role.code.in_(payload.role_codes))).all())
    found_codes = {role.code for role in roles}
    missing = sorted(set(payload.role_codes) - found_codes)
    if missing:
        raise HTTPException(status_code=422, detail={"unknown_role_codes": missing})
    user.roles = roles
    record_audit(
        db, action="user.roles_changed", entity_type="user", entity_id=user.id,
        actor_user_id=actor.id, details={"role_codes": sorted(found_codes)},
    )
    db.commit()
    db.refresh(user)
    return user


@router.patch("/users/{user_id}/status", response_model=UserRead)
def set_user_status(
    user_id: UUID,
    payload: UserStatusUpdate,
    actor: User = Depends(require_superuser()),
    db: Session = Depends(get_db),
) -> User:
    user = db.get(User, user_id)
    if user is None:
        raise HTTPException(status_code=404, detail="User not found")
    if user.id == actor.id and not payload.is_active:
        raise HTTPException(status_code=409, detail="You cannot deactivate your own account")
    if user.is_superuser and not payload.is_active:
        other_admin = db.scalar(
            select(User.id).where(User.is_superuser.is_(True), User.is_active.is_(True), User.id != user.id).limit(1)
        )
        if other_admin is None:
            raise HTTPException(status_code=409, detail="Cannot deactivate the last active superuser")
    user.is_active = payload.is_active
    record_audit(
        db, action="user.status_changed", entity_type="user", entity_id=user.id,
        actor_user_id=actor.id, details={"is_active": user.is_active},
    )
    db.commit()
    db.refresh(user)
    return user


@router.get("/roles", summary="List roles (requires roles.read)")
def list_roles(
    _: User = Depends(require_permission("roles.read")),
    db: Session = Depends(get_db),
) -> list[dict[str, object]]:
    roles = db.scalars(select(Role).order_by(Role.code)).all()
    return [
        {
            "code": role.code,
            "name_fa": role.name_fa,
            "description": role.description,
            "permissions": sorted(permission.code for permission in role.permissions),
        }
        for role in roles
    ]


@router.post("/roles", status_code=status.HTTP_201_CREATED)
def create_role(
    payload: RoleCreate,
    actor: User = Depends(require_superuser()),
    db: Session = Depends(get_db),
) -> dict[str, str | None]:
    role = Role(code=payload.code, name_fa=payload.name_fa, description=payload.description, is_system=False)
    db.add(role)
    try:
        db.flush()
        record_audit(
            db, action="role.created", entity_type="role", entity_id=role.id,
            actor_user_id=actor.id, details={"code": role.code},
        )
        db.commit()
        return {"code": role.code, "name_fa": role.name_fa, "description": role.description}
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="Role code already exists") from None


@router.get("/permissions", response_model=list[PermissionRead])
def list_permissions(
    _: User = Depends(require_superuser()),
    db: Session = Depends(get_db),
) -> list[Permission]:
    return list(db.scalars(select(Permission).order_by(Permission.code)).all())


@router.put("/roles/{role_code}/permissions")
def set_role_permissions(
    role_code: str,
    payload: PermissionAssignment,
    actor: User = Depends(require_superuser()),
    db: Session = Depends(get_db),
) -> dict[str, object]:
    role = db.scalar(select(Role).where(Role.code == role_code))
    if role is None:
        raise HTTPException(status_code=404, detail="Role not found")
    permissions = list(
        db.scalars(select(Permission).where(Permission.code.in_(payload.permission_codes))).all()
    )
    found_codes = {permission.code for permission in permissions}
    missing = sorted(set(payload.permission_codes) - found_codes)
    if missing:
        raise HTTPException(status_code=422, detail={"unknown_permission_codes": missing})
    role.permissions = permissions
    record_audit(
        db, action="role.permissions_changed", entity_type="role", entity_id=role.id,
        actor_user_id=actor.id, details={"permission_codes": sorted(found_codes)},
    )
    db.commit()
    return {"role_code": role.code, "permission_codes": sorted(found_codes)}


@router.get("/audit-events", summary="List audit events (requires audit.read)")
def list_audit_events(
    _: User = Depends(require_permission("audit.read")),
    db: Session = Depends(get_db),
    limit: int = 100,
    offset: int = 0,
) -> list[dict[str, object]]:
    from app.models.audit_event import AuditEvent

    if limit < 1 or limit > 500 or offset < 0:
        raise HTTPException(status_code=422, detail="Invalid pagination parameters")
    events = db.scalars(
        select(AuditEvent).order_by(AuditEvent.created_at.desc()).limit(limit).offset(offset)
    ).all()
    return [
        {
            "id": str(event.id),
            "actor_user_id": str(event.actor_user_id) if event.actor_user_id else None,
            "action": event.action,
            "entity_type": event.entity_type,
            "entity_id": event.entity_id,
            "details": event.details,
            "created_at": event.created_at.isoformat(),
        }
        for event in events
    ]
