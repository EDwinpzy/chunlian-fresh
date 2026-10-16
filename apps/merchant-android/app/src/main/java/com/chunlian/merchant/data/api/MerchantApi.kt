package com.chunlian.merchant.data.api

import com.chunlian.merchant.core.network.ApiEnvelope
import com.chunlian.merchant.data.model.Category
import com.chunlian.merchant.data.model.CategoryCreateRequest
import com.chunlian.merchant.data.model.CategoryMutationResult
import com.chunlian.merchant.data.model.CategoryPatch
import com.chunlian.merchant.data.model.CodReceiptRequest
import com.chunlian.merchant.data.model.DashboardData
import com.chunlian.merchant.data.model.DeliverRequest
import com.chunlian.merchant.data.model.DispatchRequest
import com.chunlian.merchant.data.model.Goods
import com.chunlian.merchant.data.model.GoodsCreateRequest
import com.chunlian.merchant.data.model.GoodsCreated
import com.chunlian.merchant.data.model.GoodsPage
import com.chunlian.merchant.data.model.GoodsUpdateRequest
import com.chunlian.merchant.data.model.LoginRequest
import com.chunlian.merchant.data.model.LoginResult
import com.chunlian.merchant.data.model.OpenSessionRequest
import com.chunlian.merchant.data.model.LedgerPage
import com.chunlian.merchant.data.model.ManualMoneyActionResult
import com.chunlian.merchant.data.model.ManualRefundRequest
import com.chunlian.merchant.data.model.Notifications
import com.chunlian.merchant.data.model.Order
import com.chunlian.merchant.data.model.OrderPage
import com.chunlian.merchant.data.model.Receivable
import com.chunlian.merchant.data.model.RevisionHistory
import com.chunlian.merchant.data.model.QrReceiptRequest
import com.chunlian.merchant.data.model.ShelvesRequest
import com.chunlian.merchant.data.model.SkuUpdateRequest
import com.chunlian.merchant.data.model.StatsData
import com.chunlian.merchant.data.model.StorePatch
import com.chunlian.merchant.data.model.StoreSetting
import okhttp3.MultipartBody
import retrofit2.http.Body
import retrofit2.http.DELETE
import retrofit2.http.GET
import retrofit2.http.HTTP
import retrofit2.http.Header
import retrofit2.http.Multipart
import retrofit2.http.POST
import retrofit2.http.Part
import retrofit2.http.Path
import retrofit2.http.Query

/**
 * 商家端 API（对应 ShopXO 插件 `api/Merchant.php` 的 dispatch 分支）。
 *
 * 【契约提醒】返回的 `data` 与后端一一对应，**不在这里做字段改名**。
 * 后端金额一律 `*_fen` 整数，前端统一用 `fen2yuan()` 展示。
 *
 * 【写操作必须带 Idempotency-Key】
 * 服务端对写端点强制校验 UUID v4；缺失会直接 422。
 * 因此每个写方法都显式声明该 header，让「忘记带」在编译期就不可能发生。
 */
interface MerchantApi {

    // ---------------- 认证 ----------------
    @POST("merchant/auth/login")
    suspend fun login(@Body body: LoginRequest): ApiEnvelope<LoginResult>

    @POST("merchant/auth/open-session")
    suspend fun openSession(@Body body: OpenSessionRequest): ApiEnvelope<LoginResult>

    @POST("merchant/auth/logout")
    suspend fun logout(): ApiEnvelope<Unit>

    // ---------------- 工作台 ----------------
    @GET("merchant/dashboard")
    suspend fun dashboard(): ApiEnvelope<DashboardData>

    // ---------------- 订单 ----------------
    @GET("merchant/orders")
    suspend fun orders(
        @Query("status") status: String? = null,
        @Query("keyword") keyword: String? = null,
        @Query("sort") sort: String = "DEFAULT",
        @Query("page") page: Int = 1,
        @Query("page_size") pageSize: Int = 20,
    ): ApiEnvelope<OrderPage>

    @GET("merchant/orders/{id}")
    suspend fun orderDetail(@Path("id") id: String): ApiEnvelope<Order>

    /** 开始配送（后端动作名是 deliver，语义是「出发」） */
    @POST("merchant/orders/{id}/deliver")
    suspend fun dispatch(
        @Path("id") id: String,
        @Body body: DispatchRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<Order>

    /** 确认送达（COD 可同时登记收款） */
    @POST("merchant/orders/{id}/confirm-delivery")
    suspend fun confirmDelivery(
        @Path("id") id: String,
        @Body body: DeliverRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<Order>

    /** 送达后补记 COD 收款（纠错入口） */
    @POST("merchant/orders/{id}/cod-receipt")
    suspend fun codReceipt(
        @Path("id") id: String,
        @Body body: CodReceiptRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<Order>

    /** 已核实线下二维码到账后登记预付或待补款。 */
    @POST("merchant/orders/{id}/qr-receipt")
    suspend fun confirmQrReceipt(
        @Path("id") id: String,
        @Body body: QrReceiptRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<ManualMoneyActionResult>

    /** 线下实际退款完成后登记审计流水并完成对应订单修订。 */
    @POST("merchant/orders/{id}/manual-refund")
    suspend fun manualRefund(
        @Path("id") id: String,
        @Body body: ManualRefundRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<ManualMoneyActionResult>

    @GET("merchant/orders/{id}/revisions")
    suspend fun revisions(@Path("id") id: String): ApiEnvelope<RevisionHistory>

    // ---------------- 商品 ----------------
    @GET("merchant/goods/categories")
    suspend fun categories(): ApiEnvelope<List<Category>>

    @POST("merchant/goods/categories")
    suspend fun createCategory(
        @Body body: CategoryCreateRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<Category>

    @HTTP(method = "PATCH", path = "merchant/goods/categories/{id}", hasBody = true)
    suspend fun updateCategory(
        @Path("id") id: String,
        @Body body: CategoryPatch,
    ): ApiEnvelope<Category>

    @DELETE("merchant/goods/categories/{id}")
    suspend fun deleteCategory(@Path("id") id: String): ApiEnvelope<CategoryMutationResult>

    @GET("merchant/goods")
    suspend fun goods(
        @Query("keyword") keyword: String? = null,
        @Query("on_sale_only") onSaleOnly: Boolean? = null,
        @Query("page") page: Int = 1,
        @Query("page_size") pageSize: Int = 20,
    ): ApiEnvelope<GoodsPage>

    @GET("merchant/goods/{id}")
    suspend fun goodsDetail(@Path("id") id: String): ApiEnvelope<Goods>

    @POST("merchant/goods")
    suspend fun createGoods(
        @Body body: GoodsCreateRequest,
        @Header("Idempotency-Key") idempotencyKey: String,
    ): ApiEnvelope<GoodsCreated>

    /** Retrofit 没有 @PATCH，必须用 @HTTP 显式声明 */
    @HTTP(method = "PATCH", path = "merchant/goods/{id}", hasBody = true)
    suspend fun updateGoods(
        @Path("id") id: String,
        @Body body: GoodsUpdateRequest,
    ): ApiEnvelope<GoodsCreated>

    @POST("merchant/goods/{id}/shelves")
    suspend fun setShelves(
        @Path("id") id: String,
        @Body body: ShelvesRequest,
    ): ApiEnvelope<Goods>

    @HTTP(method = "PATCH", path = "merchant/goods/{id}/sku/{skuId}", hasBody = true)
    suspend fun updateSku(
        @Path("id") id: String,
        @Path("skuId") skuId: String,
        @Body body: SkuUpdateRequest,
    ): ApiEnvelope<Goods>

    @DELETE("merchant/goods/{id}")
    suspend fun archiveGoods(@Path("id") id: String): ApiEnvelope<GoodsCreated>

    @Multipart
    @POST("merchant/uploads")
    suspend fun uploadImage(@Part file: MultipartBody.Part): ApiEnvelope<UploadResult>

    // ---------------- 店铺 / 统计 ----------------
    @GET("merchant/store")
    suspend fun store(): ApiEnvelope<StoreSetting>

    @HTTP(method = "PATCH", path = "merchant/store", hasBody = true)
    suspend fun updateStore(@Body body: StorePatch): ApiEnvelope<StoreSetting>

    @GET("merchant/stats")
    suspend fun stats(
        @Query("from") from: String? = null,
        @Query("to") to: String? = null,
    ): ApiEnvelope<StatsData>

    @GET("merchant/ledger")
    suspend fun ledger(
        @Query("page") page: Int = 1,
        @Query("page_size") pageSize: Int = 20,
    ): ApiEnvelope<LedgerPage>

    @GET("merchant/receivables")
    suspend fun receivables(): ApiEnvelope<List<Receivable>>

    @GET("merchant/notifications")
    suspend fun notifications(@Query("limit") limit: Int = 50): ApiEnvelope<Notifications>

    @POST("merchant/notifications/read")
    suspend fun markNotificationsRead(): ApiEnvelope<Unit>
}

@kotlinx.serialization.Serializable
data class UploadResult(
    val path: String = "",
    val url: String = "",
    val width: Int = 0,
    val height: Int = 0,
    val bytes: Long = 0,
)
