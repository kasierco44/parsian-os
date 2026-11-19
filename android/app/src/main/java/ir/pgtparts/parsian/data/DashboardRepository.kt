package ir.pgtparts.parsian.data

import android.content.Context
import ir.pgtparts.parsian.BuildConfig
import ir.pgtparts.parsian.security.SessionStore
import ir.pgtparts.parsian.sync.SyncScheduler
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import org.json.JSONArray
import java.io.IOException
import java.util.concurrent.TimeUnit

object DashboardRepository {
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .callTimeout(45, TimeUnit.SECONDS)
        .build()
    private val jsonType = "application/json; charset=utf-8".toMediaType()
    private data class ResponseData(val code: Int, val body: String)

    private suspend fun request(url: String, method: String, token: String, body: String? = null): ResponseData =
        withContext(Dispatchers.IO) {
            val builder = Request.Builder().url(url).header("Accept", "application/json")
            if (token.isNotBlank()) builder.header("Authorization", "Bearer " + token)
            if (method == "POST") builder.post((body ?: "{}").toRequestBody(jsonType)) else builder.get()
            client.newCall(builder.build()).execute().use { ResponseData(it.code, it.body?.string().orEmpty()) }
        }

    private suspend fun authorized(context: Context, path: String): JSONObject {
        val session = SessionStore.read(context) ?: throw IllegalStateException("ابتدا وارد سامانه شوید.")
        var response = request(session.apiRoot + path, "GET", session.accessToken)
        if (response.code == 401) {
            val renewed = request(
                session.apiRoot + "/auth/refresh", "POST", "",
                JSONObject().put("refresh_token", session.refreshToken).toString()
            )
            if (renewed.code !in 200..299) throw IllegalStateException("نشست شما منقضی شده است؛ دوباره وارد شوید.")
            val tokens = JSONObject(renewed.body)
            val access = tokens.optJSONObject("access_token")?.optString("token").orEmpty()
            val refresh = tokens.optJSONObject("refresh_token")?.optString("token").orEmpty()
            if (access.isBlank() || refresh.isBlank()) throw IllegalStateException("تمدید نشست ناموفق بود.")
            SessionStore.rotate(context, access, refresh)
            response = request(session.apiRoot + path, "GET", access)
        }
        if (response.code !in 200..299) {
            val error = JSONObject(response.body.ifBlank { "{}" })
            throw IllegalStateException(error.optString("message", error.optString("code", "دریافت اطلاعات ناموفق بود.")))
        }
        return JSONObject(response.body)
    }

    private suspend fun persistOfflineCollections(dao: SyncOperationDao, dashboard: JSONObject) {
        val resources = if (BuildConfig.APP_ROLE == "customer") {
            listOf("invoices", "proformas", "payments")
        } else {
            listOf("customers", "leads", "proformas", "tasks")
        }
        val now = System.currentTimeMillis()
        for (resource in resources) {
            val response = dashboard.optJSONObject(resource)
                ?: throw IllegalStateException("پاسخ منبع آفلاین ناقص است: " + resource)
            val items = response.optJSONArray("items")
                ?: throw IllegalStateException("ساختار فهرست آفلاین نامعتبر است: " + resource)
            val records = ArrayList<OfflineRecordEntity>()
            for (index in 0 until items.length()) {
                val item = items.optJSONObject(index)
                    ?: throw IllegalStateException("رکورد نامعتبر در منبع: " + resource)
                val id = item.optLong("id", 0L)
                if (id <= 0L) throw IllegalStateException("شناسه رکورد نامعتبر در منبع: " + resource)
                records.add(OfflineRecordEntity(resource, id, item.toString(), now))
            }
            dao.replaceOfflineResource(resource, records)
        }
    }

    private suspend fun staleResourceNames(dao: SyncOperationDao): JSONArray {
        val resources = if (BuildConfig.APP_ROLE == "customer") {
            listOf("invoices", "proformas", "payments")
        } else {
            listOf("customers", "leads", "proformas", "tasks")
        }
        val stale = JSONArray()
        for (resource in resources) {
            if (dao.metadata("offline_stale_" + resource) != null) stale.put(resource)
        }
        return stale
    }

    suspend fun isOfflineResourceStale(context: Context, resourceType: String): Boolean {
        val allowed = if (BuildConfig.APP_ROLE == "customer") {
            setOf("invoices", "proformas", "payments")
        } else {
            setOf("customers", "leads", "proformas", "tasks")
        }
        require(resourceType in allowed) { "Unsupported offline resource" }
        return ParsianDatabase.get(context).syncOperations().metadata("offline_stale_" + resourceType) != null
    }

    suspend fun loadOfflineResource(context: Context, resourceType: String): JSONArray {
        val allowed = if (BuildConfig.APP_ROLE == "customer") {
            setOf("invoices", "proformas", "payments")
        } else {
            setOf("customers", "leads", "proformas", "tasks")
        }
        require(resourceType in allowed) { "Unsupported offline resource" }
        val records = ParsianDatabase.get(context).syncOperations().offlineRecords(resourceType)
        val result = JSONArray()
        records.forEach { result.put(JSONObject(it.payloadJson)) }
        return result
    }

    suspend fun load(context: Context): JSONObject {
        val dao = ParsianDatabase.get(context).syncOperations()
        return try {
            val result = JSONObject()
            if (BuildConfig.APP_ROLE == "customer") {
                result.put("overview", authorized(context, "/portal/overview"))
                result.put("invoices", authorized(context, "/portal/invoices"))
                result.put("proformas", authorized(context, "/portal/proformas"))
                result.put("payments", authorized(context, "/portal/payments"))
            } else {
                result.put("customers", authorized(context, "/customers?per_page=50"))
                result.put("leads", authorized(context, "/leads?per_page=50"))
                result.put("proformas", authorized(context, "/proformas?per_page=50"))
                result.put("tasks", authorized(context, "/tasks?per_page=50"))
            }
            persistOfflineCollections(dao, result)
            dao.saveDashboardSnapshot(DashboardSnapshotEntity("dashboard", result.toString()))
            result.put("_offline", false)
            result.put("_stale_resources", staleResourceNames(dao))
            result
        } catch (error: IOException) {
            val cached = dao.dashboardSnapshot("dashboard")
                ?: throw IllegalStateException("اتصال برقرار نیست و هنوز اطلاعاتی برای نمایش آفلاین ذخیره نشده است.")
            JSONObject(cached.payloadJson)
                .put("_offline", true)
                .put("_cached_at_epoch_ms", cached.updatedAtEpochMs)
                .put("_stale_resources", staleResourceNames(dao))
        }
    }

    suspend fun logout(context: Context) {
        val session = SessionStore.read(context)
        if (session != null) {
            try {
                val revoked = request(
                    session.apiRoot + "/auth/revoke-device", "POST", session.accessToken,
                    JSONObject().put("device_uuid", session.deviceUuid).toString()
                )
                if (revoked.code !in 200..299) request(session.apiRoot + "/auth/logout", "POST", session.accessToken, "{}")
            } catch (_: Exception) { }
        }
        SyncScheduler.cancel(context)
        val dao = ParsianDatabase.get(context).syncOperations()
        dao.clearOutbox()
        dao.clearInbox()
        dao.clearMetadata()
        dao.clearDashboardSnapshots()
        dao.clearOfflineRecords()
        SessionStore.clear(context)
    }
}
