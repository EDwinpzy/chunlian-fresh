package com.chunlian.merchant.navigation

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.ui.components.MerchantAssetIcon

@Composable
fun AuthenticationGate(onLoggedIn: () -> Unit) {
    val app = MerchantApp.instance
    var attempt by remember { mutableIntStateOf(0) }
    var loading by remember { mutableStateOf(true) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(attempt) {
        loading = true
        error = null

        if (!app.session.isEncrypted) {
            app.session.clear()
            error = "本机加密存储不可用，无法安全保存管理会话。请检查系统安全设置后重试。"
            loading = false
            return@LaunchedEffect
        }

        if (app.session.hasValidSession()) {
            onLoggedIn()
            return@LaunchedEffect
        }

        // 丢弃缺少到期时间、已过期或损坏的旧会话，再申请新的公开管理会话。
        app.session.clear()
        runCatching { app.repository.openSession(app.session.getOrCreateDeviceId()) }
            .onSuccess { onLoggedIn() }
            .onFailure { failure ->
                error = failure.message?.takeIf { it.isNotBlank() } ?: "暂时无法连接管理服务，请检查网络后重试。"
            }
        loading = false
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(C.Canvas)
            .safeDrawingPadding()
            .padding(horizontal = 28.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(12.dp, Alignment.CenterVertically),
    ) {
        MerchantAssetIcon(R.mipmap.ic_launcher, "商家应用图标", size = 68.dp)
        Text("商家管理", fontSize = T.section, fontWeight = FontWeight.Bold, color = C.Brand700)
        Text("公开免登录管理模式", fontSize = T.body, color = C.TextSecondary)

        if (loading) {
            CircularProgressIndicator(color = C.Brand700)
            Text("正在连接管理服务…", fontSize = T.body, color = C.TextSecondary)
        } else if (error != null) {
            Text(error.orEmpty(), fontSize = T.body, color = C.Danger)
            TextButton(onClick = { attempt += 1 }) {
                Text("重试", fontSize = T.body, color = C.Brand700)
            }
        }
    }
}
