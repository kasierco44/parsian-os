package ir.pgtparts.parsian

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp

class MainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContent { ParsianMobileApp() }
    }
}

@Composable
private fun ParsianMobileApp() {
    val seller = BuildConfig.APP_ROLE == "seller"
    val title = if (seller) "پارسیان‌یار فروشنده" else "پارسیان قطعه | مشتریان"
    val description = if (seller)
        "میزکار فروشنده برای مشتریان تخصیص‌یافته، پیگیری‌ها و پیش‌فاکتورهای پیش‌نویس"
    else
        "پنل مشتری برای مشاهده کاتالوگ، سفارش‌ها و اسناد متعلق به حساب شما"
    MaterialTheme {
        Surface(modifier = Modifier.fillMaxSize(), color = MaterialTheme.colorScheme.background) {
            Column(
                modifier = Modifier.fillMaxSize().padding(24.dp),
                verticalArrangement = Arrangement.Center,
                horizontalAlignment = Alignment.CenterHorizontally
            ) {
                Text("PARSIAN FINAL", style = MaterialTheme.typography.labelLarge, color = MaterialTheme.colorScheme.primary)
                Text(title, style = MaterialTheme.typography.headlineMedium, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 12.dp))
                Text(description, style = MaterialTheme.typography.bodyMedium, modifier = Modifier.padding(top = 12.dp))
                Text("نسخه توسعه 0.1.0 · اتصال و همگام‌سازی در حال پیاده‌سازی", style = MaterialTheme.typography.labelSmall, modifier = Modifier.padding(top = 28.dp))
            }
        }
    }
}
