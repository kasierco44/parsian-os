from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.sync import SyncOperation, SyncRecord
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
        ))
        current_version = record.version if record else 0
        if item.base_version is not None and item.base_version != current_version:
            results.append(SyncOperationResult(id=item.id, status="conflict", version=current_version, message="نسخه اطلاعات روی سرور تغییر کرده است؛ نیازمند بررسی است."))
            continue

        if record is None:
            record = SyncRecord(
                owner_user_id=user.id,
                entity_type=item.entity_type,
                entity_id=item.entity_id,
                payload=item.payload if item.operation == "upsert" else {},
                version=1,
                deleted=item.operation == "delete",
            )
            db.add(record)
        else:
            record.version += 1
            record.payload = item.payload if item.operation == "upsert" else {}
            record.deleted = item.operation == "delete"

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
