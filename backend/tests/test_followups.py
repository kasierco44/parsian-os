from datetime import UTC, datetime
from types import SimpleNamespace
from uuid import uuid4

import pytest

from app.api.routes.followups import _scope
from app.models.followup import FollowUp
from app.schemas.followup import FollowUpCreate, FollowUpUpdate


def test_followup_create_requires_timezone_aware_due_date():
    payload = FollowUpCreate(
        customer_id=uuid4(),
        title="تماس پیگیری سفارش",
        due_at=datetime(2026, 11, 20, 10, 0, tzinfo=UTC),
    )
    assert payload.title == "تماس پیگیری سفارش"


def test_followup_status_is_restricted():
    with pytest.raises(ValueError):
        FollowUpUpdate(status="pending")


def test_followup_scope_limits_visitor_to_owned_records():
    visitor_id = uuid4()
    visitor = SimpleNamespace(is_superuser=False, id=visitor_id, roles=[SimpleNamespace(code="visitor")])
    statement = _scope(__import__("sqlalchemy").select(FollowUp), visitor)
    compiled = str(statement.compile(compile_kwargs={"literal_binds": True}))
    assert "owner_user_id" in compiled
    assert visitor_id.hex in compiled


def test_followup_scope_is_not_restricted_for_admin():
    admin = SimpleNamespace(is_superuser=True, id=uuid4(), roles=[])
    statement = _scope(__import__("sqlalchemy").select(FollowUp), admin)
    assert "WHERE followups.owner_user_id" not in str(statement)
