package ir.pgtparts.parsian.security
import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.util.UUID
data class ParsianSession(val apiRoot: String, val accessToken: String, val refreshToken: String, val deviceUuid: String)
object SessionStore {
 private const val PREFS = "parsian_secure_session"
 private fun prefs(context: Context) = EncryptedSharedPreferences.create(
  context, PREFS,
  MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
  EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
  EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM
 )
 fun deviceUuid(context: Context): String {
  val store = prefs(context)
  val existing = store.getString("device_uuid", null)
  if (existing != null) return existing
  val created = UUID.randomUUID().toString()
  store.edit().putString("device_uuid", created).apply()
  return created
 }
 fun save(context: Context, apiRoot: String, accessToken: String, refreshToken: String) {
  prefs(context).edit().putString("api_root", apiRoot.trimEnd('/')).putString("access_token", accessToken).putString("refresh_token", refreshToken).apply()
 }
 fun read(context: Context): ParsianSession? {
  val store = prefs(context)
  val apiRoot = store.getString("api_root", null) ?: return null
  val access = store.getString("access_token", null) ?: return null
  val refresh = store.getString("refresh_token", null) ?: return null
  return ParsianSession(apiRoot, access, refresh, deviceUuid(context))
 }
 fun rotate(context: Context, accessToken: String, refreshToken: String) {
  prefs(context).edit().putString("access_token", accessToken).putString("refresh_token", refreshToken).apply()
 }
 fun clear(context: Context) {
  val store = prefs(context); val device = store.getString("device_uuid", null)
  store.edit().clear().apply()
  if (device != null) store.edit().putString("device_uuid", device).apply()
 }
}
