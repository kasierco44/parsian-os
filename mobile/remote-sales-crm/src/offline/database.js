import * as SQLite from "expo-sqlite";

const DB_NAME = "parsian-sales.db";
let databasePromise;

export async function getOfflineDb() {
  if (!databasePromise) {
    databasePromise = (async () => {
      const db = await SQLite.openDatabaseAsync(DB_NAME);
      await db.execAsync(`
        PRAGMA journal_mode = WAL;
        PRAGMA foreign_keys = ON;
        CREATE TABLE IF NOT EXISTS outbox (
          id TEXT PRIMARY KEY NOT NULL,
          entity_type TEXT NOT NULL,
          operation TEXT NOT NULL,
          payload TEXT NOT NULL,
          base_version TEXT,
          created_at TEXT NOT NULL,
          state TEXT NOT NULL DEFAULT 'pending',
          attempts INTEGER NOT NULL DEFAULT 0,
          last_error TEXT
        );
        CREATE INDEX IF NOT EXISTS ix_outbox_state_created ON outbox(state, created_at);
        CREATE TABLE IF NOT EXISTS cache_records (
          entity_type TEXT NOT NULL,
          entity_id TEXT NOT NULL,
          payload TEXT NOT NULL,
          server_version TEXT,
          updated_at TEXT NOT NULL,
          deleted INTEGER NOT NULL DEFAULT 0,
          PRIMARY KEY(entity_type, entity_id)
        );
        CREATE TABLE IF NOT EXISTS sync_state (
          key TEXT PRIMARY KEY NOT NULL,
          value TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS media_cache (
          media_id TEXT PRIMARY KEY NOT NULL,
          version TEXT NOT NULL,
          local_uri TEXT NOT NULL,
          checksum TEXT,
          updated_at TEXT NOT NULL
        );
      `);
      const columns = await db.getAllAsync("PRAGMA table_info(outbox)");
      if (!columns.some(column => column.name === "entity_id")) {
        await db.execAsync("ALTER TABLE outbox ADD COLUMN entity_id TEXT");
      }
      return db;
    })();
  }
  return databasePromise;
}

export async function enqueueOperation({ id, entityType, entityId, operation, payload, baseVersion = null }) {
  if (!id || !entityType || !entityId || !operation) throw new Error("شناسه عملیات، نوع و شناسه رکورد و عملیات الزامی است.");
  const db = await getOfflineDb();
  const createdAt = new Date().toISOString();
  await db.runAsync(
    "INSERT OR IGNORE INTO outbox (id, entity_type, entity_id, operation, payload, base_version, created_at, state) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')",
    id, entityType, String(entityId), operation, JSON.stringify(payload), baseVersion, createdAt
  );
  return id;
}

export async function getPendingOperations(limit = 100) {
  const db = await getOfflineDb();
  return db.getAllAsync(
    "SELECT * FROM outbox WHERE state IN ('pending','failed') ORDER BY created_at LIMIT ?",
    limit
  );
}

export async function markOperation(id, state, error = null) {
  const db = await getOfflineDb();
  await db.runAsync(
    "UPDATE outbox SET state = ?, attempts = attempts + 1, last_error = ? WHERE id = ?",
    state, error, id
  );
}

export async function acknowledgeOperation(id) {
  const db = await getOfflineDb();
  await db.runAsync("UPDATE outbox SET state = 'synced', last_error = NULL WHERE id = ?", id);
}

export async function cacheRecord(entityType, entityId, payload, serverVersion = null, deleted = false) {
  const db = await getOfflineDb();
  await db.runAsync(
    `INSERT INTO cache_records(entity_type, entity_id, payload, server_version, updated_at, deleted)
     VALUES (?, ?, ?, ?, ?, ?)
     ON CONFLICT(entity_type, entity_id) DO UPDATE SET payload=excluded.payload,
     server_version=excluded.server_version, updated_at=excluded.updated_at, deleted=excluded.deleted`,
    entityType, entityId, JSON.stringify(payload), serverVersion, new Date().toISOString(), deleted ? 1 : 0
  );
}

export async function readCachedRecords(entityType) {
  const db = await getOfflineDb();
  const rows = await db.getAllAsync(
    "SELECT payload FROM cache_records WHERE entity_type = ? AND deleted = 0 ORDER BY updated_at DESC",
    entityType
  );
  return rows.map(row => JSON.parse(row.payload));
}

export async function getSyncCursor() {
  const db = await getOfflineDb();
  const row = await db.getFirstAsync("SELECT value FROM sync_state WHERE key = 'server_cursor'");
  return row?.value ?? null;
}

export async function setSyncCursor(cursor) {
  const db = await getOfflineDb();
  await db.runAsync(
    "INSERT INTO sync_state(key,value) VALUES('server_cursor',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
    String(cursor)
  );
}

export async function getCatalogVersion() {
  const db = await getOfflineDb();
  const row = await db.getFirstAsync("SELECT value FROM sync_state WHERE key = 'catalog_version'");
  return Number(row?.value || 0);
}

export async function setCatalogVersion(version) {
  const db = await getOfflineDb();
  await db.runAsync(
    "INSERT INTO sync_state(key,value) VALUES('catalog_version',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
    String(version)
  );
}
