package ir.pgtparts.parsian.data

import androidx.room.Entity
import androidx.room.PrimaryKey

@Entity(tableName = "dashboard_snapshots")
data class DashboardSnapshotEntity(
    @PrimaryKey val snapshotKey: String,
    val payloadJson: String,
    val updatedAtEpochMs: Long = System.currentTimeMillis()
)
