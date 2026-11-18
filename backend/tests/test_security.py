from uuid import uuid4

import pytest
from jwt import InvalidTokenError

from app.core.security import create_access_token, decode_access_token, hash_password, verify_password


def test_password_hash_verifies_without_storing_plaintext() -> None:
    password = "Correct-Horse-Battery-42!"
    hashed = hash_password(password)

    assert hashed != password
    assert verify_password(password, hashed)
    assert not verify_password("wrong-password", hashed)


def test_access_token_round_trip() -> None:
    user_id = uuid4()

    token = create_access_token(subject=user_id)

    assert decode_access_token(token) == user_id


def test_expired_access_token_is_rejected() -> None:
    token = create_access_token(subject=uuid4(), expires_minutes=-1)

    with pytest.raises(InvalidTokenError):
        decode_access_token(token)
