package ir.pgtparts.parsian.data
import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import androidx.room.migration.Migration
import androidx.sqlite.db.SupportSQLiteDatabase
import net.sqlcipher.database.SQLiteDatabase
import net.sqlcipher.database.SupportFactory
import ir.pgtparts.parsian.security.SessionStore
@Database(entities = [SyncOperationEntity::class, SyncEventEntity::class, SyncMetadataEntity::class, DashboardSnapshotEntity::class, OfflineRecordEntity::class], version = 5, exportSchema = true)
abstract class ParsianDatabase : RoomDatabase() {
 abstract fun syncOperations(): SyncOperationDao
 companion object {
  private val MIGRATION_1_2 = object : Migration(1, 2) {
   override fun migrate(database: SupportSQLiteDatabase) {
    database.execSQL("CREATE TABLE IF NOT EXISTS sync_inbox (eventId INTEGER NOT NULL PRIMARY KEY, eventUuid TEXT NOT NULL, eventType TEXT NOT NULL, aggregateType TEXT NOT NULL, aggregateId INTEGER NOT NULL, payloadJson TEXT NOT NULL, createdAt TEXT NOT NULL)")
    database.execSQL("CREATE TABLE IF NOT EXISTS sync_metadata (metaKey TEXT NOT NULL PRIMARY KEY, metaValue TEXT NOT NULL)")
   }
  }
  private val MIGRATION_2_3 = object : Migration(2, 3) {
   override fun migrate(database: SupportSQLiteDatabase) {
    database.execSQL("CREATE TABLE IF NOT EXISTS dashboard_snapshots (snapshotKey TEXT NOT NULL PRIMARY KEY, payloadJson TEXT NOT NULL, updatedAtEpochMs INTEGER NOT NULL)")
   }
  }
  private val MIGRATION_3_4 = object : Migration(3, 4) {
   override fun migrate(database: SupportSQLiteDatabase) {
    database.execSQL("CREATE UNIQUE INDEX IF NOT EXISTS index_sync_inbox_eventUuid ON sync_inbox(eventUuid)")
   }
  }
  private val MIGRATION_4_5 = object : Migration(4, 5) {
   override fun migrate(database: SupportSQLiteDatabase) {
    database.execSQL("CREATE TABLE IF NOT EXISTS offline_records (resourceType TEXT NOT NULL, recordId INTEGER NOT NULL, payloadJson TEXT NOT NULL, updatedAtEpochMs INTEGER NOT NULL, PRIMARY KEY(resourceType, recordId))")
    database.execSQL("CREATE INDEX IF NOT EXISTS index_offline_records_resourceType ON offline_records(resourceType)")
   }
  }
  @Volatile private var INSTANCE: ParsianDatabase? = null
  fun get(context: Context): ParsianDatabase = INSTANCE ?: synchronized(this) {
   SQLiteDatabase.loadLibs(context)
   INSTANCE ?: Room.databaseBuilder(context.applicationContext, ParsianDatabase::class.java, "parsian-local.db")
    .openHelperFactory(SupportFactory(SQLiteDatabase.getBytes(SessionStore.databasePassphrase(context))))
    .addMigrations(MIGRATION_1_2, MIGRATION_2_3, MIGRATION_3_4, MIGRATION_4_5)
    .build().also { INSTANCE = it }
  }
 }
}
