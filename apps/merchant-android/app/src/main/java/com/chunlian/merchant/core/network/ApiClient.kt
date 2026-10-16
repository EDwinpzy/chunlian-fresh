package com.chunlian.merchant.core.network

import com.chunlian.merchant.BuildConfig
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.logging.HttpLoggingInterceptor
import retrofit2.Retrofit
import java.util.concurrent.TimeUnit
import java.util.UUID

/**
 * 网络层装配。
 *
 * 【超时为什么分两档】
 * 普通读接口 15s 足够；但「确认送达并收款」这类写操作要等
 * 数据库行锁 + 流水写入，给到 30s，避免店主在店里信号差时
 * 看到超时提示而重复点击（重复点击由幂等键兜底，但提示超时仍会吓到他）。
 */
object ApiClient {

    val json: Json = Json {
        // 服务端会新增字段（例如以后给订单加发票信息），
        // 客户端不该因为不认识就整体解析失败
        ignoreUnknownKeys = true
        // 服务端显式返回的 null 要能落到默认值上
        explicitNulls = false
        coerceInputValues = true
        isLenient = true
        encodeDefaults = true
    }

    /** 当前会话 token；由 SessionStore 注入，避免网络层依赖 Android Context */
    @Volatile
    var tokenProvider: () -> String = { "" }

    /** 会话失效时的回调（清会话 + 跳登录） */
    @Volatile
    var onUnauthorized: () -> Unit = {}

    fun baseUrl(): String = BuildConfig.API_BASE_URL.trimEnd('/') + "/api/v1/"

    fun build(): Retrofit {
        val logging = HttpLoggingInterceptor().apply {
            // 只记基本信息：Authorization 头与订单内容不该出现在日志里
            level = if (BuildConfig.DEBUG) HttpLoggingInterceptor.Level.BASIC
            else HttpLoggingInterceptor.Level.NONE
        }

        val client = OkHttpClient.Builder()
            .connectTimeout(10, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .addInterceptor { chain ->
                val builder = chain.request().newBuilder()
                    .header("Accept", "application/json")
                    .header("X-Client", "merchant-android")

                val token = tokenProvider()
                if (token.isNotBlank()) {
                    builder.header("Authorization", "Bearer $token")
                }

                chain.proceed(builder.build())
            }
            .addInterceptor(logging)
            .build()

        return Retrofit.Builder()
            .baseUrl(baseUrl())
            .client(client)
            .addConverterFactory(json.asConverterFactory("application/json".toMediaType()))
            .build()
    }

    /**
     * 生成幂等键（UUID v4）。
     *
     * 【为什么必须在客户端生成，而且要"一次操作一个"】
     * 服务端按 `actor + endpoint + key` 去重，重复请求返回首次结果。
     * 如果每次重试都新生成 key，等于完全没有幂等 ——
     * 店主在信号差的地方点两次「确认送达」，就会记两笔收款。
     * 正确用法：在**点击那一刻**生成，重试时复用同一个。
     */
    fun idempotencyKey(): String = UUID.randomUUID().toString()
}
