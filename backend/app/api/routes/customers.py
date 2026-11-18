from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query, status
from sqlalchemy import or_, select
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.customer import Customer
from app.models.identity import User
from app.schemas.customer import CustomerCreate, CustomerRead, CustomerUpdate
from app.services.audit import record_audit_event
from app.services.replication import record_replication_event

router = APIRouter(prefix="/customers", tags=["customers"])


def _can_manage_all(user: User) -> bool:
    return user.is_superuser or any(role.code in {"manager", "admin", "owner"} for role in user.roles)


def _replication_payload(customer: Customer) -> dict:
    return {
        "assigned_to_user_id": str(customer.assigned_to_user_id),
        "assigned_to_username": customer.assigned_to.username,
        "created_by_user_id": str(customer.created_by_user_id),
        "created_by_username": customer.created_by.username,
        "name": customer.name, "phone": customer.phone, "city": customer.city,
        "province": customer.province, "address": customer.address,
        "customer_type": customer.customer_type, "notes": customer.notes,
        "status": customer.status, "is_active": customer.is_active,
    }


@router.get("", response_model=list[CustomerRead])
def list_customers(q: str | None = Query(default=None, max_length=120), limit: int = Query(default=50, ge=1, le=200), offset: int = Query(default=0, ge=0), user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = select(Customer).where(Customer.is_active.is_(True))
    if not _can_manage_all(user):
        statement = statement.where(Customer.assigned_to_user_id == user.id)
    if q and q.strip():
        needle = f"%{q.strip()}%"
        statement = statement.where(or_(Customer.name.ilike(needle), Customer.phone.ilike(needle), Customer.city.ilike(needle), Customer.province.ilike(needle)))
    return db.scalars(statement.order_by(Customer.updated_at.desc()).offset(offset).limit(limit)).all()


@router.post("", response_model=CustomerRead, status_code=status.HTTP_201_CREATED)
def create_customer(payload: CustomerCreate, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    customer = Customer(assigned_to_user_id=user.id, created_by_user_id=user.id, assigned_to=user, created_by=user, **payload.model_dump())
    db.add(customer)
    db.flush()
    customer.replication_version = 1
    record_replication_event(db, entity_type="customer", entity_id=str(customer.id), version=customer.replication_version, payload=_replication_payload(customer))
    record_audit_event(db, action="customer_created", entity_type="customer", actor_user_id=user.id, entity_id=str(customer.id), details={"name": customer.name})
    db.commit()
    db.refresh(customer)
    return customer


@router.patch("/{customer_id}", response_model=CustomerRead)
def update_customer(customer_id: UUID, payload: CustomerUpdate, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = select(Customer).where(Customer.id == customer_id)
    if not _can_manage_all(user):
        statement = statement.where(Customer.assigned_to_user_id == user.id)
    customer = db.scalar(statement)
    if customer is None:
        raise HTTPException(status_code=404, detail="مشتری پیدا نشد یا به آن دسترسی ندارید.")
    changes = payload.model_dump(exclude_unset=True)
    if "assigned_to_user_id" in changes:
        if not _can_manage_all(user):
            raise HTTPException(status_code=403, detail="تغییر مسئول مشتری فقط برای مدیر مجاز است.")
        assignee = db.get(User, changes.pop("assigned_to_user_id"))
        if assignee is None or not assignee.is_active:
            raise HTTPException(status_code=404, detail="ویزیتور مقصد معتبر نیست.")
        customer.assigned_to = assignee
        customer.assigned_to_user_id = assignee.id
    for key, value in changes.items():
        setattr(customer, key, value)
    customer.replication_version += 1
    db.flush()
    record_replication_event(db, entity_type="customer", entity_id=str(customer.id), version=customer.replication_version, payload=_replication_payload(customer), deleted=not customer.is_active)
    record_audit_event(db, action="customer_updated", entity_type="customer", actor_user_id=user.id, entity_id=str(customer.id), details={"fields": sorted(payload.model_fields_set)})
    db.commit()
    db.refresh(customer)
    return customer


@router.get("/{customer_id}", response_model=CustomerRead)
def get_customer(customer_id: UUID, user: User = Depends(get_current_user), db: Session = Depends(get_db)):
    statement = select(Customer).where(Customer.id == customer_id, Customer.is_active.is_(True))
    if not _can_manage_all(user):
        statement = statement.where(Customer.assigned_to_user_id == user.id)
    customer = db.scalar(statement)
    if customer is None:
        raise HTTPException(status_code=404, detail="مشتری پیدا نشد یا به آن دسترسی ندارید.")
    return customer
