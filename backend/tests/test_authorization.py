from types import SimpleNamespace

import pytest
from fastapi import HTTPException

from app.core.authorization import require_permission, require_superuser


def user(*, is_superuser: bool = False, permissions: tuple[str, ...] = ()) -> SimpleNamespace:
    return SimpleNamespace(
        is_superuser=is_superuser,
        roles=[
            SimpleNamespace(
                permissions=[SimpleNamespace(code=code) for code in permissions]
            )
        ],
    )


def test_superuser_bypasses_permission_matrix() -> None:
    current = user(is_superuser=True)
    assert require_permission("users.read")(current_user=current) is current


def test_user_with_permission_is_allowed() -> None:
    current = user(permissions=("users.read",))
    assert require_permission("users.read")(current_user=current) is current


def test_user_without_permission_is_forbidden() -> None:
    current = user(permissions=("orders.read",))
    with pytest.raises(HTTPException) as exc:
        require_permission("users.read")(current_user=current)
    assert exc.value.status_code == 403


def test_non_superuser_is_denied_admin_only_route() -> None:
    current = user(permissions=("users.read",))
    with pytest.raises(HTTPException) as exc:
        require_superuser()(current_user=current)
    assert exc.value.status_code == 403
