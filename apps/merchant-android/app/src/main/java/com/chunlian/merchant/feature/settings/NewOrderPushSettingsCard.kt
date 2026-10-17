package com.chunlian.merchant.feature.settings

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.LifecycleOwner
import com.chunlian.merchant.BuildConfig
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.push.hasValidPrivacyPolicyUrl
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.SecondaryButton
import com.igexin.sdk.PushManager
import kotlinx.coroutines.launch

@Composable
internal fun NewOrderPushSettingsCard() {
    val app = MerchantApp.instance
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val policyUrl = BuildConfig.MERCHANT_PRIVACY_POLICY_URL.trim()
    val hasPolicy = hasValidPrivacyPolicyUrl(policyUrl)
    val hasAppId = BuildConfig.GETUI_APP_ID.isNotBlank()

    var enabled by rememberSaveable { mutableStateOf(app.session.getuiPrivacyConsentAccepted) }
    var showConsent by rememberSaveable { mutableStateOf(false) }
    var notificationsAllowed by remember { mutableStateOf(areSystemNotificationsAllowed(context)) }
    var statusMessage by rememberSaveable { mutableStateOf<String?>(null) }
    var unbindPending by rememberSaveable { mutableStateOf(app.session.pushUnregisterPending) }

    LaunchedEffect(app.session.pushUnregisterPending) {
        if (!app.session.pushUnregisterPending) return@LaunchedEffect
        unbindPending = true
        val unregistered = app.repository.unregisterPushDeviceForPrivacyRevocation()
        unbindPending = !unregistered
        statusMessage = if (unregistered) {
            "服务器设备解绑完成。"
        } else {
            "本机提醒已关闭，服务器设备解绑暂未完成；请保持登录并重试。"
        }
    }

    DisposableEffect(context) {
        val owner = context as? LifecycleOwner
        val observer = LifecycleEventObserver { _, event ->
            if (event == Lifecycle.Event.ON_RESUME) {
                notificationsAllowed = areSystemNotificationsAllowed(context)
            }
        }
        owner?.lifecycle?.addObserver(observer)
        onDispose { owner?.lifecycle?.removeObserver(observer) }
    }

    fun openUrl(url: String) {
        runCatching { context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) }
    }

    fun acceptAndEnable() {
        app.session.getuiPrivacyConsentAccepted = true
        enabled = true
        showConsent = false
        notificationsAllowed = areSystemNotificationsAllowed(context)
        statusMessage = if (notificationsAllowed) {
            app.getuiPushSdkInitializer.initializeIfAllowed(context)
            PushManager.getInstance().getClientid(context)?.takeIf(String::isNotBlank)?.let { cid ->
                app.applicationScope.launch { app.repository.onPushClientIdReceived(cid) }
            }
            "新订单提醒已开启。"
        } else {
            "已同意开启新订单提醒，请再允许系统通知。"
        }
    }

    fun disable() {
        app.session.getuiPrivacyConsentAccepted = false
        enabled = false
        app.session.pushUnregisterPending = true
        unbindPending = true
        app.getuiPushSdkInitializer.disable(context)
        statusMessage = "新订单提醒已关闭。"
    }

    fun retryUnbind() {
        scope.launch {
            val unregistered = app.repository.unregisterPushDeviceForPrivacyRevocation()
            unbindPending = !unregistered
            statusMessage = if (unregistered) {
                "服务器设备解绑完成。"
            } else {
                "服务器设备解绑失败，请检查网络后重试。"
            }
        }
    }

    val settingsIntent = remember(context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                .putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName)
        } else {
            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${context.packageName}"))
        }
    }

    CardBox {
        Column(
            Modifier.fillMaxWidth().padding(14.dp),
            verticalArrangement = Arrangement.spacedBy(10.dp),
        ) {
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text("新订单系统通知", fontSize = T.body, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                    Text(
                        if (enabled) "订单创建后，商家端会尝试发送系统通知。"
                        else "开启后，即使商家端在后台也可接收新订单提醒。",
                        fontSize = T.caption,
                        color = C.TextSecondary,
                    )
                }
                Switch(
                    checked = enabled,
                    onCheckedChange = { turnOn ->
                        if (turnOn) showConsent = true else disable()
                    },
                    enabled = !unbindPending && (enabled || (hasPolicy && hasAppId)),
                    colors = SwitchDefaults.colors(
                        checkedThumbColor = Color.White,
                        checkedTrackColor = C.Brand700,
                    ),
                )
            }

            if (!hasPolicy) {
                NoticeBar(
                    "商家端隐私政策 HTTPS 链接尚未配置，暂时不能开启推送。",
                    ChipTone.Warning,
                )
            } else if (!hasAppId) {
                NoticeBar("个推 App ID 尚未配置，暂时不能开启推送。", ChipTone.Warning)
            }

            if (enabled && !notificationsAllowed) {
                NoticeBar("手机系统通知当前未开放。请在系统设置中允许商家端发送通知。", ChipTone.Warning)
                SecondaryButton(
                    text = "打开系统通知设置",
                    onClick = { runCatching { context.startActivity(settingsIntent) } },
                    modifier = Modifier.fillMaxWidth(),
                )
            }

            statusMessage?.let { Text(it, fontSize = T.mini, color = C.TextMuted) }
            if (unbindPending) {
                TextButton(onClick = ::retryUnbind) {
                    Text("重试解除服务器绑定", color = C.Brand700)
                }
            }
            Text(
                "通知只提示有新订单，不包含顾客姓名、电话、地址或订单详情。",
                fontSize = T.mini,
                color = C.TextMuted,
            )
        }
    }

    if (showConsent) {
        AlertDialog(
            onDismissRequest = { showConsent = false },
            title = { Text("开启新订单提醒") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        "开启后，个推 SDK 会处理必要的设备标识、设备信息和网络信息，生成推送标识并将新订单提醒投递到本机。你可以随时关闭此功能。",
                        fontSize = T.caption,
                        color = C.TextSecondary,
                    )
                    TextButton(onClick = { openUrl(policyUrl) }) {
                        Text("阅读《商家端隐私政策》", color = C.Brand700)
                    }
                    TextButton(onClick = { openUrl(GETUI_PRIVACY_POLICY_URL) }) {
                        Text("查看个推隐私政策", color = C.Brand700)
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = ::acceptAndEnable) {
                    Text("同意并开启", color = C.Brand700)
                }
            },
            dismissButton = {
                TextButton(onClick = { showConsent = false }) {
                    Text("暂不开启", color = C.TextSecondary)
                }
            },
        )
    }
}

private fun areSystemNotificationsAllowed(context: Context): Boolean {
    val runtimePermissionGranted = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) ==
        PackageManager.PERMISSION_GRANTED
    return runtimePermissionGranted && NotificationManagerCompat.from(context).areNotificationsEnabled()
}

private const val GETUI_PRIVACY_POLICY_URL = "https://docs.getui.com/privacy/"
