import hashlib
import hmac
import json
import os
import time
from urllib.parse import urlsplit

import httpx
from sqlalchemy import select

from app.db.session import get_session_factory
from app.models.replication import ReplicationEvent, ReplicationState
from app.schemas.replication import ReplicationEventIn
from app.services.replication import apply_remote_event, local_node_id


def signed_headers(method: str, url: str, body: bytes, node: str, secret: str) -> dict[str, str]:
    parsed = urlsplit(url)
    path = parsed.path + (f"?{parsed.query}" if parsed.query else "")
    timestamp = str(int(time.time()))
    digest = hashlib.sha256(body).hexdigest()
    message = f"{timestamp}\n{method.upper()}\n{path}\n{digest}".encode()
    signature = hmac.new(secret.encode(), message, hashlib.sha256).hexdigest()
    return {
        "X-Replication-Node": node,
        "X-Replication-Timestamp": timestamp,
        "X-Replication-Signature": signature,
        "Accept": "application/json",
    }


def replicate_once(client: httpx.Client, peer_url: str, node: str, secret: str) -> tuple[int, int]:
    session = get_session_factory()()
    pushed = pulled = 0
    try:
        state = session.get(ReplicationState, node)  # state row is keyed by peer; local peer key is configured below
        peer_node = os.getenv("REPLICATION_PEER_NODE_ID", "").strip()
        if not peer_node:
            raise RuntimeError("REPLICATION_PEER_NODE_ID is required.")
        state = session.get(ReplicationState, peer_node)
        if state is None:
            state = ReplicationState(peer_node=peer_node, inbound_cursor=0, outbound_cursor=0)
            session.add(state)
            session.flush()

        outgoing = session.scalars(select(ReplicationEvent).where(
            ReplicationEvent.origin_node == node,
            ReplicationEvent.id > state.outbound_cursor,
        ).order_by(ReplicationEvent.id).limit(100)).all()
        if outgoing:
            payload = {"events": [{
                "event_uuid": str(event.event_uuid),
                "origin_node": event.origin_node,
                "entity_type": event.entity_type,
                "entity_id": event.entity_id,
                "payload": event.payload,
                "version": event.version,
                "deleted": event.deleted,
            } for event in outgoing]}
            body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode()
            url = f"{peer_url}/replication/push"
            response = client.post(url, content=body, headers={**signed_headers("POST", url, body, node, secret), "Content-Type": "application/json"})
            response.raise_for_status()
            state.outbound_cursor = outgoing[-1].id
            pushed = len(outgoing)

        url = f"{peer_url}/replication/pull?cursor={state.inbound_cursor}&limit=100"
        response = client.get(url, headers=signed_headers("GET", url, b"", node, secret))
        response.raise_for_status()
        incoming = response.json()
        for item in incoming.get("events", []):
            event_data = ReplicationEventIn.model_validate(item)
            exists = session.scalar(select(ReplicationEvent).where(
                ReplicationEvent.origin_node == event_data.origin_node,
                ReplicationEvent.event_uuid == event_data.event_uuid,
            ))
            if exists is not None:
                continue
            event = ReplicationEvent(**event_data.model_dump())
            apply_remote_event(session, event)
            session.add(event)
            pulled += 1
        state.inbound_cursor = int(incoming.get("next_cursor", state.inbound_cursor))
        session.commit()
        return pushed, pulled
    except Exception:
        session.rollback()
        raise
    finally:
        session.close()


def main() -> None:
    peer_url = os.getenv("REPLICATION_PEER_URL", "").rstrip("/")
    node = local_node_id()
    secret = os.getenv("REPLICATION_SHARED_SECRET", "")
    if not peer_url or not secret or node == "unconfigured-node":
        raise SystemExit("Set REPLICATION_PEER_URL, REPLICATION_SHARED_SECRET and PARSIAN_NODE_ID.")
    if not peer_url.startswith("https://") and "localhost" not in peer_url and "127.0.0.1" not in peer_url:
        raise SystemExit("Replication peer URL must use HTTPS outside localhost.")
    with httpx.Client(timeout=30.0) as client:
        while True:
            try:
                pushed, pulled = replicate_once(client, peer_url, node, secret)
                if pushed or pulled:
                    print(f"Replication cycle: pushed={pushed}, pulled={pulled}", flush=True)
            except Exception as exc:
                print(f"Replication cycle failed; data remains queued: {exc}", flush=True)
            time.sleep(10)


if __name__ == "__main__":
    main()
