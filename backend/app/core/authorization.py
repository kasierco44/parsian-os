from collections.abc import Callable

from fastapi import Depends, HTTPException, status

from app.api.routes.auth import get_current_user
from app.models.identity import User


def require_superuser() -> Callable:
    """Dependency that restricts an endpoint to explicitly privileged users."""

    def checker(current_user: User = Depends(get_current_user)) -> User:
        if not current_user.is_superuser:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="Administrator privileges are required",
            )
        return current_user

    return checker


def require_permission(permission_code: str) -> Callable:
    """Dependency that enforces a role-derived permission; superusers bypass RBAC."""

    def checker(current_user: User = Depends(get_current_user)) -> User:
        if current_user.is_superuser:
            return current_user

        granted = {
            permission.code
            for role in current_user.roles
            for permission in role.permissions
        }
        if permission_code not in granted:
            raise HTTPException(
                status_code=status.HTTP_403_FORBIDDEN,
                detail="Insufficient permissions",
            )
        return current_user

    return checker
