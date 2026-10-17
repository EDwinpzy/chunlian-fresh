package com.chunlian.customer.ui

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.chunlian.androidupdate.AppUpdateState
import com.chunlian.customer.BuildConfig
import com.chunlian.customer.R
import com.chunlian.customer.data.CustomerUiState
import com.chunlian.customer.data.CustomerViewModel
import com.chunlian.customer.ui.theme.CustomerColors as C

@Composable
fun ProfileScreen(
    state: CustomerUiState,
    vm: CustomerViewModel,
    updateState: AppUpdateState,
    onCheckForUpdate: () -> Unit,
    onLogin: () -> Unit,
    onAddresses: () -> Unit,
    onOrders: () -> Unit,
) {
    val context = LocalContext.current
    var editNickname by rememberSaveable { mutableStateOf(false) }
    var showLogout by rememberSaveable { mutableStateOf(false) }
    var dialError by remember { mutableStateOf(false) }
    var nickname by rememberSaveable(state.user?.userId) { mutableStateOf(state.user?.nickname.orEmpty()) }
    if (editNickname) {
        AlertDialog(
            onDismissRequest = { editNickname = false },
            title = { Text("修改昵称") },
            text = { OutlinedTextField(nickname, { nickname = it }, label = { Text("昵称") }, singleLine = true) },
            confirmButton = { TextButton(onClick = { vm.updateProfile(nickname); editNickname = false }, enabled = nickname.isNotBlank() && !state.busy) { Text("保存") } },
            dismissButton = { TextButton(onClick = { editNickname = false }) { Text("取消") } },
        )
    }
    if (showLogout) {
        AlertDialog(
            onDismissRequest = { showLogout = false },
            title = { Text("退出登录？") },
            text = { Text("退出后，查看订单和管理地址需要重新登录。") },
            confirmButton = { TextButton(onClick = { showLogout = false; vm.logout() }) { Text("退出登录", color = C.Danger) } },
            dismissButton = { TextButton(onClick = { showLogout = false }) { Text("取消") } },
        )
    }
    if (dialError) {
        AlertDialog(
            onDismissRequest = { dialError = false }, title = { Text("店铺电话") },
            text = { Text("请拨打 ${state.shop.phone}") },
            confirmButton = { TextButton(onClick = { dialError = false }) { Text("知道了") } },
        )
    }
    val updateSummary = when {
        updateState.checking -> "正在检查更新…"
        updateState.downloading -> "正在下载更新${updateState.progressPercent?.let { "（$it%）" }.orEmpty()}"
        !updateState.errorMessage.isNullOrBlank() -> updateState.errorMessage
        updateState.downloadedFile != null -> "更新包已下载，等待安装"
        !updateState.statusMessage.isNullOrBlank() -> updateState.statusMessage
        updateState.release != null -> "发现新版本 ${updateState.release.versionName}"
        else -> "当前版本 ${BuildConfig.VERSION_NAME}"
    }
    Column(Modifier.fillMaxSize().background(C.Canvas).verticalScroll(rememberScrollState()).padding(bottom = 24.dp)) {
        Text("我的", fontSize = 22.sp, fontWeight = FontWeight.Bold, color = C.TextPrimary, modifier = Modifier.padding(start = 16.dp, end = 16.dp, top = 12.dp, bottom = 8.dp))
        Row(Modifier.fillMaxWidth().padding(start = 16.dp, end = 8.dp, top = 4.dp, bottom = 20.dp), verticalAlignment = Alignment.CenterVertically) {
            Box(Modifier.size(56.dp).clip(CircleShape).background(C.Brand100), contentAlignment = Alignment.Center) {
                Text(state.user?.nickname?.takeIf { it.isNotBlank() }?.take(1) ?: "你", color = C.Brand700, fontSize = 26.sp, fontWeight = FontWeight.SemiBold)
            }
            Column(Modifier.weight(1f).padding(start = 14.dp)) {
                Text(state.user?.nickname?.takeIf { it.isNotBlank() } ?: if (state.user != null) "生鲜顾客" else "登录 / 注册", fontSize = 21.sp, fontWeight = FontWeight.Bold, color = C.TextPrimary, maxLines = 2, overflow = TextOverflow.Ellipsis)
                Text(state.user?.mobileMasked ?: "登录后管理订单和收货地址", fontSize = 13.sp, color = C.TextSecondary, modifier = Modifier.padding(top = 6.dp))
            }
            TextButton(onClick = {
                if (state.user == null) onLogin() else { nickname = state.user.nickname; editNickname = true }
            }, enabled = vm.identityTransportAllowed) { Text(if (state.user == null) "去登录" else "修改昵称", color = C.Brand600, fontSize = 13.sp) }
        }
        if (!vm.secureTransport) {
            InfoBanner(
                if (vm.loopbackTestTransport) "本地测试连接未加密" else "安全连接暂未就绪",
                if (vm.loopbackTestTransport) "此构建只允许连接本机 QA 服务，请仅使用合成测试账号；正式服务仍需 HTTPS。"
                else "当前服务器使用 HTTP。为避免手机号、密码和订单资料明文传输，登录、注册、订单与地址功能暂不可用。",
                warning = true,
            )
        }
        CardBox(Modifier.padding(horizontal = 16.dp), bordered = false) {
            Column {
                ProfileMenuRow(R.drawable.cust_orders, "我的订单", onClick = onOrders)
                DividerLine(Modifier.padding(start = 56.dp, end = 16.dp))
                ProfileMenuRow(R.drawable.cust_address, "收货地址", onClick = { if (state.user == null) onLogin() else onAddresses() }, enabled = vm.identityTransportAllowed)
            }
        }
        Text("服务与设置", fontSize = 13.sp, color = C.TextSecondary, modifier = Modifier.padding(start = 24.dp, top = 16.dp, bottom = 8.dp))
        CardBox(Modifier.padding(horizontal = 16.dp), bordered = false) {
            Column {
                ProfileMenuRow(R.drawable.cust_phone, "联系店主", value = state.shop.phone.ifBlank { "暂未设置" }, enabled = state.shop.phone.isNotBlank(), onClick = {
                    try { context.startActivity(Intent(Intent.ACTION_DIAL, Uri.fromParts("tel", state.shop.phone, null))) }
                    catch (_: ActivityNotFoundException) { dialError = true }
                })
                DividerLine(Modifier.padding(start = 56.dp, end = 16.dp))
                ProfileMenuRow(R.drawable.cust_refresh, "检查应用更新", updateSummary.orEmpty(), enabled = !updateState.checking && !updateState.downloading, onClick = onCheckForUpdate)
            }
        }
        if (state.user != null) {
            TextButton(onClick = { showLogout = true }, modifier = Modifier.padding(horizontal = 16.dp, vertical = 16.dp).fillMaxWidth().heightIn(min = 48.dp).background(C.Surface, RoundedCornerShape(16.dp))) {
                Text("退出登录", color = C.Danger, fontSize = 15.sp)
            }
        }
    }
}

@Composable
private fun ProfileMenuRow(icon: Int, title: String, subtitle: String = "", value: String = "", enabled: Boolean = true, onClick: () -> Unit) {
    Row(Modifier.fillMaxWidth().clickable(enabled = enabled, onClick = onClick).heightIn(min = 56.dp).padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
        CustomerAssetIcon(icon, contentDescription = null, size = 22.dp)
        Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
            Text(title, fontSize = 15.sp, fontWeight = FontWeight.Medium, color = C.TextPrimary)
            if (subtitle.isNotBlank()) Text(subtitle, fontSize = 12.sp, color = C.TextSecondary, modifier = Modifier.padding(top = 4.dp))
        }
        if (value.isNotBlank()) Text(value, fontSize = 12.sp, color = C.TextSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = Modifier.widthIn(max = 132.dp).padding(end = 8.dp))
        if (enabled) CustomerAssetIcon(R.drawable.cust_back, contentDescription = null, modifier = Modifier.graphicsLayer { rotationZ = 180f }, size = 14.dp)
    }
}
