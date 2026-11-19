package ir.pgtparts.parsian.data
import androidx.room.Entity
import androidx.room.PrimaryKey
@Entity(tableName = "sync_inbox")
data class SyncEventEntity(
 @PrimaryKey val eventId: Long,
 val eventUuid: String,
 val eventType: String,
 val aggregateType: String,
 val aggregateId: Long,
 val payloadJson: String,
 val createdAt: String
)
