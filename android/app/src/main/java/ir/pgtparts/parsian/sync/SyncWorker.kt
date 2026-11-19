package ir.pgtparts.parsian.sync
import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import ir.pgtparts.parsian.data.ParsianDatabase
import ir.pgtparts.parsian.data.SyncEventEntity
import ir.pgtparts.parsian.data.SyncOperationAcknowledgement
import ir.pgtparts.parsian.security.SessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException

class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
 private val transport = SyncHttpTransport()
 private val jsonType = "application/json; charset=utf-8".toMediaType()

 private suspend fun execute(request: Request): SyncHttpResult = withContext(Dispatchers.IO) {
  transport.execute(request)
 }

 private suspend fun post(url: String, body: String, token: String): SyncHttpResult {
  val builder = Request.Builder().url(url).header("Accept", "application/json").post(body.toRequestBody(jsonType))
  if (token.isNotBlank()) builder.header("Authorization", "Bearer " + token)
  return execute(builder.build())
 }

 private suspend fun get(url: String, token: String): SyncHttpResult {
  val builder = Request.Builder().url(url).header("Accept", "application/json").get()
  if (token.isNotBlank()) builder.header("Authorization", "Bearer " + token)
  return execute(builder.build())
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

 private suspend fun authenticatedPost(apiRoot: String, path: String, body: String, initialToken: String, refreshToken: String): SyncHttpResult {
  var result = post(apiRoot + path, body, initialToken)
  if (result.code == 401) {
   val renewed = refresh(apiRoot, refreshToken) ?: return result
   result = post(apiRoot + path, body, renewed)
  }
  return result
 }

 private suspend fun authenticatedGet(apiRoot: String, path: String, initialToken: String, refreshToken: String): SyncHttpResult {
  var result = get(apiRoot + path, initialToken)
  if (result.code == 401) {
   val renewed = refresh(apiRoot, refreshToken) ?: return result
   result = get(apiRoot + path, renewed)
  }
  return result
 }

 override suspend fun doWork(): Result {
  val session = SessionStore.read(applicationContext) ?: return Result.failure()
  val dao = ParsianDatabase.get(applicationContext).syncOperations()
  val pending = dao.queued(100)

  return try {
   if (pending.isNotEmpty()) {
    val deviceBody = JSONObject().put("device_uuid", session.deviceUuid)
     .put("platform", "android").put("label", android.os.Build.MODEL ?: "Android device").toString()
    val deviceResult = authenticatedPost(session.apiRoot, "/sync/devices", deviceBody, session.accessToken, session.refreshToken)
    if (deviceResult.code !in 200..299) {
     pending.forEach { dao.recordRetry(it.operationUuid, "device_registration_http_" + deviceResult.code) }
     return if (deviceResult.code >= 500 || deviceResult.code == 429) Result.retry() else Result.failure()
    }

    val operations = JSONArray()
    pending.forEach { operation ->
     operations.put(JSONObject().put("operation_uuid", operation.operationUuid)
      .put("type", operation.operationType).put("payload", JSONObject(operation.payloadJson)))
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
      "pending_review", "accepted" -> dao.updateStatus(uuid, "pending_review")
      "conflict" -> dao.updateStatus(uuid, "conflict", "record_changed_on_server")
      "applied" -> dao.updateStatus(uuid, "applied")
      "rejected" -> dao.updateStatus(uuid, "rejected", item.optString("code"))
      "retry" -> dao.recordRetry(uuid, "server_requested_retry")
      else -> dao.recordRetry(uuid, "unknown_server_result")
     }
    }
   }

   var cursor = dao.metadata("pull_cursor")?.toLongOrNull() ?: 0L
   var morePages = false
   for (page in 0 until 5) {
    val latest = SessionStore.read(applicationContext) ?: session
    val pull = authenticatedGet(session.apiRoot, "/sync/pull?since_id=" + cursor, latest.accessToken, latest.refreshToken)
    if (pull.code !in 200..299) {
     return if (pull.code >= 500 || pull.code == 429 || pull.code == 401) Result.retry() else Result.failure()
    }
    val body = JSONObject(pull.body)
    val previousCursor = cursor
    val items = body.optJSONArray("items") ?: JSONArray()
    val events = ArrayList<SyncEventEntity>()
    val operationAcknowledgements = ArrayList<SyncOperationAcknowledgement>()
    val staleResources = linkedSetOf<String>()
    for (index in 0 until items.length()) {
     val item = items.optJSONObject(index) ?: continue
     val eventId = item.optLong("id", -1L)
     if (eventId <= 0L) continue
     val eventType = item.optString("event_type")
     val aggregateType = item.optString("aggregate_type")
     when (aggregateType) {
      "customer" -> staleResources.add("customers")
      "lead" -> staleResources.add("leads")
      "task" -> staleResources.add("tasks")
      "proforma" -> staleResources.add("proformas")
      "invoice" -> staleResources.add("invoices")
      "payment" -> staleResources.add("payments")
     }
     val payloadJson = item.optString("payload", "{}")
     val eventPayload = try { JSONObject(payloadJson) } catch (_: Exception) { JSONObject() }
     val operationUuid = eventPayload.optString("operation_uuid")
     if (operationUuid.isNotBlank()) {
      when (eventType) {
       "crm.lead.created.offline", "crm.lead.updated.offline", "crm.task.completed.offline", "sales.proforma.created.offline",
       "sync.customer.updated.offline", "sync.proforma.updated.offline", "sync.invoice.updated.offline", "sync.payment.updated.offline" ->
        operationAcknowledgements.add(SyncOperationAcknowledgement(operationUuid, "applied"))
       "sync.operation.conflict" ->
        operationAcknowledgements.add(SyncOperationAcknowledgement(operationUuid, "conflict", "record_changed_on_server"))
       "sync.operation.rejected" ->
        operationAcknowledgements.add(SyncOperationAcknowledgement(operationUuid, "rejected", eventPayload.optString("reason")))
      }
     }
     events.add(SyncEventEntity(
      eventId = eventId,
      eventUuid = item.optString("event_uuid"),
      eventType = eventType,
      aggregateType = aggregateType,
      aggregateId = item.optLong("aggregate_id"),
      payloadJson = payloadJson,
      createdAt = item.optString("created_at")
     ))
    }
    val nextCursor = body.optLong("next_cursor", cursor)
    if (nextCursor < cursor) return Result.failure()
    // Inbox identities, cursor, stale markers and outbox acknowledgements commit as one Room transaction.
    dao.persistInboxPage(events, nextCursor, staleResources, operationAcknowledgements)
    cursor = nextCursor
    morePages = body.optBoolean("has_more", false)
    if (!morePages || (events.isEmpty() && nextCursor == previousCursor)) break
   }
   if (morePages || dao.queuedCount() > 0) Result.retry() else Result.success()
  } catch (error: IOException) {
   pending.forEach { dao.recordRetry(it.operationUuid, error.javaClass.simpleName) }
   if (SyncRetryPolicy.shouldRetry(error)) Result.retry() else Result.failure()
  } catch (error: Exception) {
   pending.forEach { dao.recordRetry(it.operationUuid, error.javaClass.simpleName) }
   Result.failure()
  }
}
