import os
import uuid
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.customer import Customer
from app.models.identity import User
from app.models.product import Product
from app.models.replication import ReplicationEvent
from app.models.sync import SyncRecord


def local_node_id() -> str:
    return os.getenv("PARSIAN_NODE_ID", "unconfigured-node")


def record_replication_event(db: Session, *, entity_type: str, entity_id: str, payload: dict, version: int, deleted: bool = False) -> ReplicationEvent:
    event = ReplicationEvent(
        event_uuid=uuid.uuid4(),
        origin_node=local_node_id(),
        entity_type=entity_type,
        entity_id=entity_id,
        payload=payload,
        version=version,
        deleted=deleted,
    )
    db.add(event)
    return event


def apply_remote_event(db: Session, event: ReplicationEvent) -> None:
    if event.entity_type == "customer":
        entity_id = UUID(event.entity_id)
        data = event.payload
        assigned_id = UUID(data["assigned_to_user_id"])
        created_by_id = UUID(data["created_by_user_id"])
        if db.get(User, assigned_id) is None or db.get(User, created_by_id) is None:
            raise ValueError("Customer owner identities must exist on both replication nodes.")
        record = db.get(Customer, entity_id)
        if record is not None and record.replication_version >= event.version:
            return
        if record is None:
            record = Customer(id=entity_id, assigned_to_user_id=assigned_id, created_by_user_id=created_by_id, name=data.get("name", ""))
            db.add(record)
        if event.deleted:
            record.is_active = False
            record.status = "inactive"
        else:
            for key in ("name", "phone", "city", "province", "address", "customer_type", "notes", "status", "is_active"):
                if key in data:
                    setattr(record, key, data[key])
        record.replication_version = event.version
        return

    if event.entity_type == "product":
        entity_id = UUID(event.entity_id)
        data = event.payload
        record = db.get(Product, entity_id)
        if record is not None and record.replication_version >= event.version:
            return
        if record is None:
            record = Product(id=entity_id, sku=data["sku"], name=data["name"])
            db.add(record)
        for key in ("sku", "name", "category", "vehicle_model", "description", "unit_price", "is_active", "catalog_version"):
            if key in data:
                setattr(record, key, data[key])
        record.replication_version = event.version
        return

    if event.entity_type in {"task", "followup", "visit", "customer_note", "order_draft", "proforma_draft", "product_favorite"}:
        owner_id = UUID(event.payload["owner_user_id"])
        if db.get(User, owner_id) is None:
            raise ValueError("Sync owner identity must exist on both replication nodes.")
        latest = db.scalar(select(SyncRecord).where(
            SyncRecord.owner_user_id == owner_id,
            SyncRecord.entity_type == event.entity_type,
            SyncRecord.entity_id == event.entity_id,
        ).order_by(SyncRecord.id.desc()).limit(1))
        if latest is not None and latest.version >= event.version:
            return
        db.add(SyncRecord(
            owner_user_id=owner_id,
            entity_type=event.entity_type,
            entity_id=event.entity_id,
            payload=event.payload.get("record", {}) if not event.deleted else {},
            version=event.version,
            deleted=event.deleted,
        ))
        return

    raise ValueError(f"Unsupported replication entity type: {event.entity_type}")
