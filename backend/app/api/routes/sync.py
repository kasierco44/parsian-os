from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.sync import SyncOperation, SyncRecord
from app.models.customer import Customer
from app.schemas.customer import CustomerCreate
from app.models.identity import User
from app.schemas.sync import SyncChangeOut, SyncOperationResult, SyncPullOut, SyncPushIn, SyncPushOut
from app.services.audit import record_audit_event

router = APIRouter(prefix="/sync", tags=["offline synchronization"])


@router.post("/push", response_model=SyncPushOut)
def push_operations(payload: SyncPushIn, user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> SyncPushOut:
    results: list[SyncOperationResult] = []
    for item in payload.operations:
        prior = db.scalar(select(SyncOperation).where(
            SyncOperation.owner_user_id == user.id,
            SyncOperation.operation_id == item.id,
        ))
        if prior is not None:
            if item.entity_type == "customer":
            try:
                customer_id = UUID(item.entity_id)
            except ValueError:
                results.append(SyncOperationResult(id=item.id, status="rejected", message="شناسه مشتری معتبر نیست."))
                continue
            customer = db.get(Customer, customer_id)
            if customer is not None and customer.assigned_to_user_id != user.id and not user.is_superuser:
                results.append(SyncOperationResult(id=item.id, status="rejected", message="به این مشتری دسترسی ندارید."))
                continue
            if item.operation == "delete":
                if customer is not None:
                    customer.is_active = False
                    customer.status = "inactive"
            else:
                try:
                    customer_data = CustomerCreate.model_validate(item.payload).model_dump()
                except Exception:
                    results.append(SyncOperationResult(id=item.id, status="rejected", message="اطلاعات مشتری معتبر نیست."))
                    continue
                if customer is None:
                    customer = Customer(id=customer_id, assigned_to_user_id=user.id, created_by_user_id=user.id, **customer_data)
                    db.add(customer)
                else:
                    for key, value in customer_data.items():
                        setattr(customer, key, value)
            db.add(SyncOperation(owner_user_id=user.id, operation_id=item.id, entity_type=item.entity_type, entity_id=item.entity_id, outcome="accepted"))
            record_audit_event(db, action="offline_customer_" + item.operation, entity_type="customer", actor_user_id=user.id, entity_id=item.entity_id, details={"operation_id": str(item.id)})
            previous = db.scalar(select(SyncRecord).where(SyncRecord.owner_user_id == user.id, SyncRecord.entity_type == "customer", SyncRecord.entity_id == item.entity_id).order_by(SyncRecord.id.desc()).limit(1))
            version = (previous.version if previous else 0) + 1
            db.add(SyncRecord(owner_user_id=user.id, entity_type="customer", entity_id=item.entity_id, payload=item.payload if item.operation == "upsert" else {}, version=version, deleted=item.operation == "delete"))
            results.append(SyncOperationResult(id=item.id, status="accepted", version=version))
            continue

        record = db.scalar(select(SyncRecord).where(
                SyncRecord.owner_user_id == user.id,
                SyncRecord.entity_type == item.entity_type,
                SyncRecord.entity_id == item.entity_id,
            ))
            results.append(SyncOperationResult(id=item.id, status="duplicate", version=record.version if record else None))
            continue

        record = db.scalar(select(SyncRecord).where(
            SyncRecord.owner_user_id == user.id,
            SyncRecord.entity_type == item.entity_type,
            SyncRecord.entity_id == item.entity_id,
        ).order_by(SyncRecord.id.desc()).limit(1))
        current_version = record.version if record else 0
        if item.base_version is not None and item.base_version != current_version:
            results.append(SyncOperationResult(id=item.id, status="conflict", version=current_version, message="نسخه اطلاعات روی سرور تغییر کرده است؛ نیازمند بررسی است."))
            continue

        record = SyncRecord(
            owner_user_id=user.id,
            entity_type=item.entity_type,
            entity_id=item.entity_id,
            payload=item.payload if item.operation == "upsert" else {},
            version=current_version + 1,
            deleted=item.operation == "delete",
        )
        db.add(record)

        db.add(SyncOperation(
            owner_user_id=user.id,
            operation_id=item.id,
            entity_type=item.entity_type,
            entity_id=item.entity_id,
            outcome="accepted",
        ))
        record_audit_event(
            db,
            action="offline_sync_" + item.operation,
            entity_type=item.entity_type,
            actor_user_id=user.id,
            entity_id=item.entity_id,
            details={"operation_id": str(item.id), "version": record.version},
        )
        results.append(SyncOperationResult(id=item.id, status="accepted", version=record.version))

    try:
        db.commit()
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="تداخل همزمان عملیات؛ همگام‌سازی را دوباره انجام دهید.") from None
    return SyncPushOut(results=results)


@router.get("/pull", response_model=SyncPullOut)
def pull_changes(cursor: int = Query(default=0, ge=0), limit: int = Query(default=200, ge=1, le=500), user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> SyncPullOut:
    rows = db.scalars(
        select(SyncRecord)
        .where(SyncRecord.owner_user_id == user.id, SyncRecord.id > cursor)
        .order_by(SyncRecord.id)
        .limit(limit)
    ).all()
    changes = [
        SyncChangeOut(entity_type=row.entity_type, entity_id=row.entity_id, payload=row.payload, version=row.version, deleted=row.deleted)
        for row in rows
    ]
    next_cursor = rows[-1].id if rows else cursor
    return SyncPullOut(changes=changes, next_cursor=next_cursor)
