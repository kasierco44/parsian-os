package ir.pgtparts.parsian.data
import android.content.Context
import ir.pgtparts.parsian.sync.SyncScheduler
import org.json.JSONObject
import java.util.UUID
object Outbox {
 private val allowedTypes = setOf("crm.lead.create", "crm.lead.update", "crm.task.complete", "sales.proforma.draft")
 suspend fun enqueue(context: Context, type: String, payload: JSONObject): String {
  require(type in allowedTypes) { "این نوع عملیات هنوز در سرور مجاز نشده است." }
  val uuid = UUID.randomUUID().toString()
  ParsianDatabase.get(context).syncOperations().enqueue(SyncOperationEntity(operationUuid=uuid, operationType=type, payloadJson=payload.toString()))
  SyncScheduler.enqueue(context)
  return uuid
 }
}
