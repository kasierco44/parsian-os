from uuid import uuid4

from app.core.audit import record_audit


class RecordingSession:
    def __init__(self) -> None:
        self.added = []

    def add(self, item) -> None:
        self.added.append(item)


def test_record_audit_creates_event_without_committing() -> None:
    session = RecordingSession()
    actor_id = uuid4()
    entity_id = uuid4()

    record_audit(
        session,
        action="user.created",
        entity_type="user",
        entity_id=entity_id,
        actor_user_id=actor_id,
        details={"username": "operator1"},
    )

    assert len(session.added) == 1
    event = session.added[0]
    assert event.action == "user.created"
    assert event.entity_type == "user"
    assert event.entity_id == str(entity_id)
    assert event.actor_user_id == actor_id
    assert event.details == {"username": "operator1"}
