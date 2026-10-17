package com.chunlian.merchant.core.push

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.chunlian.merchant.BuildConfig
import com.chunlian.merchant.core.network.SessionStore
import com.igexin.sdk.PushManager
import java.net.URI

/**
 * Starts Getui only after the app privacy policy is configured, the user explicitly
 * enables order alerts, and Android notifications are allowed.
 */
class GetuiPushSdkInitializer(private val session: SessionStore) {
    @Volatile private var initialized = false
    @Volatile private var disabledByUser = false

    @Synchronized
    fun initializeIfAllowed(context: Context): Boolean {
        val appContext = context.applicationContext
        val notificationPermissionGranted =
            (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
                ContextCompat.checkSelfPermission(appContext, Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED) &&
                NotificationManagerCompat.from(appContext).areNotificationsEnabled()
        if (!shouldInitializeGetui(
                BuildConfig.GETUI_APP_ID,
                BuildConfig.MERCHANT_PRIVACY_POLICY_URL,
                session.getuiPrivacyConsentAccepted,
                notificationPermissionGranted,
            )
        ) return false
        if (initialized) {
            if (!disabledByUser) return false
            PushManager.getInstance().turnOnPush(appContext)
            disabledByUser = false
            return true
        }

        PushManager.getInstance().preInit(appContext)
        PushManager.getInstance().initialize(appContext)
        PushManager.getInstance().setNotificationIcon(appContext, "push_small", "push_small")
        initialized = true
        return true
    }

    @Synchronized
    fun disable(context: Context) {
        if (!initialized) return
        PushManager.getInstance().turnOffPush(context.applicationContext)
        disabledByUser = true
    }
}

internal fun shouldInitializeGetui(
    appId: String,
    privacyPolicyUrl: String,
    explicitPrivacyConsent: Boolean,
    notificationsEnabled: Boolean,
): Boolean = appId.isNotBlank() && hasValidPrivacyPolicyUrl(privacyPolicyUrl) &&
    explicitPrivacyConsent && notificationsEnabled

internal fun hasValidPrivacyPolicyUrl(value: String): Boolean = runCatching {
    val uri = URI(value.trim())
    uri.scheme?.equals("https", ignoreCase = true) == true && !uri.host.isNullOrBlank()
}.getOrDefault(false)
