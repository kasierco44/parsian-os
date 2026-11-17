from typing import Any

from sqlalchemy.orm import Session

from app.models.audit_event import AuditEvent


def record_audit_event(
    db: Session,
    *,
    action: str,
    entity_type: str,
    actor_user_id=None,
    entity_id: str | None = None,
    request_id: str | None = None,
    details: dict[str, Any] | None = None,
    ip_address: str | None = None,
) -> AuditEvent:
    """Add an audit event to the caller's transaction; caller controls commit/rollback."""
    event = AuditEvent(
        actor_user_id=actor_user_id,
        action=action,
        entity_type=entity_type,
        entity_id=entity_id,
        request_id=request_id,
        details=details,
        ip_address=ip_address,
    )
    db.add(event)
    return event
