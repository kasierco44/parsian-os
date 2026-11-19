package ir.pgtparts.parsian.data
import androidx.room.Entity
import androidx.room.Index
@Entity(tableName = "offline_records", primaryKeys = ["resourceType", "recordId"], indices = [Index(value = ["resourceType"])])
data class OfflineRecordEntity(val resourceType: String, val recordId: Long, val payloadJson: String, val updatedAtEpochMs: Long)
