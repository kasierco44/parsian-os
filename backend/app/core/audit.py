from typing import Any
from uuid import UUID

from sqlalchemy.orm import Session

from app.models.audit_event import AuditEvent


def record_audit(
    db: Session,
    *,
    action: str,
    entity_type: str,
    entity_id: UUID | str | None,
    actor_user_id: UUID | None,
    details: dict[str, Any] | None = None,
) -> None:
    db.add(
        AuditEvent(
            actor_user_id=actor_user_id,
            action=action,
            entity_type=entity_type,
            entity_id=str(entity_id) if entity_id is not None else None,
            details=details,
        )
    )
