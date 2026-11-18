from uuid import uuid4

import pytest
from pydantic import ValidationError

from app.schemas.sync import SyncOperationIn, SyncPushIn


def test_sync_operation_accepts_supported_offline_record() -> None:
    operation = SyncOperationIn(
        id=uuid4(),
        entity_type="followup",
        entity_id="followup-123",
        operation="upsert",
        payload={"note": "تماس با مشتری", "completed": False},
        base_version=2,
    )

    assert operation.entity_type == "followup"
    assert operation.base_version == 2


def test_sync_operation_rejects_unknown_entity_type() -> None:
    with pytest.raises(ValidationError):
        SyncOperationIn(
            id=uuid4(),
            entity_type="warehouse_stock",
            entity_id="stock-1",
            operation="upsert",
            payload={},
        )


def test_sync_operation_rejects_unexpected_client_fields() -> None:
    with pytest.raises(ValidationError):
        SyncOperationIn(
            id=uuid4(),
            entity_type="task",
            entity_id="task-1",
            operation="upsert",
            payload={},
            client_created_at="2026-11-18T10:00:00Z",
        )


def test_sync_push_rejects_more_than_one_hundred_operations() -> None:
    items = [
        {
            "id": str(uuid4()),
            "entity_type": "task",
            "entity_id": f"task-{index}",
            "operation": "upsert",
            "payload": {},
        }
        for index in range(101)
    ]

    with pytest.raises(ValidationError):
        SyncPushIn(operations=items)
