import hashlib
import hmac
import json
import os
import time

from fastapi import APIRouter, Depends, HTTPException, Query, Request
from sqlalchemy import func, select
from sqlalchemy.exc import IntegrityError
from sqlalchemy.orm import Session

from app.db.session import get_db
from app.models.replication import ReplicationEvent
from app.schemas.replication import ReplicationEventOut, ReplicationPullOut, ReplicationPushIn
from app.services.replication import apply_remote_event, local_node_id

router = APIRouter(prefix="/replication", tags=["office-cloud replication"])


def _verify_signature(request: Request, body: bytes) -> str:
    node = request.headers.get("X-Replication-Node", "")
    timestamp = request.headers.get("X-Replication-Timestamp", "")
    signature = request.headers.get("X-Replication-Signature", "")
    secret = os.getenv("REPLICATION_SHARED_SECRET", "")
    if not secret or not node or not timestamp or not signature:
        raise HTTPException(status_code=503, detail="Replication authentication is not configured.")
    try:
        stamp = int(timestamp)
    except ValueError:
        raise HTTPException(status_code=401, detail="Invalid replication timestamp.") from None
    if abs(int(time.time()) - stamp) > 300:
        raise HTTPException(status_code=401, detail="Replication request expired.")
    query = request.url.query
    path = request.url.path + (f"?{query}" if query else "")
    digest = hashlib.sha256(body).hexdigest()
    message = f"{timestamp}\n{request.method.upper()}\n{path}\n{digest}".encode()
    expected = hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()
    if not hmac.compare_digest(expected, signature):
        raise HTTPException(status_code=401, detail="Invalid replication signature.")
    if node == local_node_id():
        raise HTTPException(status_code=400, detail="Replication node cannot connect to itself.")
    return node


@router.post("/push")
async def push_replication_events(request: Request, db: Session = Depends(get_db)):
    body = await request.body()
    sender_node = _verify_signature(request, body)
    try:
        payload = ReplicationPushIn.model_validate_json(body)
    except Exception as exc:
        raise HTTPException(status_code=422, detail="Invalid replication event payload.") from exc
    accepted = 0
    try:
        for item in payload.events:
            if item.origin_node != sender_node:
                raise HTTPException(status_code=403, detail="Event origin does not match authenticated peer.")
            duplicate = db.scalar(select(ReplicationEvent).where(
                ReplicationEvent.origin_node == item.origin_node,
                ReplicationEvent.event_uuid == item.event_uuid,
            ))
            if duplicate is not None:
                continue
            event = ReplicationEvent(**item.model_dump())
            apply_remote_event(db, event)
            db.add(event)
            accepted += 1
        db.commit()
    except HTTPException:
        db.rollback()
        raise
    except (ValueError, KeyError) as exc:
        db.rollback()
        raise HTTPException(status_code=409, detail=str(exc)) from exc
    except IntegrityError:
        db.rollback()
        raise HTTPException(status_code=409, detail="Concurrent replication conflict; retry the batch.") from None
    return {"accepted": accepted, "duplicates": len(payload.events) - accepted}


@router.get("/pull", response_model=ReplicationPullOut)
async def pull_replication_events(request: Request, cursor: int = Query(default=0, ge=0), limit: int = Query(default=200, ge=1, le=500), db: Session = Depends(get_db)):
    _verify_signature(request, b"")
    rows = db.scalars(select(ReplicationEvent).where(
        ReplicationEvent.id > cursor,
        ReplicationEvent.origin_node == local_node_id(),
    ).order_by(ReplicationEvent.id).limit(limit)).all()
    max_id = db.scalar(select(func.max(ReplicationEvent.id))) or cursor
    events = [ReplicationEventOut(
        event_uuid=row.event_uuid, origin_node=row.origin_node, entity_type=row.entity_type,
        entity_id=row.entity_id, payload=row.payload, version=row.version, deleted=row.deleted,
    ) for row in rows]
    next_cursor = rows[-1].id if rows else max_id
    return ReplicationPullOut(events=events, next_cursor=next_cursor)
