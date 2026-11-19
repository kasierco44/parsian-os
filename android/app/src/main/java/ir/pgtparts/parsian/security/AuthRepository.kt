package ir.pgtparts.parsian.security

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.util.concurrent.TimeUnit

data class LoginResult(val userId: Int, val deviceUuid: String)

object AuthRepository {
    private val client = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .callTimeout(30, TimeUnit.SECONDS)
        .build()
    private val jsonType = "application/json; charset=utf-8".toMediaType()

    suspend fun login(
        context: Context,
        apiRoot: String,
        username: String,
        password: String
    ): LoginResult = withContext(Dispatchers.IO) {
        val root = apiRoot.trim().trimEnd('/')
        require(root.startsWith("https://")) { "آدرس سرور باید با HTTPS شروع شود." }
        require(username.isNotBlank() && password.isNotBlank()) { "نام کاربری و رمز عبور الزامی است." }
        val deviceUuid = SessionStore.deviceUuid(context)
        val payload = JSONObject()
            .put("username", username)
            .put("password", password)
            .put("device_uuid", deviceUuid)
            .put("platform", "android")
            .put("device_label", android.os.Build.MODEL ?: "Android")
            .toString()
        val request = Request.Builder()
            .url(root + "/auth/login")
            .header("Accept", "application/json")
            .post(payload.toRequestBody(jsonType))
            .build()
        client.newCall(request).execute().use { response ->
            val raw = response.body?.string().orEmpty()
            val json = JSONObject(raw.ifBlank { "{}" })
            if (!response.isSuccessful) {
                throw IllegalStateException(json.optString("message", json.optString("code", "ورود ناموفق بود.")))
            }
            val access = json.optJSONObject("access_token")?.optString("token").orEmpty()
            val refresh = json.optJSONObject("refresh_token")?.optString("token").orEmpty()
            if (access.isBlank() || refresh.isBlank()) throw IllegalStateException("توکن معتبر از سرور دریافت نشد.")
            SessionStore.save(context, root, access, refresh)
            LoginResult(json.optInt("user_id"), json.optString("device_uuid", deviceUuid))
        }
    }
}
