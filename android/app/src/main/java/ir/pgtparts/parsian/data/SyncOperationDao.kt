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
 @Insert(onConflict = OnConflictStrategy.ABORT)
 suspend fun saveEvents(events: List<SyncEventEntity>)
 @Insert(onConflict = OnConflictStrategy.REPLACE)
 suspend fun saveMetadata(metadata: SyncMetadataEntity)
 @Query("SELECT metaValue FROM sync_metadata WHERE metaKey = :key LIMIT 1")
 suspend fun metadata(key: String): String?
 @Query("SELECT * FROM sync_inbox WHERE eventId = :id LIMIT 1")
 suspend fun inboxEventById(id: Long): SyncEventEntity?
 @Query("SELECT * FROM sync_inbox WHERE eventUuid = :uuid LIMIT 1")
 suspend fun inboxEventByUuid(uuid: String): SyncEventEntity?
 @Query("SELECT COUNT(*) FROM sync_inbox")
 suspend fun inboxCount(): Int
 @Query("DELETE FROM sync_outbox")
 suspend fun clearOutbox()
 @Query("DELETE FROM sync_inbox")
 suspend fun clearInbox()
 @Query("DELETE FROM sync_metadata")
 suspend fun clearMetadata()
 @Insert(onConflict = OnConflictStrategy.REPLACE)
 suspend fun saveDashboardSnapshot(snapshot: DashboardSnapshotEntity)
 @Query("SELECT * FROM dashboard_snapshots WHERE snapshotKey = :key LIMIT 1")
 suspend fun dashboardSnapshot(key: String): DashboardSnapshotEntity?
 @Query("DELETE FROM dashboard_snapshots")
 suspend fun clearDashboardSnapshots()

 @Transaction
 suspend fun persistInboxPage(events: List<SyncEventEntity>, cursor: Long) {
  val newEvents = ArrayList<SyncEventEntity>()
  val pageById = HashMap<Long, SyncEventEntity>()
  val pageByUuid = HashMap<String, SyncEventEntity>()
  for (event in events) {
   require(event.eventId > 0L && event.eventUuid.isNotBlank()) { "Invalid server event identity" }
   val pageIdMatch = pageById[event.eventId]
   val pageUuidMatch = pageByUuid[event.eventUuid]
   if (pageIdMatch != null || pageUuidMatch != null) {
    require(pageIdMatch == event && pageUuidMatch == event) { "Conflicting duplicate inside server page" }
    continue
   }
   pageById[event.eventId] = event
   pageByUuid[event.eventUuid] = event
   val byId = inboxEventById(event.eventId)
   val byUuid = inboxEventByUuid(event.eventUuid)
   if (byId == null && byUuid == null) {
    newEvents.add(event)
   } else {
    require(byId == event && byUuid == event) { "Server event identity collision" }
   }
  }
  if (newEvents.isNotEmpty()) saveEvents(newEvents)
  saveMetadata(SyncMetadataEntity("pull_cursor", cursor.toString()))
 }
}
