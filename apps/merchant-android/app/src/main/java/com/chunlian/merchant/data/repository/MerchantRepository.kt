package com.chunlian.merchant.data.repository

import com.chunlian.merchant.BuildConfig
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.core.network.ApiEnvelope
import com.chunlian.merchant.core.network.ApiException
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.core.network.SessionStore
import com.chunlian.merchant.core.network.parseSessionExpiryMillis
import com.chunlian.merchant.core.network.unwrap
import com.chunlian.merchant.data.api.MerchantApi
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
import com.chunlian.merchant.data.model.LoginResult
import com.chunlian.merchant.data.model.LoginRequest
import com.chunlian.merchant.data.model.OpenSessionRequest
import com.chunlian.merchant.data.model.LedgerPage
import com.chunlian.merchant.data.model.ManualMoneyActionResult
import com.chunlian.merchant.data.model.ManualRefundRequest
import com.chunlian.merchant.data.model.Notifications
import com.chunlian.merchant.data.model.Order
import com.chunlian.merchant.data.model.OrderPage
import com.chunlian.merchant.data.model.PushDeviceRequest
import com.chunlian.merchant.data.model.Receivable
import com.chunlian.merchant.data.model.RevisionHistory
import com.chunlian.merchant.data.model.QrReceiptRequest
import com.chunlian.merchant.data.model.SkuUpdateRequest
import com.chunlian.merchant.data.model.StatsData
import com.chunlian.merchant.data.model.StorePatch
import com.chunlian.merchant.data.model.StoreSetting
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import retrofit2.Retrofit
import java.io.File
import java.io.IOException

/**
 * 商家端仓库层。
 *
 * 【为什么所有网络调用都要过这一层】
 * 三个理由，任何一个单独看都够：
 *   1. **统一解包**：服务端返回 `{code,message,data}`，`code != OK` 时要抛
 *      带业务码的 [ApiException]；散落在 ViewModel 里写 if 判断迟早漏。
 *   2. **统一异常翻译**：`IOException` / 超时 / JSON 解析失败都要变成
 *      店主看得懂的一句话（「网络似乎开了小差」），而不是堆栈。
 *   3. **图片地址补全**：服务端商品图存的是相对路径
 *      `/static/upload/...`，安卓端必须拼上主机名才能显示。
 *
 * 所有方法都是 `suspend`，IO 调度器在这里统一切换 —— ViewModel 不需要
 * 关心线程。
 */
class MerchantRepository(
    private val session: SessionStore,
    retrofit: Retrofit = ApiClient.build(),
    private val getuiAppId: String = BuildConfig.GETUI_APP_ID,
) {

    private val api: MerchantApi = retrofit.create(MerchantApi::class.java)

    // =========================================================
    // 认证
    // =========================================================

    suspend fun login(username: String, password: String, deviceId: String): LoginResult = call {
        val result = api.login(LoginRequest(username, password, deviceId)).unwrap()
        persistSession(result)
        session.lastUsername = username
        registerPushDeviceIfAllowed()

        result
    }

    suspend fun openSession(deviceId: String): LoginResult = call {
        val result = api.openSession(OpenSessionRequest(deviceId)).unwrap()
        val expiry = parseSessionExpiryMillis(result.expiresAt)
        if (result.token.isBlank() || expiry == null || expiry <= System.currentTimeMillis()) {
            throw NetworkException("管理服务返回的会话信息无效，请重试。")
        }
        persistSession(result)
        registerPushDeviceIfAllowed()
        result
    }

    /** Stores a CID received after explicit privacy consent and binds it once a session exists. */
    suspend fun onPushClientIdReceived(cid: String) {
        if (cid.isBlank() || !session.getuiPrivacyConsentAccepted) return
        session.pushClientId = cid
        registerPushDeviceIfAllowed()
    }

    /** Revoke the current installation binding after the merchant turns off order alerts. */
    suspend fun unregisterPushDeviceForPrivacyRevocation(): Boolean {
        if (!session.hasValidSession()) {
            session.pushUnregisterPending = true
            return false
        }
        if (session.pushClientId.isBlank()) {
            session.pushUnregisterPending = false
            return true
        }

        return try {
            call { api.unregisterPushDevice(session.getOrCreateDeviceId()).unwrap() }
            session.pushClientId = ""
            session.pushUnregisterPending = false
            true
        } catch (cancelled: kotlinx.coroutines.CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            session.pushUnregisterPending = true
            false
        }
    }

    private suspend fun registerPushDeviceIfAllowed() {
        if (!session.getuiPrivacyConsentAccepted || !session.hasValidSession()) return
        val appId = getuiAppId.trim()
        val cid = session.pushClientId.trim()
        if (appId.isBlank() || cid.isBlank()) return

        try {
            api.registerPushDevice(
                PushDeviceRequest(
                    appId = appId,
                    cid = cid,
                    installationId = session.getOrCreateDeviceId(),
                ),
            ).unwrap()
        } catch (cancelled: kotlinx.coroutines.CancellationException) {
            throw cancelled
        } catch (_: Exception) {
            // Push registration is best effort and must not prevent merchant sign-in.
        }
    }

    private fun persistSession(result: LoginResult) {
        session.token = result.token
        session.shopName = result.shopName
        session.merchantId = result.merchantId
        session.expiresAt = result.expiresAt
    }

    suspend fun logout() {
        val installationId = session.getOrCreateDeviceId()
        if (session.pushClientId.isNotBlank() && session.hasValidSession()) {
            // Unbind while the bearer token is still available. A transient push API failure
            // must not prevent local sign-out.
            runCatching { api.unregisterPushDevice(installationId).unwrap() }
        }
        // 服务端登出失败也要清本地：否则店主会卡在「点了退出但还是登录着」
        runCatching { call { api.logout() } }
        session.clear()
    }

    // =========================================================
    // 工作台
    // =========================================================

    suspend fun dashboard(): DashboardData = call { api.dashboard().unwrap() }

    // =========================================================
    // 订单
    // =========================================================

    suspend fun orders(
        status: String? = null,
        keyword: String? = null,
        sort: String = "DEFAULT",
        page: Int = 1,
        pageSize: Int = 20,
    ): OrderPage = call {
        api.orders(status, keyword, sort, page, pageSize).unwrap()
    }

    suspend fun orderDetail(id: String): Order = call { api.orderDetail(id).unwrap() }

    /**
     * 开始配送。
     *
     * [idempotencyKey] 由调用方在**点击那一刻**生成并在重试时复用 ——
     * 见 [ApiClient.idempotencyKey] 的说明。
     */
    suspend fun dispatch(id: String, version: Int, idempotencyKey: String): Order = call {
        api.dispatch(id, DispatchRequest(version), idempotencyKey).unwrap()
    }

    suspend fun confirmDelivery(
        id: String,
        version: Int,
        collectedOnDelivery: Boolean,
        collectionMethod: String?,
        collectedFen: Long?,
        idempotencyKey: String,
    ): Order = call {
        api.confirmDelivery(
            id,
            DeliverRequest(version, collectedOnDelivery, collectionMethod, collectedFen),
            idempotencyKey,
        ).unwrap()
    }

    suspend fun codReceipt(
        id: String,
        version: Int,
        collectedFen: Long,
        collectionMethod: String,
        note: String?,
        idempotencyKey: String,
    ): Order = call {
        api.codReceipt(id, CodReceiptRequest(version, collectedFen, collectionMethod, note), idempotencyKey).unwrap()
    }

    suspend fun confirmQrReceipt(
        id: String,
        version: Int,
        amountFen: Long,
        idempotencyKey: String,
    ): ManualMoneyActionResult = call {
        api.confirmQrReceipt(id, QrReceiptRequest(version, amountFen), idempotencyKey).unwrap()
    }

    suspend fun manualRefund(
        id: String,
        version: Int,
        amountFen: Long,
        idempotencyKey: String,
    ): ManualMoneyActionResult = call {
        api.manualRefund(
            id,
            ManualRefundRequest(version, amountFen),
            idempotencyKey,
        ).unwrap()
    }

    suspend fun revisions(id: String): RevisionHistory = call { api.revisions(id).unwrap() }

    // =========================================================
    // 商品
    // =========================================================

    suspend fun categories(): List<Category> = call { api.categories().unwrap() }

    suspend fun createCategory(body: CategoryCreateRequest, idempotencyKey: String): Category = call {
        api.createCategory(body, idempotencyKey).unwrap()
    }

    suspend fun updateCategory(id: String, patch: CategoryPatch): Category = call {
        api.updateCategory(id, patch).unwrap()
    }

    suspend fun deleteCategory(id: String): CategoryMutationResult = call { api.deleteCategory(id).unwrap() }

    suspend fun goods(keyword: String? = null, onSaleOnly: Boolean? = null, page: Int = 1, pageSize: Int = 20): GoodsPage =
        call { api.goods(keyword, onSaleOnly, page, pageSize).unwrap() }

    suspend fun goodsDetail(id: String): Goods = call { api.goodsDetail(id).unwrap() }

    suspend fun createGoods(body: GoodsCreateRequest, idempotencyKey: String): GoodsCreated = call {
        api.createGoods(body, idempotencyKey).unwrap()
    }

    suspend fun updateGoods(id: String, body: GoodsUpdateRequest): GoodsCreated = call {
        api.updateGoods(id, body).unwrap()
    }

    suspend fun setShelves(id: String, onShelves: Boolean): Goods = call {
        api.setShelves(id, com.chunlian.merchant.data.model.ShelvesRequest(onShelves)).unwrap()
    }

    suspend fun updateSku(goodsId: String, skuId: String, priceFen: Long?, inventory: Int?): Goods = call {
        api.updateSku(goodsId, skuId, SkuUpdateRequest(priceFen, inventory)).unwrap()
    }

    suspend fun archiveGoods(id: String): GoodsCreated = call { api.archiveGoods(id).unwrap() }

    /** 上传商品图；返回服务端给的**相对路径**，直接存进 goods.image 字段 */
    suspend fun uploadImage(file: File): String = call {
        val body = file.asRequestBody("image/*".toMediaType())
        val part = MultipartBody.Part.createFormData("file", file.name, body)

        api.uploadImage(part).unwrap().path
    }

    // =========================================================
    // 店铺 / 统计
    // =========================================================

    suspend fun store(): StoreSetting = call {
        api.store().unwrap().also { session.shopName = it.shopName }
    }

    suspend fun updateStore(patch: StorePatch): StoreSetting = call {
        api.updateStore(patch).unwrap().also { session.shopName = it.shopName }
    }

    suspend fun stats(from: String? = null, to: String? = null): StatsData = call { api.stats(from, to).unwrap() }

    suspend fun ledger(page: Int = 1, pageSize: Int = 20): LedgerPage = call {
        api.ledger(page, pageSize).unwrap()
    }

    suspend fun receivables(): List<Receivable> = call { api.receivables().unwrap() }

    suspend fun notifications(limit: Int = 50): Notifications = call { api.notifications(limit).unwrap() }

    suspend fun markNotificationsRead() = call { api.markNotificationsRead().unwrap() }

    // =========================================================
    // 内部
    // =========================================================

    /**
     * 统一执行 + 异常翻译。
     *
     * 【为什么 401 要走 onUnauthorized 回调而不是只抛异常】
     * 会话过期时，如果只是抛一个「登录已过期」，店主可能正停在
     * 订单详情页反复点「确认送达」——他不知道自己需要重新登录。
     * 回调让 App 能立即清会话并跳登录页。
     */
    private suspend fun <T> call(block: suspend () -> T): T = withContext(Dispatchers.IO) {
        try {
            block()
        } catch (e: ApiException) {
            if (e.isUnauthorized) {
                session.clear()
                ApiClient.onUnauthorized()
            }
            throw e
        } catch (e: retrofit2.HttpException) {
            throw NetworkException("服务端返回了异常状态（${e.code()}），请稍后重试", e)
        } catch (e: IOException) {
            throw NetworkException("网络似乎开了小差，请重试。", e)
        } catch (e: kotlinx.serialization.SerializationException) {
            // 契约不匹配是开发期问题，但也要给店主一句人话而不是崩溃
            throw NetworkException("服务端返回了无法解析的数据，请更新 App 或联系维护者", e)
        }
    }

    /**
     * 把相对图片路径补全成可加载的绝对地址。
     *
     * 后端 `shopxo_goods.images` 存的是 `/static/upload/...`，
     * Coil 拿到相对路径会直接加载失败并显示占位图 ——
     * 店主会以为「图片丢了」而重新上传一遍。
     */
    fun absoluteUrl(path: String): String {
        if (path.isBlank()) return ""
        if (path.startsWith("http://") || path.startsWith("https://")) return path

        return BuildConfig.API_BASE_URL.trimEnd('/') + "/" + path.trimStart('/')
    }

    companion object {
        /** 供全局 Json 复用（调试导出等） */
        val debugJson = Json { prettyPrint = true }
    }
}
