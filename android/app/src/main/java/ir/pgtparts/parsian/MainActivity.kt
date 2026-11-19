package ir.pgtparts.parsian

import android.content.Context
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.weight
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import ir.pgtparts.parsian.data.DashboardRepository
import ir.pgtparts.parsian.security.AuthRepository
import ir.pgtparts.parsian.security.SessionStore
import ir.pgtparts.parsian.sync.SyncScheduler
import kotlinx.coroutines.launch
import org.json.JSONArray
import org.json.JSONObject

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { ParsianMobileApp(applicationContext) }
    }
}

@Composable
private fun ParsianMobileApp(context: Context) {
    val seller = BuildConfig.APP_ROLE == "seller"
    val title = if (seller) "پارسیان‌یار فروشنده" else "پارسیان قطعه | مشتریان"
    var apiRoot by remember { mutableStateOf("") }
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var message by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var reload by remember { mutableStateOf(0) }
    var dashboard by remember { mutableStateOf<JSONObject?>(null) }
    var loggedIn by remember { mutableStateOf(SessionStore.read(context) != null) }
    val scope = rememberCoroutineScope()

    LaunchedEffect(loggedIn, reload) {
        if (loggedIn) {
            busy = true
            try {
                dashboard = DashboardRepository.load(context)
                message = "اطلاعات از هسته مرکزی دریافت شد."
            } catch (error: Exception) {
                dashboard = null
                message = error.message ?: "دریافت اطلاعات ناموفق بود."
            } finally {
                busy = false
            }
        }
    }

    MaterialTheme {
        Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(
                modifier = Modifier.fillMaxSize().padding(24.dp),
                verticalArrangement = Arrangement.Center,
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text("PARSIAN FINAL", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
                Text(title, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp))
                if (loggedIn) {
                    Text("نشست امن این دستگاه", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 8.dp))
                    Text(if (seller) "مشتریان تخصیص‌یافته، سرنخ‌ها و پیش‌فاکتورها" else "فاکتورها، پیش‌فاکتورها و دریافت‌های حساب شما", style = MaterialTheme.typography.bodySmall)
                    Column(modifier = Modifier.fillMaxWidth().padding(top = 10.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        Button(onClick = { SyncScheduler.enqueue(context); reload++ }, modifier = Modifier.fillMaxWidth()) {
                            Text("همگام‌سازی و بروزرسانی")
                        }
                        Button(onClick = {
                            scope.launch {
                                DashboardRepository.logout(context)
                                dashboard = null
                                loggedIn = false
                                message = "از حساب خارج شدید."
                            }
                        }, modifier = Modifier.fillMaxWidth()) { Text("خروج امن") }
                    }
                    if (busy) CircularProgressIndicator(modifier = Modifier.padding(12.dp))
                    dashboard?.let { data ->
                        LazyColumn(modifier = Modifier.fillMaxWidth().weight(1f).padding(top = 8.dp)) {
                            if (!seller) {
                                item {
                                    val overview = data.optJSONObject("overview") ?: JSONObject()
                                    Text("خلاصه حساب", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold)
                                    Text("تعداد فاکتور: " + overview.optInt("invoice_count"))
                                    Text("جمع فاکتورها: " + money(overview.optLong("invoice_total_toman")) + " تومان")
                                    Text("دریافت ثبت‌شده: " + money(overview.optLong("posted_received_toman")) + " تومان")
                                    Text("تعداد پیش‌فاکتور: " + overview.optInt("proforma_count"))
                                    Spacer(Modifier.height(14.dp))
                                }
                                item { portalSection(data, "invoices", "فاکتورهای من", listOf("invoice_number", "total_toman", "status", "due_date")) }
                                item { portalSection(data, "proformas", "پیش‌فاکتورهای من", listOf("document_number", "total_toman", "status", "due_date")) }
                                item { portalSection(data, "payments", "دریافت‌های من", listOf("amount_toman", "method", "reference", "status")) }
                            } else {
                                item { portalSection(data, "customers", "مشتریان تخصیص‌یافته", listOf("display_name", "province", "status")) }
                                item { portalSection(data, "leads", "سرنخ‌های فروش من", listOf("display_name", "phone", "province", "status")) }
                                item { portalSection(data, "proformas", "پیش‌فاکتورهای من", listOf("document_number", "total_toman", "status")) }
                            }
                        }
                    }
                } else {
                    Text("اتصال به محیط آزمایشی", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 20.dp))
                    OutlinedTextField(value = apiRoot, onValueChange = { apiRoot = it }, label = { Text("آدرس HTTPS سرور آزمایشی") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
                    OutlinedTextField(value = username, onValueChange = { username = it }, label = { Text("نام کاربری") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
                    OutlinedTextField(value = password, onValueChange = { password = it }, label = { Text("رمز عبور") }, visualTransformation = PasswordVisualTransformation(), singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
                    Button(
                        enabled = !busy,
                        onClick = {
                            scope.launch {
                                busy = true
                                message = ""
                                try {
                                    AuthRepository.login(context, apiRoot, username, password)
                                    password = ""
                                    loggedIn = true
                                    SyncScheduler.enqueue(context)
                                    message = "ورود موفق بود؛ نشست به‌صورت رمزگذاری‌شده ذخیره شد."
                                } catch (error: Exception) {
                                    message = error.message ?: "ورود انجام نشد."
                                } finally {
                                    busy = false
                                }
                            }
                        },
                        modifier = Modifier.fillMaxWidth().padding(top = 14.dp)
                    ) {
                        if (busy) CircularProgressIndicator() else Text("ورود امن")
                    }
                    Spacer(Modifier.height(8.dp))
                    Text("از آدرس Production استفاده نکنید؛ ابتدا Staging را وارد کنید.", style = MaterialTheme.typography.bodySmall)
                }
                if (message.isNotBlank()) Text(message, color = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(top = 12.dp))
                Text("نسخه توسعه 0.1.0 · دسترسی‌ها در سمت سرور کنترل می‌شوند.", style = MaterialTheme.typography.labelSmall, modifier = Modifier.padding(top = 14.dp))
            }
        }
    }
}

@Composable
private fun portalSection(data: JSONObject, key: String, title: String, fields: List<String>) {
    val array = data.optJSONObject(key)?.optJSONArray("items") ?: JSONArray()
    Text(title, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
    if (array.length() == 0) {
        Text("موردی برای نمایش وجود ندارد.", style = MaterialTheme.typography.bodySmall)
    } else {
        itemsPlaceholder(array, fields)
    }
}

@Composable
private fun itemsPlaceholder(array: JSONArray, fields: List<String>) {
    for (index in 0 until array.length()) {
        val row = array.optJSONObject(index) ?: JSONObject()
        val values = fields.mapNotNull { field ->
            if (!row.has(field) || row.isNull(field)) null
            else {
                val label = when (field) {
                    "display_name" -> "نام"
                    "province" -> "استان"
                    "phone" -> "تلفن"
                    "status" -> "وضعیت"
                    "invoice_number" -> "فاکتور"
                    "document_number" -> "پیش‌فاکتور"
                    "total_toman", "amount_toman" -> "مبلغ"
                    "due_date" -> "سررسید"
                    "method" -> "روش"
                    "reference" -> "شناسه"
                    else -> field
                }
                val value = row.opt(field).toString()
                label + ": " + if (field.endsWith("_toman")) money(value.toLongOrNull() ?: 0L) + " تومان" else value
            }
        }
        Text(values.joinToString(" · "), style = MaterialTheme.typography.bodySmall, modifier = Modifier.fillMaxWidth().padding(vertical = 7.dp))
    }
}

private fun money(value: Long): String = java.text.NumberFormat.getInstance(java.util.Locale("fa", "IR")).format(value)
