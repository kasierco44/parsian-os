package ir.pgtparts.parsian.sync
import android.content.Context
import androidx.work.Constraints
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
object SyncScheduler {
 private const val UNIQUE_WORK = "parsian-offline-outbox"
 fun enqueue(context: Context) {
  val constraints = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
  val request = OneTimeWorkRequestBuilder<SyncWorker>().setConstraints(constraints).build()
  WorkManager.getInstance(context).enqueueUniqueWork(UNIQUE_WORK, ExistingWorkPolicy.APPEND_OR_REPLACE, request)
 }
}
