package ir.pgtparts.parsian.sync

import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.util.concurrent.TimeUnit

internal data class SyncHttpResult(val code: Int, val body: String)

internal class SyncHttpTransport(
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .callTimeout(45, TimeUnit.SECONDS)
        .build()
) {
    @Throws(IOException::class)
    fun execute(request: Request): SyncHttpResult {
        client.newCall(request).execute().use { response ->
            return SyncHttpResult(response.code, response.body?.string().orEmpty())
        }
    }
}

internal object SyncRetryPolicy {
    fun shouldRetry(error: Throwable): Boolean = error is IOException
}
