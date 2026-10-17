package com.chunlian.merchant.data.api

import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.data.model.PushDeviceRequest
import kotlinx.coroutines.runBlocking
import okhttp3.OkHttpClient
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.mockwebserver.MockResponse
import okhttp3.mockwebserver.MockWebServer
import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import com.jakewharton.retrofit2.converter.kotlinx.serialization.asConverterFactory
import retrofit2.Retrofit

class MerchantPushApiTest {
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
    fun registrationPostsTheRequiredPushDeviceFields() = runBlocking {
        server.enqueue(okResponse())
        api().registerPushDevice(PushDeviceRequest("public-app-id", "cid-123", "install-456"))

        val request = server.takeRequest()
        assertEquals("POST", request.method)
        assertEquals("/api/v1/merchant/push-devices", request.path)
        assertEquals(
            """{"app_id":"public-app-id","cid":"cid-123","installation_id":"install-456"}""",
            request.body.readUtf8(),
        )
    }

    @Test
    fun unregisterDeletesByInstallationId() = runBlocking {
        server.enqueue(okResponse())
        api().unregisterPushDevice("install-456")

        val request = server.takeRequest()
        assertEquals("DELETE", request.method)
        assertEquals("/api/v1/merchant/push-devices/install-456", request.path)
    }

    private fun okResponse() = MockResponse()
        .addHeader("Content-Type", "application/json")
        .setBody("""{"code":"OK","data":null}""")

    private fun api(): MerchantApi = Retrofit.Builder()
        .baseUrl(server.url("/api/v1/"))
        .client(OkHttpClient.Builder().build())
        .addConverterFactory(ApiClient.json.asConverterFactory("application/json".toMediaType()))
        .build()
        .create(MerchantApi::class.java)
}
