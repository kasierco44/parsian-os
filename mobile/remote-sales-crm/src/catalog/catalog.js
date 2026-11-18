import * as FileSystem from "expo-file-system/legacy";
import { cacheRecord, getCatalogVersion, getOfflineDb, readCachedRecords, setCatalogVersion } from "../offline/database";

const API_BASE = (process.env.EXPO_PUBLIC_API_BASE_URL || "").replace(/\/+$/, "");

export async function getCatalogManifest(token, sinceVersion = 0) {
  if (!API_BASE) throw new Error("نشانی API تنظیم نشده است.");
  const response = await fetch(`${API_BASE}/catalog/manifest?since_version=${encodeURIComponent(sinceVersion)}`, {
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
  const url = /^https?:\/\//i.test(media.download_url) ? media.download_url : `${API_BASE}${media.download_url}`;
  const result = await FileSystem.downloadAsync(url, destination, {
    headers: { Authorization: `Bearer ${token}` },
  });
  if (result.status !== 200) throw new Error("دانلود تصویر محصول ناموفق بود.");
  const info = await FileSystem.getInfoAsync(result.uri);
  if (!info.exists || !info.size || info.size !== media.size_bytes) {
    await FileSystem.deleteAsync(result.uri, { idempotent: true });
    throw new Error("اندازه فایل دریافتی با اطلاعات سرور مطابقت ندارد.");
  }
  const db = await getOfflineDb();
  await db.runAsync(
    "INSERT INTO media_cache(media_id,version,local_uri,checksum,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(media_id) DO UPDATE SET version=excluded.version,local_uri=excluded.local_uri,checksum=excluded.checksum,updated_at=excluded.updated_at",
    String(media.id), String(media.version), result.uri, media.checksum || null, new Date().toISOString()
  );
  return result.uri;
}

export async function synchronizeCatalog(token) {
  const previousVersion = await getCatalogVersion();
  const manifest = await getCatalogManifest(token, previousVersion);
  const products = await readCachedRecords("product");
  const productMap = new Map(products.map(item => [String(item.id), item]));
  for (const product of manifest.products || []) {
    const existing = productMap.get(String(product.id)) || {};
    const merged = { ...existing, ...product };
    if (product.is_active === false) productMap.delete(String(product.id));
    else productMap.set(String(product.id), merged);
    await cacheRecord("product", String(product.id), merged, product.catalog_version, product.is_active === false);
  }
  for (const media of manifest.media || []) {
    const product = productMap.get(String(media.product_id));
    if (!product) continue;
    const uri = await downloadCatalogMedia({ token, media });
    if (product) {
      product.image_uri = uri;
      await cacheRecord("product", String(product.id), product, product.catalog_version, false);
    }
  }
  await setCatalogVersion(manifest.version);
  return Array.from(productMap.values()).filter(item => item.is_active !== false);
}
