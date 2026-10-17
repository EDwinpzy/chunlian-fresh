package com.chunlian.customer.data

import android.content.Context
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.chunlian.customer.BuildConfig
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import java.util.UUID
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

class CustomerSession(context: Context) {
    private val json = Json { ignoreUnknownKeys = true }
    private val preferences by lazy {
        val masterKey = MasterKey.Builder(context.applicationContext)
            .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
            .build()
        EncryptedSharedPreferences.create(
            context.applicationContext,
            "customer-session",
            masterKey,
            EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
            EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
        )
    }

    fun token(): String = runCatching { preferences.getString(KEY_TOKEN, "").orEmpty() }.getOrDefault("")
    fun saveToken(token: String) { preferences.edit().putString(KEY_TOKEN, token).apply() }
    fun pushInstallationId(): String = synchronized(this) {
        preferences.getString(KEY_PUSH_INSTALLATION_ID, null)?.takeIf(String::isNotBlank)
            ?: UUID.randomUUID().toString().also { preferences.edit().putString(KEY_PUSH_INSTALLATION_ID, it).apply() }
    }
    fun clear() {
        val editor = preferences.edit().remove(KEY_TOKEN)
        preferences.all.keys.filter { it.startsWith(IDEMPOTENCY_PREFIX) }.forEach(editor::remove)
        editor.apply()
    }
    fun cart(): List<CartItem> = runCatching {
        json.decodeFromString<List<CartItem>>(preferences.getString(KEY_CART, "[]") ?: "[]")
    }.getOrDefault(emptyList())
    fun saveCart(items: List<CartItem>) { preferences.edit().putString(KEY_CART, json.encodeToString(items)).apply() }

    @Synchronized
    fun idempotencyKey(operation: String, payload: String): String {
        val scope = operation.replace(Regex("[^A-Za-z0-9_.:-]"), "_")
        val payloadHash = MessageDigest.getInstance("SHA-256")
            .digest(payload.toByteArray(Charsets.UTF_8))
            .joinToString("") { "%02x".format(it) }
        val fingerprintKey = "$IDEMPOTENCY_PREFIX$scope:fingerprint"
        val valueKey = "$IDEMPOTENCY_PREFIX$scope:key"
        val prefs = preferences
        if (prefs.getString(fingerprintKey, null) == payloadHash) {
            prefs.getString(valueKey, null)?.takeIf(String::isNotBlank)?.let { return it }
        }
        val key = UUID.randomUUID().toString()
        prefs.edit().putString(fingerprintKey, payloadHash).putString(valueKey, key).apply()
        return key
    }

    fun clearIdempotencyKey(operation: String) {
        val scope = operation.replace(Regex("[^A-Za-z0-9_.:-]"), "_")
        preferences.edit()
            .remove("$IDEMPOTENCY_PREFIX$scope:fingerprint")
            .remove("$IDEMPOTENCY_PREFIX$scope:key")
            .apply()
    }

    private companion object {
        const val KEY_TOKEN = "token"
        const val KEY_CART = "cart"
        const val KEY_PUSH_INSTALLATION_ID = "push_installation_id"
        const val IDEMPOTENCY_PREFIX = "idem:"
    }
}

class CustomerApiException(message: String) : Exception(message)

class CustomerRepository(context: Context) {
    val session = CustomerSession(context)
    val api: CustomerApi
    val secureTransport: Boolean = BuildConfig.API_BASE_URL.startsWith("https://", ignoreCase = true)
    private val localLoopbackTestTransport: Boolean = BuildConfig.DEBUG &&
        BuildConfig.ALLOW_INSECURE_LOOPBACK_AUTH &&
        runCatching { java.net.URI(BuildConfig.API_BASE_URL).host in setOf("127.0.0.1", "localhost", "::1") }.getOrDefault(false)
    val identityTransportAllowed: Boolean = secureTransport || localLoopbackTestTransport
    val loopbackTestTransport: Boolean = localLoopbackTestTransport

    init {
        val json = Json {
            ignoreUnknownKeys = true
            explicitNulls = false
            coerceInputValues = true
            isLenient = true
            encodeDefaults = true
        }
        val logging = HttpLoggingInterceptor().apply {
            level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BASIC else HttpLoggingInterceptor.Level.NONE
        }
        val client = OkHttpClient.Builder()
            .connectTimeout(12, TimeUnit.SECONDS)
            .readTimeout(25, TimeUnit.SECONDS)
            .writeTimeout(25, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val request = chain.request().newBuilder()
                    .header("Accept", "application/json")
                    .header("X-Client", "customer-android")
                // 不允许通过明文 HTTP 发送顾客身份凭据。
                if (chain.request().url.isHttps || localLoopbackTestTransport) {
                    session.token().takeIf(String::isNotBlank)?.let { request.header("Authorization", "Bearer $it") }
                }
                chain.proceed(request.build())
            }
            .addInterceptor(logging)
            .build()
        val baseUrl = BuildConfig.API_BASE_URL.trimEnd('/') + "/api/v1/"
        api = Retrofit.Builder()
            .baseUrl(if (baseUrl.endsWith('/')) baseUrl else "$baseUrl/")
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
            .create(CustomerApi::class.java)
    }

    suspend fun <T : Any> unwrap(call: suspend () -> ApiEnvelope<T>): T {
        val result = try {
            call()
        } catch (error: retrofit2.HttpException) {
            throw CustomerApiException("服务暂时不可用（${error.code()}），请稍后重试")
        } catch (error: java.io.IOException) {
            throw CustomerApiException("网络连接失败，请检查网络后重试")
        }
        if (result.code != "OK") throw CustomerApiException(result.message?.takeIf(String::isNotBlank) ?: "操作失败，请重试")
        return result.data ?: throw CustomerApiException(result.message?.takeIf(String::isNotBlank) ?: "服务端没有返回数据")
    }

    fun idempotencyKey(operation: String, payload: String): String = session.idempotencyKey(operation, payload)
    fun clearIdempotencyKey(operation: String) = session.clearIdempotencyKey(operation)
}
