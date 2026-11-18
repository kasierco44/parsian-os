import { enqueueOperation, getPendingOperations, markOperation, acknowledgeOperation, getSyncCursor, setSyncCursor, cacheRecord } from "../offline/database";
import { ApiError } from "./client";

const API_PREFIX = "/sync";

async function syncRequest(path, { token, method = "GET", body } = {}) {
  const base = (process.env.EXPO_PUBLIC_API_BASE_URL || "").replace(/\/+$/, "");
  if (!base) throw new ApiError("نشانی API تنظیم نشده است.");
  let response;
  try {
    response = await fetch(`${base}${API_PREFIX}${path}`, {
      method,
      headers: { Accept: "application/json", Authorization: `Bearer ${token}`, ...(body ? { "Content-Type": "application/json" } : {}) },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
  } catch {
    throw new ApiError("آفلاین هستید؛ عملیات در صف محلی باقی می‌ماند.");
  }
  const data = await response.json().catch(() => null);
  if (!response.ok) throw new ApiError(data?.detail || "همگام‌سازی ناموفق بود.", response.status, data?.detail);
  return data;
}

function newOperationId() {
  if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
  return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, c => {
    const r = Math.floor(Math.random() * 16);
    return (c === "x" ? r : (r & 0x3) | 0x8).toString(16);
  });
}

export async function queueOfflineChange(change) {
  return enqueueOperation({ ...change, id: change.id || newOperationId() });
}

export async function synchronize({ token, batchSize = 50 } = {}) {
  const pendingRows = await getPendingOperations(batchSize);
  const pending = pendingRows.filter(row => row.entity_id).map(row => ({
    id: row.id, entity_type: row.entity_type, entity_id: row.entity_id, operation: row.operation,
    payload: JSON.parse(row.payload), base_version: row.base_version, client_created_at: row.created_at,
  }));
  let pushed = { results: [] };
  if (pending.length) {
    pushed = await syncRequest("/push", { token, method: "POST", body: { operations: pending } });
    for (const result of pushed.results || []) {
      if (result.status === "accepted" || result.status === "duplicate") await acknowledgeOperation(result.id);
      else if (result.status === "conflict") await markOperation(result.id, "conflict", result.message || "تعارض نسخه");
      else await markOperation(result.id, "failed", result.message || "رد توسط سرور");
    }
  }

  const cursor = await getSyncCursor();
  const pulled = await syncRequest(`/pull?cursor=${encodeURIComponent(cursor || "0")}`, { token });
  for (const change of pulled.changes || []) {
    await cacheRecord(change.entity_type, change.entity_id, change.payload, change.version, change.deleted);
  }
  if (pulled.next_cursor != null) await setSyncCursor(pulled.next_cursor);
  return { pushed: (pushed.results || []).length, pulled: (pulled.changes || []).length, conflicts: (pushed.results || []).filter(x => x.status === "conflict").length };
}
