import pytest
from pydantic import ValidationError

from app.schemas.auth import PasswordChangeRequest
from app.schemas.identity import UserProvisionRequest


def test_user_provisioning_requires_temporary_password_and_valid_role() -> None:
    payload = UserProvisionRequest(
        username="seller_arak",
        full_name="ویزیتور اراک",
        temporary_password="Temp-Password-123!",
        role_code="seller",
    )
    assert payload.role_code == "seller"


def test_user_provisioning_rejects_unprivileged_role_code() -> None:
    with pytest.raises(ValidationError):
        UserProvisionRequest(
            username="seller_arak",
            full_name="ویزیتور اراک",
            temporary_password="Temp-Password-123!",
            role_code="superuser",
        )


def test_password_change_requires_twelve_characters() -> None:
    with pytest.raises(ValidationError):
        PasswordChangeRequest(current_password="old", new_password="short")
