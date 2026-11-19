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
@Database(entities = [SyncOperationEntity::class, SyncEventEntity::class, SyncMetadataEntity::class], version = 2, exportSchema = true)
abstract class ParsianDatabase : RoomDatabase() {
 abstract fun syncOperations(): SyncOperationDao
 companion object {
  private val MIGRATION_1_2 = object : Migration(1, 2) {
   override fun migrate(database: SupportSQLiteDatabase) {
    database.execSQL("CREATE TABLE IF NOT EXISTS sync_inbox (eventId INTEGER NOT NULL PRIMARY KEY, eventUuid TEXT NOT NULL, eventType TEXT NOT NULL, aggregateType TEXT NOT NULL, aggregateId INTEGER NOT NULL, payloadJson TEXT NOT NULL, createdAt TEXT NOT NULL)")
    database.execSQL("CREATE TABLE IF NOT EXISTS sync_metadata (metaKey TEXT NOT NULL PRIMARY KEY, metaValue TEXT NOT NULL)")
   }
  }
  @Volatile private var INSTANCE: ParsianDatabase? = null
  fun get(context: Context): ParsianDatabase = INSTANCE ?: synchronized(this) {
   SQLiteDatabase.loadLibs(context)
   INSTANCE ?: Room.databaseBuilder(context.applicationContext, ParsianDatabase::class.java, "parsian-local.db")
    .openHelperFactory(SupportFactory(SQLiteDatabase.getBytes(SessionStore.databasePassphrase(context))))
    .addMigrations(MIGRATION_1_2)
    .build().also { INSTANCE = it }
  }
 }
}
