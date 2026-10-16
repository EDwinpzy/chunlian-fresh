package com.chunlian.merchant.data.repository

import com.chunlian.merchant.core.network.ApiException
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.core.network.SessionStore
import com.chunlian.merchant.core.network.memorySharedPreferences
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.MediaType.Companion.toMediaType
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.fail
import org.junit.Before
import org.junit.Test
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import retrofit2.Retrofit

class OpenSessionRepositoryTest {
    private lateinit var server: MockWebServer

    @Before
    fun startServer() {
        server = MockWebServer()
        server.start()
    }

    @After
    fun stopServer() {
        server.shutdown()
    }

    @Test
    fun openSessionPostsDeviceIdAndPersistsTheSharedLoginResult() = runBlocking {
        server.enqueue(
            MockResponse()
                .addHeader("Content-Type", "application/json")
                .setBody(
                    """{"code":"OK","data":{"token":"open-token","expires_at":"2999-10-15 12:00:00","merchant_id":"17","shop_name":"春连鲜选"}}""",
                ),
        )
        val store = SessionStore.fromPreferences(memorySharedPreferences())
        val repository = MerchantRepository(store, retrofit())

        val result = repository.openSession("install-device-id")
        val request = server.takeRequest()

        assertEquals("POST", request.method)
        assertEquals("/api/v1/merchant/auth/open-session", request.path)
        assertEquals("""{"device_id":"install-device-id"}""", request.body.readUtf8())
        assertNull(request.getHeader("Authorization"))
        assertEquals("open-token", result.token)
        assertEquals("open-token", store.token)
        assertEquals("17", store.merchantId)
        assertEquals("春连鲜选", store.shopName)
        assertEquals("2999-10-15 12:00:00", store.expiresAt)
        assertEquals("", store.lastUsername)
    }

    @Test
    fun openSessionSurfacesDisabledServerSwitchWithoutSavingAToken() = runBlocking {
        server.enqueue(
            MockResponse()
                .addHeader("Content-Type", "application/json")
                .setBody("""{"code":"FORBIDDEN","message":"公开管理未启用","data":null}"""),
        )
        val store = SessionStore.fromPreferences(memorySharedPreferences())
        val repository = MerchantRepository(store, retrofit())

        try {
            repository.openSession("install-device-id")
            fail("Expected the server switch rejection to be surfaced")
        } catch (error: ApiException) {
            assertEquals("FORBIDDEN", error.code)
            assertEquals("公开管理未启用", error.message)
        }
        assertEquals("", store.token)
        assertEquals("", store.expiresAt)
    }

    @Test
    fun openSessionRejectsMalformedSuccessBeforePersistingIt() = runBlocking {
        server.enqueue(
            MockResponse()
                .addHeader("Content-Type", "application/json")
                .setBody(
                    """{"code":"OK","data":{"token":"","expires_at":"2999-10-15 12:00:00","merchant_id":"17","shop_name":"春连鲜选"}}""",
                ),
        )
        val store = SessionStore.fromPreferences(memorySharedPreferences())
        val repository = MerchantRepository(store, retrofit())

        try {
            repository.openSession("install-device-id")
            fail("Expected malformed session data to be rejected")
        } catch (error: com.chunlian.merchant.core.network.NetworkException) {
            assertEquals("管理服务返回的会话信息无效，请重试。", error.message)
        }
        assertEquals("", store.token)
        assertEquals("", store.expiresAt)
    }

    private fun retrofit(): Retrofit = Retrofit.Builder()
        .baseUrl(server.url("/api/v1/"))
        .client(OkHttpClient.Builder().build())
        .addConverterFactory(ApiClient.json.asConverterFactory("application/json".toMediaType()))
        .build()
}
