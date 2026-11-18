from uuid import UUID

from fastapi import APIRouter, Depends, HTTPException, Query
from sqlalchemy import select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.api.routes.auth import get_current_user
from app.db.session import get_db
from app.models.customer import Customer
from app.models.identity import User
from app.models.product import Product
from app.models.sync import SyncOperation, SyncRecord
from app.schemas.customer import CustomerCreate
from app.schemas.sync import SyncChangeOut, SyncOperationResult, SyncPullOut, SyncPushIn, SyncPushOut
from app.services.audit import record_audit_event
from app.services.replication import record_replication_event

router = APIRouter(prefix="/sync", tags=["offline synchronization"])


def _can_manage_customer(user: User) -> bool:
    return user.is_superuser or any(role.code in {"manager", "admin", "owner"} for role in user.roles)


def _latest_record(db: Session, user_id, entity_type: str, entity_id: str):
    return db.scalar(
        select(SyncRecord)
        .where(
            SyncRecord.owner_user_id == user_id,
            SyncRecord.entity_type == entity_type,
            SyncRecord.entity_id == entity_id,
        )
        .order_by(SyncRecord.id.desc())
        .limit(1)
    )


def _validate_proforma_draft(db: Session, user: User, payload: dict) -> tuple[dict | None, str | None]:
    try:
        customer_id = UUID(str(payload.get("customer_id", "")))
    except ValueError:
        return None, "شناسه مشتری پیش‌نویس معتبر نیست."
    customer = db.get(Customer, customer_id)
    if customer is None or not customer.is_active:
        return None, "مشتری پیش‌نویس وجود ندارد یا غیرفعال است."
    if customer.assigned_to_user_id != user.id and not _can_manage_customer(user):
        return None, "به مشتری انتخاب‌شده دسترسی ندارید."
    items = payload.get("items")
    if not isinstance(items, list) or not 1 <= len(items) <= 100:
        return None, "اقلام پیش‌نویس باید بین ۱ تا ۱۰۰ ردیف باشند."
    normalized_items = []
    for line in items:
        if not isinstance(line, dict):
            return None, "ساختار یکی از اقلام پیش‌نویس معتبر نیست."
        try:
            product_id = UUID(str(line.get("product_id", "")))
            quantity = int(line.get("quantity", 0))
        except (ValueError, TypeError):
            return None, "شناسه محصول یا تعداد معتبر نیست."
        if quantity < 1 or quantity > 100000:
            return None, "تعداد هر قلم باید بین ۱ تا ۱۰۰٬۰۰۰ باشد."
        product = db.get(Product, product_id)
        if product is None or not product.is_active:
            return None, "یکی از محصولات پیش‌نویس حذف یا غیرفعال شده است."
        quoted_price = line.get("unit_price")
        if quoted_price is not None and (isinstance(quoted_price, bool) or not isinstance(quoted_price, int) or quoted_price < 0):
            return None, "قیمت پیشنهادی قلم معتبر نیست."
        normalized_items.append({
            "product_id": str(product.id), "sku": product.sku, "name": product.name,
            "quantity": quantity, "unit_price": quoted_price,
            "line_total": quoted_price * quantity if quoted_price is not None else None,
            "current_catalog_price": product.unit_price,
            "catalog_version": product.catalog_version,
        })
    return {
        "customer_id": str(customer.id), "customer_name": customer.name,
        "items": normalized_items, "status": "draft",
        "created_at": payload.get("created_at"), "note": payload.get("note", ""),
    }, None


def _customer_replication_payload(customer: Customer) -> dict:
    return {
        "assigned_to_user_id": str(customer.assigned_to_user_id),
        "assigned_to_username": customer.assigned_to.username if customer.assigned_to else None,
        "created_by_user_id": str(customer.created_by_user_id),
        "created_by_username": customer.created_by.username if customer.created_by else None,
        "name": customer.name, "phone": customer.phone, "city": customer.city,
        "province": customer.province, "address": customer.address,
        "customer_type": customer.customer_type, "notes": customer.notes,
        "status": customer.status, "is_active": customer.is_active,
    }


@router.post("/push", response_model=SyncPushOut)
def push_operations(payload: SyncPushIn, user: User = Depends(get_current_user), db: Session = Depends(get_db)) -> SyncPushOut:
    results: list[SyncOperationResult] = []
    for item in payload.operations:
        prior = db.scalar(select(SyncOperation).where(
            SyncOperation.owner_user_id == user.id,
            SyncOperation.operation_id == item.id,
        ))
        if prior is not None:
            latest = _latest_record(db, user.id, item.entity_type, item.entity_id)
            results.append(SyncOperationResult(id=item.id, status="duplicate", version=latest.version if latest else None))
            continue

        latest = _latest_record(db, user.id, item.entity_type, item.entity_id)
        current_version = latest.version if latest else 0
        if item.base_version is not None and item.base_version != current_version:
            results.append(SyncOperationResult(id=item.id, status="conflict", version=current_version, message="نسخه اطلاعات روی سرور تغییر کرده است؛ نیازمند بررسی است."))
            continue

        if item.entity_type == "customer":
            try:
                customer_id = UUID(item.entity_id)
            except ValueError:
                results.append(SyncOperationResult(id=item.id, status="rejected", message="شناسه مشتری معتبر نیست."))
                continue
            customer = db.get(Customer, customer_id)
            if customer is not None and customer.assigned_to_user_id != user.id and not _can_manage_customer(user):
                results.append(SyncOperationResult(id=item.id, status="rejected", message="به این مشتری دسترسی ندارید."))
                continue
            if item.operation == "upsert":
                try:
                    data = CustomerCreate.model_validate(item.payload).model_dump()
                except Exception:
                    results.append(SyncOperationResult(id=item.id, status="rejected", message="اطلاعات مشتری معتبر نیست."))
                    continue
                if customer is None:
                    customer = Customer(id=customer_id, assigned_to_user_id=user.id, created_by_user_id=user.id, assigned_to=user, created_by=user, **data)
                    db.add(customer)
                else:
                    for key, value in data.items():
                        setattr(customer, key, value)
                    customer.is_active = True
                    customer.status = "active"
            elif customer is not None:
                customer.is_active = False
                customer.status = "inactive"

            if customer is not None:
                customer.replication_version = (customer.replication_version or 0) + 1
                db.flush()
                record_replication_event(
                    db, entity_type="customer", entity_id=item.entity_id,
                    version=customer.replication_version, deleted=item.operation == "delete",
                    payload=_customer_replication_payload(customer),
                )

        record_payload = item.payload if item.operation == "upsert" else {}
        if item.entity_type == "proforma_draft" and item.operation == "upsert":
            normalized, validation_error = _validate_proforma_draft(db, user, item.payload)
            if validation_error:
                results.append(SyncOperationResult(id=item.id, status="rejected", message=validation_error))
                continue
            record_payload = normalized

        new_version = current_version + 1
        sync_record = SyncRecord(
            owner_user_id=user.id,
            entity_type=item.entity_type,
            entity_id=item.entity_id,
            payload=record_payload,
            version=new_version,
            deleted=item.operation == "delete",
        )
        db.add(sync_record)
        db.add(SyncOperation(
            owner_user_id=user.id,
            operation_id=item.id,
            entity_type=item.entity_type,
            entity_id=item.entity_id,
            outcome="accepted",
        ))
        if item.entity_type != "customer":
            record_replication_event(
                db,
                entity_type=item.entity_type,
                entity_id=item.entity_id,
                version=new_version,
                deleted=item.operation == "delete",
                payload={"owner_user_id": str(user.id), "owner_username": user.username, "record": sync_record.payload},
            )
        record_audit_event(
            db,
            action="offline_sync_" + item.operation,
            entity_type=item.entity_type,
            actor_user_id=user.id,
            entity_id=item.entity_id,
            details={"operation_id": str(item.id), "version": new_version},
        )
        results.append(SyncOperationResult(id=item.id, status="accepted", version=new_version))

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
