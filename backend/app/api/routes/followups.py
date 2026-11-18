from datetime import UTC, datetime
from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import select
from sqlalchemy.orm import Session

from app.core.audit import record_audit
from app.core.authorization import require_permission
from app.db.session import get_db
from app.models.customer import Customer
from app.models.followup import FollowUp
from app.models.identity import User
from app.schemas.followup import FollowUpCreate, FollowUpRead, FollowUpUpdate

router = APIRouter(prefix="/followups", tags=["follow-ups"])


def _is_visitor(actor: User) -> bool:
    return not actor.is_superuser and any(role.code == "visitor" for role in actor.roles)


def _scope(statement, actor: User):
    if _is_visitor(actor):
        statement = statement.where(FollowUp.owner_user_id == actor.id)
    return statement


@router.get("", response_model=list[FollowUpRead], summary="List persistent follow-ups")
def list_followups(
    customer_id: UUID | None = None,
    state: str | None = Query(default=None, alias="status", pattern=r"^(open|completed|cancelled)$"),
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    actor: User = Depends(require_permission("sales.read")),
    db: Session = Depends(get_db),
) -> list[FollowUp]:
    statement = _scope(select(FollowUp).order_by(FollowUp.due_at.asc()).limit(limit).offset(offset), actor)
    if customer_id:
        statement = statement.where(FollowUp.customer_id == customer_id)
    if state:
        statement = statement.where(FollowUp.status == state)
    return list(db.scalars(statement).all())


@router.post("", response_model=FollowUpRead, status_code=status.HTTP_201_CREATED)
def create_followup(
    payload: FollowUpCreate,
    actor: User = Depends(require_permission("sales.write")),
    db: Session = Depends(get_db),
) -> FollowUp:
    customer = db.scalar(select(Customer).where(Customer.id == payload.customer_id))
    if customer is None or (_is_visitor(actor) and customer.assigned_to_user_id != actor.id):
        raise HTTPException(status_code=404, detail="Customer not found")
    owner_id = actor.id if _is_visitor(actor) else (payload.owner_user_id or actor.id)
    if db.get(User, owner_id) is None:
        raise HTTPException(status_code=422, detail="Follow-up owner does not exist")
    due_at = payload.due_at
    if due_at.tzinfo is None:
        raise HTTPException(status_code=422, detail="due_at must include a timezone")
    item = FollowUp(
        customer_id=payload.customer_id,
        owner_user_id=owner_id,
        created_by_user_id=actor.id,
        title=payload.title,
        note=payload.note,
        due_at=due_at,
        status="open",
    )
    db.add(item)
    db.flush()
    record_audit(
        db, action="followup.created", entity_type="followup", entity_id=item.id,
        actor_user_id=actor.id,
        details={"customer_id": str(item.customer_id), "owner_user_id": str(item.owner_user_id), "due_at": item.due_at.isoformat()},
    )
    db.commit()
    db.refresh(item)
    return item


@router.get("/{followup_id}", response_model=FollowUpRead)
def get_followup(
    followup_id: UUID,
    actor: User = Depends(require_permission("sales.read")),
    db: Session = Depends(get_db),
) -> FollowUp:
    item = db.scalar(_scope(select(FollowUp).where(FollowUp.id == followup_id), actor))
    if item is None:
        raise HTTPException(status_code=404, detail="Follow-up not found")
    return item


@router.patch("/{followup_id}", response_model=FollowUpRead)
def update_followup(
    followup_id: UUID,
    payload: FollowUpUpdate,
    actor: User = Depends(require_permission("sales.write")),
    db: Session = Depends(get_db),
) -> FollowUp:
    item = db.scalar(_scope(select(FollowUp).where(FollowUp.id == followup_id), actor))
    if item is None:
        raise HTTPException(status_code=404, detail="Follow-up not found")
    changes = payload.model_dump(exclude_unset=True)
    if "due_at" in changes and changes["due_at"] is not None and changes["due_at"].tzinfo is None:
        raise HTTPException(status_code=422, detail="due_at must include a timezone")
    if item.status != "open" and changes.get("status", item.status) == "open":
        raise HTTPException(status_code=409, detail="Closed follow-ups cannot be reopened")
    for field, value in changes.items():
        setattr(item, field, value)
    if changes.get("status") == "completed":
        item.completed_at = datetime.now(UTC)
    elif changes.get("status") in {"open", "cancelled"}:
        item.completed_at = None
    record_audit(
        db, action="followup.updated", entity_type="followup", entity_id=item.id,
        actor_user_id=actor.id, details={"changed_fields": sorted(changes), "status": item.status},
    )
    db.commit()
    db.refresh(item)
    return item
