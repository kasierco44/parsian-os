from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import or_, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.core.audit import record_audit
from app.core.authorization import require_permission
from app.db.session import get_db
from app.models.customer import Customer
from app.models.identity import User
from app.schemas.customer import CustomerCreate, CustomerRead, CustomerUpdate

router = APIRouter(prefix="/customers", tags=["customers"])


def _customer_scope(statement, actor: User):
    if not actor.is_superuser and any(role.code == "visitor" for role in actor.roles):
        return statement.where(Customer.assigned_to_user_id == actor.id)
    return statement


@router.get("", response_model=list[CustomerRead], summary="Search and list customers")
def list_customers(
    q: str | None = Query(default=None, max_length=120),
    province: str | None = Query(default=None, max_length=80),
    city: str | None = Query(default=None, max_length=80),
    is_active: bool | None = None,
    limit: int = Query(default=50, ge=1, le=200),
    offset: int = Query(default=0, ge=0),
    actor: User = Depends(require_permission("customers.read")),
    db: Session = Depends(get_db),
) -> list[Customer]:
    statement = select(Customer).order_by(Customer.name).limit(limit).offset(offset)
    statement = _customer_scope(statement, actor)
    if q:
        term = f"%{q.strip()}%"
        statement = statement.where(
            or_(
                Customer.name.ilike(term),
                Customer.customer_code.ilike(term),
                Customer.phone.ilike(term),
                Customer.mobile.ilike(term),
            )
        )
    if province:
        statement = statement.where(Customer.province == province)
    if city:
        statement = statement.where(Customer.city == city)
    if is_active is not None:
        statement = statement.where(Customer.is_active.is_(is_active))
    return list(db.scalars(statement).all())


@router.post("", response_model=CustomerRead, status_code=status.HTTP_201_CREATED)
def create_customer(
    payload: CustomerCreate,
    actor: User = Depends(require_permission("customers.write")),
    db: Session = Depends(get_db),
) -> Customer:
    assigned_user_id = payload.assigned_to_user_id
    is_visitor = not actor.is_superuser and any(role.code == "visitor" for role in actor.roles)
    if is_visitor:
        if assigned_user_id not in (None, actor.id):
            raise HTTPException(status_code=403, detail="Visitors can only assign customers to themselves")
        assigned_user_id = actor.id
    if assigned_user_id and db.get(User, assigned_user_id) is None:
        raise HTTPException(status_code=422, detail="Assigned user does not exist")
    customer_data = payload.model_dump()
    customer_data["assigned_to_user_id"] = assigned_user_id
    customer = Customer(**customer_data)
    db.add(customer)
    try:
        db.flush()
        record_audit(
            db,
            action="customer.created",
            entity_type="customer",
            entity_id=customer.id,
            actor_user_id=actor.id,
            details={"customer_code": customer.customer_code, "name": customer.name},
        )
        db.commit()
        db.refresh(customer)
        return customer
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="Customer code already exists") from None


@router.get("/{customer_id}", response_model=CustomerRead)
def get_customer(
    customer_id: UUID,
    actor: User = Depends(require_permission("customers.read")),
    db: Session = Depends(get_db),
) -> Customer:
    customer = db.scalar(_customer_scope(select(Customer).where(Customer.id == customer_id), actor))
    if customer is None:
        raise HTTPException(status_code=404, detail="Customer not found")
    return customer


@router.patch("/{customer_id}", response_model=CustomerRead)
def update_customer(
    customer_id: UUID,
    payload: CustomerUpdate,
    actor: User = Depends(require_permission("customers.write")),
    db: Session = Depends(get_db),
) -> Customer:
    customer = db.scalar(_customer_scope(select(Customer).where(Customer.id == customer_id), actor))
    if customer is None:
        raise HTTPException(status_code=404, detail="Customer not found")
    changes = payload.model_dump(exclude_unset=True, exclude_none=True)
    is_visitor = not actor.is_superuser and any(role.code == "visitor" for role in actor.roles)
    if is_visitor and "assigned_to_user_id" in changes and changes["assigned_to_user_id"] != actor.id:
        raise HTTPException(status_code=403, detail="Visitors cannot reassign customer ownership")
    if "assigned_to_user_id" in changes and changes["assigned_to_user_id"] is not None:
        if db.get(User, changes["assigned_to_user_id"]) is None:
            raise HTTPException(status_code=422, detail="Assigned user does not exist")
    for field, value in changes.items():
        setattr(customer, field, value)
    record_audit(
        db,
        action="customer.updated",
        entity_type="customer",
        entity_id=customer.id,
        actor_user_id=actor.id,
        details={"changed_fields": sorted(changes)},
    )
    db.commit()
    db.refresh(customer)
    return customer
