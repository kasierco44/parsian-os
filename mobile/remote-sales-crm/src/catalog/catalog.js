import * as FileSystem from "expo-file-system/legacy";
import { getOfflineDb } from "../offline/database";

const API_BASE = (process.env.EXPO_PUBLIC_API_BASE_URL || "").replace(/\\/+$/, "");

export async function getCatalogManifest(token, sinceVersion = null) {
  if (!API_BASE) throw new Error("نشانی API تنظیم نشده است.");
  const query = sinceVersion ? `?since_version=${encodeURIComponent(sinceVersion)}` : "";
  const response = await fetch(`${API_BASE}/catalog/manifest${query}`, {
    headers: { Authorization: `Bearer ${token}`, Accept: "application/json" },
  });
  if (!response.ok) throw new Error("دریافت فهرست محصولات ناموفق بود.");
  return response.json();
}

export async function downloadCatalogMedia({ token, media }) {
  if (!media?.id || !media?.download_url || !media?.version) throw new Error("اطلاعات فایل کاتالوگ ناقص است.");
  const root = FileSystem.documentDirectory + "catalog/";
  await FileSystem.makeDirectoryAsync(root, { intermediates: true });
  const safeId = String(media.id).replace(/[^a-zA-Z0-9_-]/g, "_");
  const destination = `${root}${safeId}-${media.version}`;
  const result = await FileSystem.downloadAsync(media.download_url, destination, {
    headers: { Authorization: `Bearer ${token}` },
  });
  if (result.status !== 200) throw new Error("دانلود تصویر محصول ناموفق بود.");
  const db = await getOfflineDb();
  await db.runAsync(
    "INSERT INTO media_cache(media_id,version,local_uri,checksum,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(media_id) DO UPDATE SET version=excluded.version,local_uri=excluded.local_uri,checksum=excluded.checksum,updated_at=excluded.updated_at",
    String(media.id), String(media.version), result.uri, media.checksum || null, new Date().toISOString()
  );
  return result.uri;
}
