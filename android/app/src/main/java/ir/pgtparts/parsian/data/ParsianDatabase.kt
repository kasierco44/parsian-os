package ir.pgtparts.parsian.data
import android.content.Context
import androidx.room.Database
import androidx.room.Room
import androidx.room.RoomDatabase
import net.sqlcipher.database.SQLiteDatabase
import net.sqlcipher.database.SupportFactory
import ir.pgtparts.parsian.security.SessionStore
@Database(entities = [SyncOperationEntity::class], version = 1, exportSchema = true)
abstract class ParsianDatabase : RoomDatabase() {
 abstract fun syncOperations(): SyncOperationDao
 companion object {
  @Volatile private var INSTANCE: ParsianDatabase? = null
  fun get(context: Context): ParsianDatabase = INSTANCE ?: synchronized(this) {
   INSTANCE ?: Room.databaseBuilder(context.applicationContext, ParsianDatabase::class.java, "parsian-local.db")
                .openHelperFactory(SupportFactory(SQLiteDatabase.getBytes(SessionStore.databasePassphrase(context))))
                .build().also { INSTANCE = it }
  }
 }
}
