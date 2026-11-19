package ir.pgtparts.parsian.sync

import okhttp3.Request
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import okhttp3.mockwebserver.SocketPolicy
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import java.io.IOException

class SyncHttpTransportTest {
    @Test
    fun successfulResponseIsReadCompletely() {
        val server = MockWebServer()
        server.enqueue(MockResponse().setResponseCode(200).setBody("""{"status":"ok"}"""))
        server.start()
        try {
            val request = Request.Builder().url(server.url("/sync/pull")).get().build()
            val response = SyncHttpTransport().execute(request)
            assertEquals(200, response.code)
            assertEquals("""{"status":"ok"}""", response.body)
        } finally {
            server.shutdown()
        }
    }

    @Test
    fun midResponseDisconnectIsClassifiedForRetry() {
        val server = MockWebServer()
        server.enqueue(
            MockResponse()
                .setSocketPolicy(SocketPolicy.DISCONNECT_DURING_RESPONSE_BODY)
                .setBody("x".repeat(1024 * 1024))
        )
        server.start()
        try {
            val request = Request.Builder().url(server.url("/sync/pull")).get().build()
            val failure = try {
                SyncHttpTransport().execute(request)
                throw AssertionError("Expected the interrupted response to fail")
            } catch (error: IOException) {
                error
            }
            assertTrue("network interruption must be retryable", SyncRetryPolicy.shouldRetry(failure))
        } finally {
            server.shutdown()
        }
    }
}
