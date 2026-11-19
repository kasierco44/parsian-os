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
import ir.pgtparts.parsian.data.Outbox
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
    var leadName by remember { mutableStateOf("") }
    var leadPhone by remember { mutableStateOf("") }
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
            SyncScheduler.enqueue(context)
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
                    if (seller) {
                        OutlinedTextField(value = leadName, onValueChange = { leadName = it }, label = { Text("نام سرنخ جدید") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 8.dp))
                        OutlinedTextField(value = leadPhone, onValueChange = { leadPhone = it }, label = { Text("شماره تماس") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 6.dp))
                        Button(
                            enabled = leadName.isNotBlank(),
                            onClick = {
                                scope.launch {
                                    try {
                                        Outbox.enqueue(context, "crm.lead.create", JSONObject().put("name", leadName.trim()).put("phone", leadPhone.trim()))
                                        leadName = ""; leadPhone = ""
                                        message = "سرنخ به صف آفلاین اضافه شد؛ پس از همگام‌سازی برای بررسی سرور ارسال می‌شود و هنوز ثبت قطعی نیست."
                                        SyncScheduler.enqueue(context)
                                    } catch (error: Exception) {
                                        message = error.message ?: "ثبت پیش‌نویس انجام نشد."
                                    }
                                }
                            },
                            modifier = Modifier.fillMaxWidth().padding(top = 6.dp)
                        ) { Text("ثبت سرنخ در صف آفلاین") }
                    } catch (error: Exception) {
                                        message = error.message ?: "ثبت پیش‌نویس انجام نشد."
                                    }
                                }
                            },
                            modifier = Modifier.fillMaxWidth().padding(top = 6.dp)
                        ) { Text("ثبت پیش‌فاکتور آفلاین") }
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
                                item { taskSection(data, context) { message = "تکمیل وظیفه به صف آفلاین اضافه شد؛ پس از تأیید مدیر قطعی می‌شود."; SyncScheduler.enqueue(context) } }
                                item { leadUpdateSection(context) { message = "ویرایش سرنخ به صف آفلاین اضافه شد؛ پس از تأیید مدیر اعمال می‌شود."; SyncScheduler.enqueue(context) } }
                                item { proformaDraftSection(context) { message = "پیش‌نویس پیش‌فاکتور به صف آفلاین اضافه شد؛ تأیید مدیر لازم است."; SyncScheduler.enqueue(context) } }
                                item { portalSection(data, "proformas", "پیش‌فاکتورهای من", listOf("document_number", "total_toman", "status")) }
                            }
                        }
                    }
                } else {
                    Text("اتصال به محیط آزمایشی", style = MaterialTheme.typography.titleMedium, modifier = Modifier.padding(top = 20.dp))
                    OutlinedTextField(value = apiRoot, onValueChange = { apiRoot = it }, label = { Text("آدرس سایت آزمایشی با HTTPS") }, singleLine = true, modifier = Modifier.fillMaxWidth().padding(top = 10.dp))
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
private fun taskSection(data: JSONObject, context: Context, onQueued: () -> Unit) {
    val scope = rememberCoroutineScope()
    val tasks = data.optJSONObject("tasks")?.optJSONArray("items") ?: JSONArray()
    Text("وظایف من", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp, bottom = 6.dp))
    if (tasks.length() == 0) {
        Text("وظیفه‌ای به شما تخصیص داده نشده است.", style = MaterialTheme.typography.bodySmall)
    } else {
        for (index in 0 until tasks.length()) {
            val task = tasks.optJSONObject(index) ?: JSONObject()
            val taskId = task.optLong("id", 0L)
            val status = task.optString("status")
            Text(task.optString("title"), style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 8.dp))
            Text("وضعیت: " + if (status == "completed") "تکمیل‌شده" else "باز", style = MaterialTheme.typography.bodySmall)
            if (status == "open" && taskId > 0L) {
                Button(onClick = {
                    scope.launch {
                        try {
                            Outbox.enqueue(context, "crm.task.complete", JSONObject().put("task_id", taskId).put("completed", true))
                            onQueued()
                        } catch (_: Exception) { }
                    }
                }, modifier = Modifier.fillMaxWidth()) { Text("ثبت تکمیل آفلاین") }
            }
        }
    }
}

@Composable
private fun leadUpdateSection(context: Context, onQueued: () -> Unit) {
    var leadId by remember { mutableStateOf("") }
    var name by remember { mutableStateOf("") }
    var phone by remember { mutableStateOf("") }
    var province by remember { mutableStateOf("") }
    var notes by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    Text("ویرایش سرنخ آفلاین", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 16.dp))
    OutlinedTextField(value = leadId, onValueChange = { leadId = it }, label = { Text("شناسه سرنخ متعلق به شما") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = name, onValueChange = { name = it }, label = { Text("نام جدید") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = phone, onValueChange = { phone = it }, label = { Text("شماره تماس جدید") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = province, onValueChange = { province = it }, label = { Text("استان جدید") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = notes, onValueChange = { notes = it }, label = { Text("یادداشت جدید") }, modifier = Modifier.fillMaxWidth())
    Button(enabled = leadId.toLongOrNull()?.let { it > 0 } == true && (name.isNotBlank() || phone.isNotBlank() || province.isNotBlank() || notes.isNotBlank()),
        onClick = {
            scope.launch {
                try {
                    val payload = JSONObject().put("lead_id", leadId.toLong())
                    if (name.isNotBlank()) payload.put("display_name", name.trim())
                    if (phone.isNotBlank()) payload.put("phone", phone.trim())
                    if (province.isNotBlank()) payload.put("province", province.trim())
                    if (notes.isNotBlank()) payload.put("notes", notes.trim())
                    Outbox.enqueue(context, "crm.lead.update", payload)
                    leadId = ""; name = ""; phone = ""; province = ""; notes = ""
                    onQueued()
                } catch (_: Exception) { }
            }
        }, modifier = Modifier.fillMaxWidth().padding(top = 6.dp)
    ) { Text("ثبت ویرایش در صف آفلاین") }
}

@Composable
private fun proformaDraftSection(context: Context, onQueued: () -> Unit) {
    var customerId by remember { mutableStateOf("") }
    var productId by remember { mutableStateOf("") }
    var quantity by remember { mutableStateOf("1") }
    var unitPrice by remember { mutableStateOf("") }
    val scope = rememberCoroutineScope()
    Text("پیش‌نویس پیش‌فاکتور آفلاین", style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 16.dp))
    OutlinedTextField(value = customerId, onValueChange = { customerId = it }, label = { Text("شناسه مشتری تخصیص‌یافته") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = productId, onValueChange = { productId = it }, label = { Text("شناسه محصول فعال") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = quantity, onValueChange = { quantity = it }, label = { Text("تعداد") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    OutlinedTextField(value = unitPrice, onValueChange = { unitPrice = it }, label = { Text("قیمت واحد به تومان") }, singleLine = true, modifier = Modifier.fillMaxWidth())
    Button(
        enabled = customerId.toLongOrNull()?.let { it > 0 } == true &&
            productId.toLongOrNull()?.let { it > 0 } == true &&
            quantity.toLongOrNull()?.let { it > 0 } == true &&
            unitPrice.toLongOrNull()?.let { it > 0 } == true,
        onClick = {
            scope.launch {
                try {
                    val line = JSONObject().put("product_id", productId.toLong())
                        .put("quantity", quantity.toLong()).put("unit_price_toman", unitPrice.toLong())
                    val payload = JSONObject().put("customer_id", customerId.toLong()).put("items", JSONArray().put(line))
                    Outbox.enqueue(context, "sales.proforma.draft", payload)
                    customerId = ""; productId = ""; quantity = "1"; unitPrice = ""
                    onQueued()
                } catch (_: Exception) { }
            }
        },
        modifier = Modifier.fillMaxWidth().padding(top = 6.dp)
    ) { Text("ثبت پیش‌فاکتور در صف آفلاین") }
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
                val rawValue = row.opt(field).toString()
                val value = if (field == "status") when (rawValue) {
                    "posted" -> "ثبت‌شده"
                    "draft" -> "پیش‌نویس"
                    "approved" -> "تأییدشده"
                    "pending_review" -> "در انتظار بررسی"
                    "pending_approval" -> "در انتظار تأیید"
                    "rejected" -> "ردشده"
                    "new" -> "جدید"
                    "active" -> "فعال"
                    "paid" -> "تسویه‌شده"
                    "partially_paid" -> "پرداخت جزئی"
                    else -> rawValue
                } else rawValue
                label + ": " + when { field.endsWith("_toman") -> money(value.toLongOrNull() ?: 0L) + " تومان"; field.endsWith("_date") || field.endsWith("_at") -> jalaliDate(value); else -> value }
            }
        }
        Text(values.joinToString(" · "), style = MaterialTheme.typography.bodySmall, modifier = Modifier.fillMaxWidth().padding(vertical = 7.dp))
    }
}

private fun jalaliDate(value: String): String {
    return try {
        val date = java.time.LocalDate.parse(value.take(10))
        val persian = android.icu.util.PersianCalendar(android.icu.util.TimeZone.getTimeZone("UTC"))
        persian.timeInMillis = date.atStartOfDay(java.time.ZoneOffset.UTC).toInstant().toEpochMilli()
        val digits = java.text.NumberFormat.getIntegerInstance(java.util.Locale("fa", "IR"))
        digits.format(persian.get(android.icu.util.Calendar.YEAR)) + "/" +
            digits.format(persian.get(android.icu.util.Calendar.MONTH) + 1) + "/" +
            digits.format(persian.get(android.icu.util.Calendar.DAY_OF_MONTH))
    } catch (_: Exception) { value }
}

private fun money(value: Long): String = java.text.NumberFormat.getInstance(java.util.Locale("fa", "IR")).format(value)
