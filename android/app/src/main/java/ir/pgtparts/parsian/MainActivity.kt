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
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import ir.pgtparts.parsian.security.AuthRepository
import ir.pgtparts.parsian.security.SessionStore
import ir.pgtparts.parsian.sync.SyncScheduler
import kotlinx.coroutines.launch

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
    var loggedIn by remember { mutableStateOf(SessionStore.read(context) != null) }
    val scope = rememberCoroutineScope()

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
                    Text("نشست امن روی این دستگاه ذخیره شده است.", modifier = Modifier.padding(top = 16.dp))
                    Text("صف عملیات آفلاین در پایگاه داده رمزگذاری‌شده نگهداری می‌شود.", style = MaterialTheme.typography.bodySmall, modifier = Modifier.padding(top = 8.dp))
                    Button(onClick = { SyncScheduler.enqueue(context) }, modifier = Modifier.padding(top = 18.dp)) {
                        Text("همگام‌سازی اکنون")
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
                if (message.isNotBlank()) Text(message, color = MaterialTheme.colorScheme.primary, modifier = Modifier.padding(top = 14.dp))
                Text("نسخه توسعه 0.1.0 · دسترسی‌ها در سمت سرور کنترل می‌شوند.", style = MaterialTheme.typography.labelSmall, modifier = Modifier.padding(top = 28.dp))
            }
        }
    }
}
