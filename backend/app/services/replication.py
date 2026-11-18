import base64
import os
import uuid
from pathlib import Path
from uuid import UUID

from sqlalchemy import select
from sqlalchemy.orm import Session

from app.models.customer import Customer
from app.models.identity import User
from app.models.product import CatalogState, Product, ProductMedia
from app.models.replication import ReplicationEvent
from app.models.sync import SyncRecord
from app.models.sales import SalesOrder, SalesOrderLine


def local_node_id() -> str:
    return os.getenv("PARSIAN_NODE_ID", "unconfigured-node")


def record_replication_event(db: Session, *, entity_type: str, entity_id: str, payload: dict, version: int, deleted: bool = False) -> ReplicationEvent:
    event = ReplicationEvent(event_uuid=uuid.uuid4(), origin_node=local_node_id(), entity_type=entity_type, entity_id=entity_id, payload=payload, version=version, deleted=deleted)
    db.add(event)
    return event


def _resolve_user_id(db: Session, user_id: str, username: str | None) -> UUID:
    user = db.get(User, UUID(user_id))
    if user is None and username:
        user = db.scalar(select(User).where(User.username == username))
    if user is None:
        raise ValueError("The referenced user must exist on both replication nodes.")
    return user.id


def apply_remote_event(db: Session, event: ReplicationEvent) -> None:
    if event.entity_type == "customer":
        entity_id = UUID(event.entity_id)
        data = event.payload
        assigned_id = _resolve_user_id(db, data["assigned_to_user_id"], data.get("assigned_to_username"))
        created_by_id = _resolve_user_id(db, data["created_by_user_id"], data.get("created_by_username"))
        record = db.get(Customer, entity_id)
        if record is not None and record.replication_version >= event.version:
            return
        if record is None:
            record = Customer(id=entity_id, assigned_to_user_id=assigned_id, created_by_user_id=created_by_id, name=data.get("name", ""))
            db.add(record)
        record.assigned_to_user_id = assigned_id
        record.created_by_user_id = created_by_id
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
        for key in ("sku", "name", "category", "vehicle_model", "description", "unit_price", "cost_price", "track_inventory", "is_active", "catalog_version"):
            if key in data:
                setattr(record, key, data[key])
        record.replication_version = event.version
        state = db.get(CatalogState, 1)
        if state is None:
            state = CatalogState(id=1, version=0)
            db.add(state)
        state.version = max(state.version, record.catalog_version)
        return

    if event.entity_type == "product_media":
        data = event.payload
        product_id = UUID(data["product_id"])
        if db.get(Product, product_id) is None:
            raise ValueError("Product metadata must replicate before its media.")
        media_id = UUID(event.entity_id)
        media = db.get(ProductMedia, media_id)
        if media is not None:
            return
        content_b64 = data.get("content_b64")
        if not content_b64:
            raise ValueError("Product media binary content is missing from replication.")
        raw = base64.b64decode(content_b64, validate=True)
        import hashlib
        if hashlib.sha256(raw).hexdigest() != data["checksum_sha256"]:
            raise ValueError("Replicated product media checksum mismatch.")
        root = Path(os.getenv("CATALOG_MEDIA_ROOT", "/var/lib/parsian/catalog")).resolve()
        target = (root / data["storage_key"]).resolve()
        if root not in target.parents:
            raise ValueError("Invalid replicated media storage path.")
        target.parent.mkdir(parents=True, exist_ok=True)
        temporary = target.with_suffix(".replica")
        temporary.write_bytes(raw)
        temporary.replace(target)
        db.add(ProductMedia(
            id=media_id, product_id=product_id, storage_key=data["storage_key"],
            file_name=data["file_name"], mime_type=data["mime_type"],
            size_bytes=len(raw), checksum_sha256=data["checksum_sha256"],
            media_version=data["media_version"], catalog_version=data["catalog_version"],
        ))
        state = db.get(CatalogState, 1)
        if state is None:
            state = CatalogState(id=1, version=0)
            db.add(state)
        state.version = max(state.version, data["catalog_version"])
        return

    if event.entity_type == "order_draft":
        owner_id = _resolve_user_id(db, event.payload["owner_user_id"], event.payload.get("owner_username"))
        latest = db.scalar(select(SyncRecord).where(
            SyncRecord.owner_user_id == owner_id,
            SyncRecord.entity_type == "order_draft",
            SyncRecord.entity_id == event.entity_id,
        ).order_by(SyncRecord.id.desc()).limit(1))
        if latest is not None and latest.version >= event.version:
            return
        data = event.payload.get("record", {})
        order_id = UUID(event.entity_id)
        order = db.get(SalesOrder, order_id)
        if event.deleted:
            if order is not None and order.status == "draft":
                order.status = "cancelled"
        else:
            customer_id = UUID(data["customer_id"])
            customer = db.get(Customer, customer_id)
            if customer is None:
                raise ValueError("Customer must replicate before its sales order.")
            if order is not None and order.status != "draft":
                raise ValueError("A non-draft sales order cannot be overwritten by replication.")
            if order is None:
                order = SalesOrder(
                    id=order_id, order_number=f"SO-REP-{order_id.hex[:12].upper()}",
                    customer_id=customer_id, seller_user_id=owner_id, status="draft",
                )
                db.add(order)
            else:
                order.lines.clear()
            order.customer_id = customer_id
            order.seller_user_id = owner_id
            order.note = data.get("note", "")
            order.subtotal = int(data["subtotal"])
            order.discount_amount = int(data["discount_amount"])
            order.total_amount = int(data["total_amount"])
            for line in data.get("lines", []):
                product_id = UUID(line["product_id"])
                if db.get(Product, product_id) is None:
                    raise ValueError("Product must replicate before its sales order.")
                order.lines.append(SalesOrderLine(
                    product_id=product_id, sku_snapshot=line["sku"], name_snapshot=line["name"],
                    quantity=int(line["quantity"]), unit_price=int(line["unit_price"]),
                    discount_amount=int(line["discount_amount"]), line_total=int(line["line_total"]),
                ))
        db.add(SyncRecord(
            owner_user_id=owner_id, entity_type="order_draft", entity_id=event.entity_id,
            payload=data if not event.deleted else {}, version=event.version, deleted=event.deleted,
        ))
        return

    if event.entity_type in {"task", "followup", "visit", "customer_note", "order_draft", "proforma_draft", "product_favorite"}:
        owner_id = _resolve_user_id(db, event.payload["owner_user_id"], event.payload.get("owner_username"))
        latest = db.scalar(select(SyncRecord).where(
            SyncRecord.owner_user_id == owner_id,
            SyncRecord.entity_type == event.entity_type,
            SyncRecord.entity_id == event.entity_id,
        ).order_by(SyncRecord.id.desc()).limit(1))
        if latest is not None and latest.version >= event.version:
            return
        db.add(SyncRecord(
            owner_user_id=owner_id, entity_type=event.entity_type, entity_id=event.entity_id,
            payload=event.payload.get("record", {}) if not event.deleted else {},
            version=event.version, deleted=event.deleted,
        ))
        return

    raise ValueError(f"Unsupported replication entity type: {event.entity_type}")
