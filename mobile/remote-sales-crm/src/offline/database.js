import * as SQLite from "expo-sqlite";
import * as SecureStore from "expo-secure-store";
import * as Crypto from "expo-crypto";

const DB_NAME = "parsian-sales-secure.db";
const LEGACY_DB_NAME = "parsian-sales.db";
const DB_KEY_NAME = "parsian.sales.sqlite.key.v1";
let databasePromise;

async function getOrCreateDatabaseKey() {
  let key = await SecureStore.getItemAsync(DB_KEY_NAME);
  if (!key) {
    const bytes = await Crypto.getRandomBytesAsync(32);
    key = Array.from(bytes, byte => byte.toString(16).padStart(2, "0")).join("");
    await SecureStore.setItemAsync(DB_KEY_NAME, key, {
      keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
  }
  if (!/^[a-f0-9]{64}$/.test(key)) throw new Error("کلید رمزنگاری پایگاه داده معتبر نیست.");
  return key;
}

async function migrateLegacyPlaintextDatabase(targetDb) {
  const marker = await targetDb.getFirstAsync("SELECT value FROM sync_state WHERE key = 'legacy_plaintext_migrated'");
  if (marker) return;
  let legacyDb;
  try {
    legacyDb = await SQLite.openDatabaseAsync(LEGACY_DB_NAME);
    const tables = await legacyDb.getAllAsync("SELECT name FROM sqlite_master WHERE type='table'");
    const names = new Set(tables.map(row => row.name));
    if (names.has("outbox")) {
      const columns = await legacyDb.getAllAsync("PRAGMA table_info(outbox)");
      const hasEntityId = columns.some(column => column.name === "entity_id");
      const rows = await legacyDb.getAllAsync("SELECT * FROM outbox");
      for (const row of rows) {
        await targetDb.runAsync(
          "INSERT OR IGNORE INTO outbox(id,entity_type,entity_id,operation,payload,base_version,created_at,state,attempts,last_error) VALUES(?,?,?,?,?,?,?,?,?,?)",
          row.id, row.entity_type, hasEntityId ? row.entity_id ?? null : null, row.operation,
          row.payload, row.base_version ?? null, row.created_at, row.state || "pending",
          row.attempts || 0, row.last_error ?? null
        );
      }
    }
    if (names.has("cache_records")) {
      const rows = await legacyDb.getAllAsync("SELECT * FROM cache_records");
      for (const row of rows) {
        await targetDb.runAsync(
          "INSERT OR IGNORE INTO cache_records(entity_type,entity_id,payload,server_version,updated_at,deleted) VALUES(?,?,?,?,?,?)",
          row.entity_type, row.entity_id, row.payload, row.server_version ?? null,
          row.updated_at, row.deleted || 0
        );
      }
    }
    if (names.has("sync_state")) {
      const rows = await legacyDb.getAllAsync("SELECT * FROM sync_state");
      for (const row of rows) {
        if (row.key !== "legacy_plaintext_migrated") {
          await targetDb.runAsync(
            "INSERT OR IGNORE INTO sync_state(key,value) VALUES(?,?)",
            row.key, row.value
          );
        }
      }
    }
    if (names.has("media_cache")) {
      const rows = await legacyDb.getAllAsync("SELECT * FROM media_cache");
      for (const row of rows) {
        await targetDb.runAsync(
          "INSERT OR IGNORE INTO media_cache(media_id,version,local_uri,checksum,updated_at) VALUES(?,?,?,?,?)",
          row.media_id, row.version, row.local_uri, row.checksum ?? null, row.updated_at
        );
      }
    }
    await targetDb.runAsync(
      "INSERT INTO sync_state(key,value) VALUES('legacy_plaintext_migrated','1') ON CONFLICT(key) DO UPDATE SET value='1'"
    );
    await legacyDb.closeAsync();
    legacyDb = null;
    if (names.size) await SQLite.deleteDatabaseAsync(LEGACY_DB_NAME);
  } catch (error) {
    if (legacyDb) await legacyDb.closeAsync().catch(() => {});
    throw new Error("انتقال امن داده‌های آفلاین قبلی انجام نشد؛ فایل قدیمی دست‌نخورده باقی مانده است. " + error.message);
  }
}

export async function getOfflineDb() {
  if (!databasePromise) {
    databasePromise = (async () => {
      const db = await SQLite.openDatabaseAsync(DB_NAME);
      const cipher = await db.getFirstAsync("PRAGMA cipher_version");
      if (!cipher?.cipher_version) {
        await db.closeAsync();
        throw new Error("رمزنگاری SQLCipher در این نسخه فعال نیست؛ برنامه را با بیلد بومی جدید نصب کنید.");
      }
      const key = await getOrCreateDatabaseKey();
      await db.execAsync(`PRAGMA key = "x'${key}'";`);
      await db.getFirstAsync("SELECT count(*) AS count FROM sqlite_master");
      await db.execAsync(`
        PRAGMA journal_mode = WAL;
        PRAGMA foreign_keys = ON;
        CREATE TABLE IF NOT EXISTS outbox (
          id TEXT PRIMARY KEY NOT NULL,
          entity_type TEXT NOT NULL,
          entity_id TEXT,
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
      await migrateLegacyPlaintextDatabase(db);
      return db;
    })().catch(error => {
      databasePromise = undefined;
      throw error;
    });
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
  return db.getAllAsync("SELECT * FROM outbox WHERE state IN ('pending','failed') ORDER BY created_at LIMIT ?", limit);
}

export async function markOperation(id, state, error = null) {
  const db = await getOfflineDb();
  await db.runAsync("UPDATE outbox SET state = ?, attempts = attempts + 1, last_error = ? WHERE id = ?", state, error, id);
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
  const rows = await db.getAllAsync("SELECT payload FROM cache_records WHERE entity_type = ? AND deleted = 0 ORDER BY updated_at DESC", entityType);
  return rows.map(row => JSON.parse(row.payload));
}

export async function getSyncCursor() {
  const db = await getOfflineDb();
  const row = await db.getFirstAsync("SELECT value FROM sync_state WHERE key = 'server_cursor'");
  return row?.value ?? null;
}

export async function setSyncCursor(cursor) {
  const db = await getOfflineDb();
  await db.runAsync("INSERT INTO sync_state(key,value) VALUES('server_cursor',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", String(cursor));
}

export async function getCatalogVersion() {
  const db = await getOfflineDb();
  const row = await db.getFirstAsync("SELECT value FROM sync_state WHERE key = 'catalog_version'");
  return Number(row?.value || 0);
}

export async function setCatalogVersion(version) {
  const db = await getOfflineDb();
  await db.runAsync("INSERT INTO sync_state(key,value) VALUES('catalog_version',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", String(version));
}
