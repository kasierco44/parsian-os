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

    suspend fun load(context: Context): JSONObject {
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
        return result
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
        SessionStore.clear(context)
    }
}
