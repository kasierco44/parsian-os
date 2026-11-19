package ir.pgtparts.parsian.data
import androidx.room.Entity
import androidx.room.PrimaryKey
@Entity(tableName = "sync_metadata")
data class SyncMetadataEntity(@PrimaryKey val metaKey: String, val metaValue: String)
