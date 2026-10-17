package com.chunlian.customer.data

import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.Header
import retrofit2.http.POST
import retrofit2.http.Path
import retrofit2.http.Query

@kotlinx.serialization.Serializable data class LoginRequest(val phone: String, val password: String)
@kotlinx.serialization.Serializable data class RegisterRequest(val phone: String, val password: String, val nickname: String = "")
@kotlinx.serialization.Serializable data class ProfileRequest(val nickname: String? = null, val avatar: String? = null)
@kotlinx.serialization.Serializable data class PushDeviceRegistrationRequest(
    @kotlinx.serialization.SerialName("app_id") val appId: String,
    val cid: String,
    @kotlinx.serialization.SerialName("installation_id") val installationId: String,
)
@kotlinx.serialization.Serializable data class PreviewRequest(
    val items: List<OrderItemRequest>, @kotlinx.serialization.SerialName("delivery_mode") val deliveryMode: String,
    @kotlinx.serialization.SerialName("expected_at") val expectedAt: String? = null,
)

interface CustomerApi {
    @GET("customer/shop") suspend fun shop(): ApiEnvelope<ShopSetting>
    @GET("customer/catalog/categories") suspend fun categories(): ApiEnvelope<List<Category>>
    @GET("customer/catalog/goods") suspend fun goods(
        @Query("category_id") categoryId: String? = null,
        @Query("keyword") keyword: String? = null,
        @Query("sort") sort: String? = null,
        @Query("page") page: Int = 1,
        @Query("page_size") pageSize: Int = 30,
        @Query("ids") ids: String? = null,
    ): ApiEnvelope<PageData<GoodsSummary>>
    @GET("customer/catalog/goods/{id}") suspend fun goodsDetail(@Path("id") id: String): ApiEnvelope<GoodsDetail>

    @POST("customer/auth/register") suspend fun register(@Body body: RegisterRequest): ApiEnvelope<LoginResult>
    @POST("customer/auth/login") suspend fun login(@Body body: LoginRequest): ApiEnvelope<LoginResult>
    @POST("customer/auth/logout") suspend fun logout(): ApiEnvelope<Unit>
    @GET("customer/auth/me") suspend fun me(): ApiEnvelope<AuthUser>
    @retrofit2.http.PATCH("customer/auth/profile") suspend fun updateProfile(@Body body: ProfileRequest): ApiEnvelope<AuthUser>

    @POST("customer/push-devices") suspend fun registerPushDevice(
        @Body body: PushDeviceRegistrationRequest,
    ): ApiEnvelope<kotlinx.serialization.json.JsonElement>
    @DELETE("customer/push-devices/{installation_id}") suspend fun unregisterPushDevice(
        @Path("installation_id") installationId: String,
    ): ApiEnvelope<kotlinx.serialization.json.JsonElement>

    @GET("customer/addresses") suspend fun addresses(): ApiEnvelope<List<Address>>
    @POST("customer/addresses") suspend fun saveAddress(@Body body: AddressSaveRequest): ApiEnvelope<List<Address>>
    @POST("customer/addresses/{id}/default") suspend fun setDefaultAddress(@Path("id") id: String): ApiEnvelope<List<Address>>
    @DELETE("customer/addresses/{id}") suspend fun deleteAddress(@Path("id") id: String): ApiEnvelope<List<Address>>

    @POST("customer/orders/preview") suspend fun preview(@Body body: PreviewRequest): ApiEnvelope<CheckoutPreview>
    @GET("customer/orders") suspend fun orders(
        @Query("status") status: String? = null, @Query("page") page: Int = 1, @Query("page_size") pageSize: Int = 30,
        @Query("keyword") keyword: String? = null,
    ): ApiEnvelope<PageData<Order>>
    @POST("customer/orders") suspend fun createOrder(
        @Body body: CreateOrderRequest, @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<CreateOrderResult>
    @GET("customer/orders/{id}") suspend fun orderDetail(@Path("id") id: String): ApiEnvelope<Order>
    @POST("customer/orders/{id}/revision/preview") suspend fun revisionPreview(@Path("id") id: String, @Body body: ItemsRequest): ApiEnvelope<RevisionPreview>
    @POST("customer/orders/{id}/revision") suspend fun revisionSubmit(
        @Path("id") id: String, @Body body: ItemsRequest, @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<RevisionSubmitResult>
    @POST("customer/orders/{id}/info") suspend fun updateOrderInfo(@Path("id") id: String, @Body body: OrderInfoRequest): ApiEnvelope<Order>
    @POST("customer/orders/{id}/cancel") suspend fun cancelOrder(
        @Path("id") id: String, @Body body: CancelOrderRequest, @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<Order>
    @POST("customer/orders/{id}/receive") suspend fun receiveOrder(
        @Path("id") id: String, @Body body: ReceiveOrderRequest, @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<Order>
}

@kotlinx.serialization.Serializable data class RevisionPreview(
    @kotlinx.serialization.SerialName("order_version") val orderVersion: Int = 0,
    @kotlinx.serialization.SerialName("old_total_fen") val oldTotalFen: Long = 0,
    @kotlinx.serialization.SerialName("new_total_fen") val newTotalFen: Long = 0,
    @kotlinx.serialization.SerialName("delta_fen") val deltaFen: Long = 0,
    @kotlinx.serialization.SerialName("delta_type") val deltaType: String = "NONE",
    val warnings: List<String> = emptyList(),
    @kotlinx.serialization.SerialName("customer_message") val customerMessage: String = "",
)
@kotlinx.serialization.Serializable data class RevisionSubmitResult(
    @kotlinx.serialization.SerialName("revision_id") val revisionId: Int = 0,
    val changed: Boolean = false,
    @kotlinx.serialization.SerialName("delta_fen") val deltaFen: Long = 0,
    @kotlinx.serialization.SerialName("revision_state") val revisionState: String = "NONE",
    @kotlinx.serialization.SerialName("applied_immediately") val appliedImmediately: Boolean = false,
    @kotlinx.serialization.SerialName("requires_topup") val requiresTopup: Boolean = false,
    val order: Order? = null,
)
