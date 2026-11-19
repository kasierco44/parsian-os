package ir.pgtparts.parsian.sync
import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import ir.pgtparts.parsian.data.ParsianDatabase
import ir.pgtparts.parsian.security.SessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.util.concurrent.TimeUnit
class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
 private val client = OkHttpClient.Builder().connectTimeout(15, TimeUnit.SECONDS).readTimeout(30, TimeUnit.SECONDS).callTimeout(45, TimeUnit.SECONDS).build()
 private val jsonType = "application/json; charset=utf-8".toMediaType()
 private data class HttpResult(val code: Int, val body: String)
 private suspend fun post(url: String, body: String, token: String): HttpResult = withContext(Dispatchers.IO) {
  val builder = Request.Builder().url(url).header("Accept", "application/json").post(body.toRequestBody(jsonType))
  if (token.isNotBlank()) builder.header("Authorization", "Bearer " + token)
  client.newCall(builder.build()).execute().use { response -> HttpResult(response.code, response.body?.string().orEmpty()) }
 }
 private suspend fun refresh(apiRoot: String, refreshToken: String): String? {
  val result = post(apiRoot + "/auth/refresh", JSONObject().put("refresh_token", refreshToken).toString(), "")
  if (result.code !in 200..299) return null
  val json = JSONObject(result.body)
  val access = json.optJSONObject("access_token")?.optString("token").orEmpty()
  val refresh = json.optJSONObject("refresh_token")?.optString("token").orEmpty()
  if (access.isBlank() || refresh.isBlank()) return null
  SessionStore.rotate(applicationContext, access, refresh)
  return access
 }
 private suspend fun authenticatedPost(apiRoot: String, path: String, body: String, initialToken: String, refreshToken: String): HttpResult {
  var result = post(apiRoot + path, body, initialToken)
  if (result.code == 401) {
   val renewed = refresh(apiRoot, refreshToken) ?: return result
   result = post(apiRoot + path, body, renewed)
  }
  return result
 }
 override suspend fun doWork(): Result {
  val session = SessionStore.read(applicationContext) ?: return Result.failure()
  val dao = ParsianDatabase.get(applicationContext).syncOperations()
  val pending = dao.queued(100)
  if (pending.isEmpty()) return Result.success()
  return try {
   val deviceBody = JSONObject().put("device_uuid", session.deviceUuid).put("platform", "android").put("label", android.os.Build.MODEL ?: "Android device").toString()
   val deviceResult = authenticatedPost(session.apiRoot, "/sync/devices", deviceBody, session.accessToken, session.refreshToken)
   if (deviceResult.code !in 200..299) {
    pending.forEach { dao.recordRetry(it.operationUuid, "device_registration_http_" + deviceResult.code) }
    return if (deviceResult.code >= 500 || deviceResult.code == 429) Result.retry() else Result.failure()
   }
   val operations = JSONArray()
   pending.forEach { operation ->
    operations.put(JSONObject().put("operation_uuid", operation.operationUuid).put("type", operation.operationType).put("payload", JSONObject(operation.payloadJson)))
   }
   val pushBody = JSONObject().put("device_uuid", session.deviceUuid).put("operations", operations).toString()
   val latest = SessionStore.read(applicationContext) ?: session
   val pushResult = authenticatedPost(session.apiRoot, "/sync/push", pushBody, latest.accessToken, latest.refreshToken)
   if (pushResult.code !in 200..299) {
    pending.forEach { dao.recordRetry(it.operationUuid, "sync_push_http_" + pushResult.code) }
    return if (pushResult.code >= 500 || pushResult.code == 429) Result.retry() else Result.failure()
   }
   val results = JSONObject(pushResult.body).optJSONArray("results") ?: JSONArray()
   for (index in 0 until results.length()) {
    val item = results.optJSONObject(index) ?: continue
    val uuid = item.optString("operation_uuid")
    if (uuid.isBlank()) continue
    when (item.optString("status")) {
     "pending_review", "accepted", "applied" -> dao.updateStatus(uuid, "pending_review")
     "rejected" -> dao.updateStatus(uuid, "rejected", item.optString("code"))
     "retry" -> dao.recordRetry(uuid, "server_requested_retry")
     else -> dao.recordRetry(uuid, "unknown_server_result")
    }
   }
   if (dao.queuedCount() > 0) Result.retry() else Result.success()
  } catch (error: IOException) {
   pending.forEach { dao.recordRetry(it.operationUuid, error.javaClass.simpleName) }
   Result.retry()
  } catch (error: Exception) {
   pending.forEach { dao.recordRetry(it.operationUuid, error.javaClass.simpleName) }
   Result.failure()
  }
 }
}
