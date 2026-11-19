package ir.pgtparts.parsian.data
import androidx.room.Dao
import androidx.room.Insert
import androidx.room.OnConflictStrategy
import androidx.room.Query
import androidx.room.Transaction
@Dao
interface SyncOperationDao {
 @Insert(onConflict = OnConflictStrategy.ABORT)
 suspend fun enqueue(operation: SyncOperationEntity)
 @Query("SELECT * FROM sync_outbox WHERE status = 'queued' ORDER BY createdAtEpochMs ASC LIMIT :limit")
 suspend fun queued(limit: Int = 100): List<SyncOperationEntity>
 @Query("UPDATE sync_outbox SET status = :status, lastError = :error WHERE operationUuid = :uuid")
 suspend fun updateStatus(uuid: String, status: String, error: String? = null)
 @Query("UPDATE sync_outbox SET attempts = attempts + 1, lastError = :error WHERE operationUuid = :uuid")
 suspend fun recordRetry(uuid: String, error: String)
 @Query("SELECT COUNT(*) FROM sync_outbox WHERE status = 'queued'")
 suspend fun queuedCount(): Int
 @Insert(onConflict = OnConflictStrategy.REPLACE)
 suspend fun saveEvents(events: List<SyncEventEntity>)
 @Insert(onConflict = OnConflictStrategy.REPLACE)
 suspend fun saveMetadata(metadata: SyncMetadataEntity)
 @Query("SELECT metaValue FROM sync_metadata WHERE metaKey = :key LIMIT 1")
 suspend fun metadata(key: String): String?
 @Query("SELECT COUNT(*) FROM sync_inbox")
 suspend fun inboxCount(): Int
 @Transaction
 suspend fun persistInboxPage(events: List<SyncEventEntity>, cursor: Long) {
  if (events.isNotEmpty()) saveEvents(events)
  saveMetadata(SyncMetadataEntity("pull_cursor", cursor.toString()))
 }
}
