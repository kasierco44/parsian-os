package ir.pgtparts.parsian.data
import androidx.room.Entity
import androidx.room.PrimaryKey
@Entity(tableName = "sync_outbox")
data class SyncOperationEntity(
 @PrimaryKey val operationUuid: String,
 val operationType: String,
 val payloadJson: String,
 val status: String = "queued",
 val attempts: Int = 0,
 val lastError: String? = null,
 val createdAtEpochMs: Long = System.currentTimeMillis()
)

data class SyncOperationAcknowledgement(
 val operationUuid: String,
 val status: String,
 val error: String? = null
)
