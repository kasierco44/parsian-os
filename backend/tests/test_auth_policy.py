from datetime import UTC, datetime, timedelta
from types import SimpleNamespace

import pytest
from fastapi import HTTPException

from app.api.routes import auth
from app.schemas.auth import LoginRequest


class FakeSession:
    def __init__(self, user):
        self.user = user
        self.commits = 0

    def scalar(self, _statement):
        return self.user

    def commit(self):
        self.commits += 1


def make_user():
    return SimpleNamespace(
        id="2b7f67e1-4a12-4ef0-9fd3-bd38eae2f041",
        username="staff",
        is_active=True,
        password_hash="hash",
        failed_login_attempts=0,
        locked_until=None,
    )


def test_fifth_failed_login_locks_account(monkeypatch):
    user = make_user()
    db = FakeSession(user)
    monkeypatch.setattr(auth, "verify_password", lambda *_: False)

    for attempt in range(auth.MAX_FAILED_LOGINS):
        with pytest.raises(HTTPException) as error:
            auth.login(LoginRequest(username="staff", password="wrong"), db)
        assert error.value.status_code == 401

    assert user.failed_login_attempts == 0
    assert user.locked_until is not None
    assert user.locked_until > datetime.now(UTC)
    with pytest.raises(HTTPException) as locked:
        auth.login(LoginRequest(username="staff", password="correct"), db)
    assert locked.value.status_code == 423


def test_successful_login_resets_failure_counter(monkeypatch):
    user = make_user()
    user.failed_login_attempts = 3
    user.locked_until = datetime.now(UTC) - timedelta(minutes=1)
    db = FakeSession(user)
    monkeypatch.setattr(auth, "verify_password", lambda *_: True)
    monkeypatch.setattr(auth, "create_access_token", lambda **_: "test-token")

    result = auth.login(LoginRequest(username="staff", password="correct"), db)

    assert result.access_token == "test-token"
    assert user.failed_login_attempts == 0
    assert user.locked_until is None
