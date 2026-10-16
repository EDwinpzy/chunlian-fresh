package com.chunlian.merchant.core.network

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import java.text.ParsePosition
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

/**
 * 商家会话存储。
 *
 * 【为什么用 EncryptedSharedPreferences 而不是普通 SharedPreferences】
 * 这里存的是**商家管理员会话 token**，有效期 30 天。
 * 普通 SharedPreferences 是明文 XML，root 过的手机或有备份权限的
 * 应用可以直接读走，然后以店主身份调用「确认送达并收款」这类
 * 涉及真实资金的接口。密钥由 Android Keystore 持有，无法被导出。
 *
 * 【为什么不用 DataStore】
 * DataStore 目前没有官方加密实现；本项目只有一个 token 和几个
 * 非敏感偏好，EncryptedSharedPreferences 已经够用，
 * 再引入一个加密包装层只会增加出错面。
 */
class SessionStore private constructor(private val prefs: SharedPreferences) {

    constructor(context: Context) : this(createSessionPreferences(
        createEncrypted = {
            val masterKey = MasterKey.Builder(context)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()

            EncryptedSharedPreferences.create(
                context,
                "chunlian_merchant_session",
                masterKey,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        },
        createPlain = {
            // 极少数设备上 Keystore 不可用时保留降级偏好，但公开管理会话
            // 在 AuthenticationGate 中会拒绝写入普通存储。
            context.getSharedPreferences("chunlian_merchant_session_plain", Context.MODE_PRIVATE)
        },
    ))

    /** Keystore 是否可用（不可用时设置页要给出提示） */
    val isEncrypted: Boolean
        get() = usesEncryptedPreferences(prefs)

    var token: String
        get() = prefs.getString(KEY_TOKEN, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_TOKEN, value).apply()

    var shopName: String
        get() = prefs.getString(KEY_SHOP_NAME, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_SHOP_NAME, value).apply()

    var merchantId: String
        get() = prefs.getString(KEY_MERCHANT_ID, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_MERCHANT_ID, value).apply()

    var expiresAt: String
        get() = prefs.getString(KEY_EXPIRES_AT, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_EXPIRES_AT, value).apply()

    /** 每次安装生成的稳定标识；随 token 一起保存在加密偏好中。 */
    fun getOrCreateDeviceId(): String {
        val current = prefs.getString(KEY_DEVICE_ID, "").orEmpty()
        if (current.isNotBlank()) return current
        return UUID.randomUUID().toString().also { prefs.edit().putString(KEY_DEVICE_ID, it).apply() }
    }

    /** 记住上次登录的账号，减少店主每天输账号的次数（不存密码） */
    var lastUsername: String
        get() = prefs.getString(KEY_LAST_USER, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_LAST_USER, value).apply()

    val isLoggedIn: Boolean get() = hasValidSession()

    fun hasValidSession(nowMillis: Long = System.currentTimeMillis()): Boolean =
        token.isNotBlank() && parseSessionExpiryMillis(expiresAt)?.let { it > nowMillis } == true

    fun clear() {
        // 只清会话，保留 lastUsername
        prefs.edit()
            .remove(KEY_TOKEN)
            .remove(KEY_EXPIRES_AT)
            .remove(KEY_MERCHANT_ID)
            .apply()
    }

    internal companion object {
        fun fromPreferences(prefs: SharedPreferences): SessionStore = SessionStore(prefs)

        const val KEY_TOKEN = "token"
        const val KEY_SHOP_NAME = "shop_name"
        const val KEY_MERCHANT_ID = "merchant_id"
        const val KEY_EXPIRES_AT = "expires_at"
        const val KEY_LAST_USER = "last_username"
        const val KEY_DEVICE_ID = "device_id"
    }
}

/** SQL DATETIME 会话到期时间按服务端 Asia/Shanghai 时区解释。 */
internal fun parseSessionExpiryMillis(value: String): Long? {
    val raw = value.trim()
    if (raw.isBlank()) return null

    val formats = listOf(
        "yyyy-MM-dd'T'HH:mm:ss.SSSXXX" to "UTC",
        "yyyy-MM-dd'T'HH:mm:ssXXX" to "UTC",
        "yyyy-MM-dd'T'HH:mm:ss'Z'" to "UTC",
        "yyyy-MM-dd HH:mm:ss" to "Asia/Shanghai",
        "yyyy-MM-dd'T'HH:mm:ss" to "Asia/Shanghai",
    )
    return formats.firstNotNullOfOrNull { (pattern, zone) ->
        runCatching {
            val formatter = SimpleDateFormat(pattern, Locale.US).apply {
                isLenient = false
                timeZone = TimeZone.getTimeZone(zone)
            }
            val position = ParsePosition(0)
            formatter.parse(raw, position)?.takeIf { position.index == raw.length }?.time
        }.getOrNull()
    }
}

internal fun createSessionPreferences(
    createEncrypted: () -> SharedPreferences,
    createPlain: () -> SharedPreferences,
): SharedPreferences = runCatching { createEncrypted() }.getOrElse { createPlain() }

internal fun usesEncryptedPreferences(prefs: SharedPreferences): Boolean =
    prefs is EncryptedSharedPreferences
