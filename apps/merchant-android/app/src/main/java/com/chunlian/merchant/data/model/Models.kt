package com.chunlian.merchant.data.model

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

/**
 * 商家端数据模型。
 *
 * 【金额约定】所有金额字段都是 `*_fen` 且为**整数分**（Long）。
 * 显示时统一走 `fen2yuan()`，绝不在业务代码里做 `/100.0` ——
 * 浮点会在累加时产生分位误差，而收款金额差一分钱就是要对账的事。
 */

// ============================================================
// 认证
// ============================================================

@Serializable
data class LoginRequest(
    val username: String,
    val password: String,
    @SerialName("device_id") val deviceId: String,
)

@Serializable
data class OpenSessionRequest(
    @SerialName("device_id") val deviceId: String,
)

@Serializable
data class LoginResult(
    val token: String = "",
    @SerialName("expires_at") val expiresAt: String = "",
    @SerialName("merchant_id") val merchantId: String = "",
    @SerialName("shop_name") val shopName: String = "",
)

// ============================================================
// 店铺设置
// ============================================================

@Serializable
data class StoreSetting(
    @SerialName("shop_name") val shopName: String = "",
    @SerialName("shop_address") val shopAddress: String = "",
    @SerialName("business_hours") val businessHours: String = "",
    @SerialName("shop_description") val shopDescription: String = "",
    @SerialName("delivery_description") val deliveryDescription: String = "",
    @SerialName("home_banner_title") val homeBannerTitle: String = "",
    @SerialName("home_banner_subtitle") val homeBannerSubtitle: String = "",
    @SerialName("shop_slogan") val shopSlogan: String = "",
    @SerialName("search_hot_words") val searchHotWords: List<String> = emptyList(),
    @SerialName("home_section_title") val homeSectionTitle: String = "",
    @SerialName("shop_logo_url") val shopLogoUrl: String = "",
    val notice: String = "",
    val phone: String = "",
    @SerialName("delivery_fee_fen") val deliveryFeeFen: Long = 0,
    @SerialName("payment_qr_url") val paymentQrUrl: String = "",
    @SerialName("is_open") val isOpen: Boolean = true,
    @SerialName("min_lead_minutes") val minLeadMinutes: Int = 30,
    @SerialName("updated_at") val updatedAt: String = "",
)

@Serializable
data class StorePatch(
    @SerialName("shop_name") val shopName: String? = null,
    @SerialName("shop_address") val shopAddress: String? = null,
    @SerialName("business_hours") val businessHours: String? = null,
    @SerialName("shop_description") val shopDescription: String? = null,
    @SerialName("delivery_description") val deliveryDescription: String? = null,
    @SerialName("home_banner_title") val homeBannerTitle: String? = null,
    @SerialName("home_banner_subtitle") val homeBannerSubtitle: String? = null,
    @SerialName("shop_slogan") val shopSlogan: String? = null,
    @SerialName("search_hot_words") val searchHotWords: List<String>? = null,
    @SerialName("home_section_title") val homeSectionTitle: String? = null,
    @SerialName("shop_logo_url") val shopLogoUrl: String? = null,
    val notice: String? = null,
    val phone: String? = null,
    @SerialName("delivery_fee_fen") val deliveryFeeFen: Long? = null,
    @SerialName("payment_qr_url") val paymentQrUrl: String? = null,
    @SerialName("is_open") val isOpen: Boolean? = null,
    @SerialName("min_lead_minutes") val minLeadMinutes: Int? = null,
)

// ============================================================
// 工作台
// ============================================================

@Serializable
data class DashboardData(
    @SerialName("pending_count") val pendingCount: Int = 0,
    @SerialName("delivering_count") val deliveringCount: Int = 0,
    @SerialName("delivered_uncollected_count") val deliveredUncollectedCount: Int = 0,
    @SerialName("pending_topup_count") val pendingTopupCount: Int = 0,
    @SerialName("pending_refund_count") val pendingRefundCount: Int = 0,
    @SerialName("today_received_fen") val todayReceivedFen: Long = 0,
    @SerialName("today_wechat_received_fen") val todayWechatReceivedFen: Long = 0,
    @SerialName("today_cod_received_fen") val todayCodReceivedFen: Long = 0,
    @SerialName("today_offline_qr_received_fen") val todayOfflineQrReceivedFen: Long = 0,
    @SerialName("today_refund_fen") val todayRefundFen: Long = 0,
    @SerialName("today_order_count") val todayOrderCount: Int = 0,
    @SerialName("today_receivable_fen") val todayReceivableFen: Long = 0,
    val store: StoreSetting? = null,
    @SerialName("recent_orders") val recentOrders: List<RecentOrder> = emptyList(),
    @SerialName("today_orders") val todayOrders: List<RecentOrder> = emptyList(),
    @SerialName("pending_orders") val pendingOrders: List<RecentOrder> = emptyList(),
    @SerialName("delivering_orders") val deliveringOrders: List<RecentOrder> = emptyList(),
    @SerialName("uncollected_orders") val uncollectedOrders: List<RecentOrder> = emptyList(),
)

@Serializable
data class RecentOrder(
    @SerialName("order_id") val orderId: String = "",
    @SerialName("order_no") val orderNo: String = "",
    val summary: String = "",
    val fulfillment: String = "PENDING",
    @SerialName("payment_state") val paymentState: String = "UNPAID",
    @SerialName("payment_method") val paymentMethod: String = "COD",
    @SerialName("total_fen") val totalFen: Long = 0,
    @SerialName("receivable_fen") val receivableFen: Long = 0,
    @SerialName("delivery_mode") val deliveryMode: String = "ASAP",
    @SerialName("expected_at") val expectedAt: String? = null,
    @SerialName("contact_name") val contactName: String = "",
    @SerialName("masked_phone") val maskedPhone: String = "",
    val address: String = "",
    @SerialName("revision_state") val revisionState: String = "NONE",
)

// ============================================================
// 订单
// ============================================================

@Serializable
data class OrderLine(
    @SerialName("sku_id") val skuId: String = "",
    @SerialName("product_id") val productId: String = "",
    @SerialName("name_snapshot") val nameSnapshot: String = "",
    @SerialName("spec_snapshot") val specSnapshot: String = "",
    @SerialName("image_snapshot") val imageSnapshot: String = "",
    @SerialName("unit_fen") val unitFen: Long = 0,
    val qty: Int = 0,
    @SerialName("subtotal_fen") val subtotalFen: Long = 0,
)

@Serializable
data class OrderContact(
    val name: String = "",
    @SerialName("masked_phone") val maskedPhone: String = "",
    @SerialName("phone") val phone: String = "",
    val address: String = "",
)

@Serializable
data class Order(
    val id: String = "",
    @SerialName("order_no") val orderNo: String = "",
    val version: Int = 1,
    val fulfillment: String = "PENDING",
    @SerialName("payment_state") val paymentState: String = "UNPAID",
    @SerialName("receipt_state") val receiptState: String = "UNCONFIRMED",
    @SerialName("revision_state") val revisionState: String = "NONE",
    @SerialName("can_start_delivery") val canStartDelivery: Boolean? = null,
    @SerialName("payment_method") val paymentMethod: String = "COD",
    @SerialName("delivery_mode") val deliveryMode: String = "ASAP",
    @SerialName("expected_at") val expectedAt: String? = null,
    val lines: List<OrderLine> = emptyList(),
    val contact: OrderContact = OrderContact(),
    @SerialName("goods_total_fen") val goodsTotalFen: Long = 0,
    @SerialName("delivery_fee_fen") val deliveryFeeFen: Long = 0,
    @SerialName("total_fen") val totalFen: Long = 0,
    @SerialName("paid_fen") val paidFen: Long = 0,
    @SerialName("refunded_fen") val refundedFen: Long = 0,
    @SerialName("refunding_fen") val refundingFen: Long = 0,
    @SerialName("receivable_fen") val receivableFen: Long = 0,
    @SerialName("refundable_fen") val refundableFen: Long = 0,
    val remark: String = "",
    @SerialName("pending_manual_refunds") val pendingManualRefunds: List<PendingManualRefund> = emptyList(),
) {
    /** 是否是「已送达但货款未收」—— 这类单要一直挂在待收款里 */
    val isCodUncollected: Boolean
        get() = paymentMethod == "COD" && receivableFen > 0

    /** 非 COD 订单须有足额到账记录才能配送；线下扫码由商家手动核实。 */
    val hasOutstandingPrepayment: Boolean
        get() = paymentMethod != "COD" && paidFen - refundedFen < totalFen

    /** 存在未结补款：不能开始配送 */
    val hasPendingTopup: Boolean
        get() = revisionState == "PENDING_TOPUP"

    /** APPLYING 等待店主核实线下退款；服务端也会阻止开始配送。 */
    val hasRefundAwaitingVerification: Boolean
        get() = revisionState == "APPLYING"

    /** 优先采用服务端计算结果；兼容未提供 can_start_delivery 的旧响应。 */
    val mayStartDelivery: Boolean
        get() = canStartDelivery ?: !(hasPendingTopup || hasRefundAwaitingVerification || hasOutstandingPrepayment)
}

@Serializable
data class PendingManualRefund(
    val id: String = "",
    @SerialName("revision_id") val revisionId: String? = null,
    @SerialName("amount_fen") val amountFen: Long = 0,
    @SerialName("business_no") val businessNo: String = "",
)

@Serializable
data class OrderPage(
    val list: List<Order> = emptyList(),
    val total: Int = 0,
    val page: Int = 1,
    @SerialName("page_size") val pageSize: Int = 20,
)

// ============================================================
// 送达与收款
// ============================================================

@Serializable
data class DeliverRequest(
    val version: Int,
    @SerialName("collected_on_delivery") val collectedOnDelivery: Boolean = false,
    @SerialName("collection_method") val collectionMethod: String? = null,
    @SerialName("collected_fen") val collectedFen: Long? = null,
)

@Serializable
data class DispatchRequest(val version: Int)

@Serializable
data class CodReceiptRequest(
    val version: Int,
    @SerialName("collected_fen") val collectedFen: Long,
    @SerialName("collection_method") val collectionMethod: String,
    val note: String? = null,
)

@Serializable
data class QrReceiptRequest(
    val version: Int,
    @SerialName("amount_fen") val amountFen: Long,
)

@Serializable
data class ManualRefundRequest(
    val version: Int,
    @SerialName("amount_fen") val amountFen: Long,
)

@Serializable
data class ManualMoneyActionResult(
    val kind: String = "",
    @SerialName("amount_fen") val amountFen: Long = 0,
    @SerialName("payment_state") val paymentState: String = "",
    @SerialName("revision_state") val revisionState: String = "",
    @SerialName("refunded_fen") val refundedFen: Long = 0,
    val version: Int = 0,
)

/** 收款渠道：现金 / 线下扫码 / 其他 */
enum class CollectionMethod(val value: String, val label: String) {
    CASH("CASH", "现金"),
    OFFLINE_QR("OFFLINE_QR", "线下扫码"),
    OTHER("OTHER", "其他"),
}

// ============================================================
// 商品
// ============================================================

@Serializable
data class Goods(
    val id: String = "",
    val title: String = "",
    val image: String = "",
    @SerialName("simple_desc") val simpleDesc: String = "",
    @SerialName("spec_desc") val specDesc: String = "",
    @SerialName("inventory_unit") val inventoryUnit: String = "",
    val inventory: Int = 0,
    @SerialName("sales_count") val salesCount: Int = 0,
    @SerialName("is_shelves") val isShelves: Boolean = true,
    @SerialName("has_many_spec") val hasManySpec: Boolean = false,
    @SerialName("min_price_fen") val minPriceFen: Long = 0,
    @SerialName("max_price_fen") val maxPriceFen: Long = 0,
    @SerialName("is_out_of_stock") val isOutOfStock: Boolean = false,
    @SerialName("category_id") val categoryId: String = "",
    val skus: List<Sku> = emptyList(),
)

@Serializable
data class Sku(
    @SerialName("sku_id") val skuId: String = "",
    @SerialName("spec_text") val specText: String = "",
    @SerialName("price_fen") val priceFen: Long = 0,
    @SerialName("original_price_fen") val originalPriceFen: Long = 0,
    val inventory: Int = 0,
)

@Serializable
data class GoodsPage(
    val list: List<Goods> = emptyList(),
    val total: Int = 0,
    val page: Int = 1,
    @SerialName("page_size") val pageSize: Int = 20,
)

@Serializable
data class Category(
    val id: String = "",
    @SerialName("parent_id") val parentId: String? = null,
    val name: String = "",
    val sort: Int = 0,
    @SerialName("is_enabled") val isEnabled: Boolean = true,
    @SerialName("product_count") val productCount: Int = 0,
    val children: List<Category> = emptyList(),
)

@Serializable
data class CategoryCreateRequest(
    val name: String,
    @SerialName("parent_id") val parentId: String? = null,
    val sort: Int? = null,
    @SerialName("is_enabled") val isEnabled: Boolean? = null,
)

@Serializable
data class CategoryPatch(
    val name: String? = null,
    val sort: Int? = null,
    @SerialName("is_enabled") val isEnabled: Boolean? = null,
)

@Serializable
data class CategoryMutationResult(
    val id: String = "",
    val deleted: Boolean = false,
)

@Serializable
data class ShelvesRequest(@SerialName("is_shelves") val isShelves: Boolean)

@Serializable
data class SkuUpdateRequest(
    @SerialName("price_fen") val priceFen: Long? = null,
    val inventory: Int? = null,
)

@Serializable
data class GoodsCreateRequest(
    val title: String,
    @SerialName("price_fen") val priceFen: Long,
    val inventory: Int,
    @SerialName("inventory_unit") val inventoryUnit: String = "份",
    @SerialName("spec_text") val specText: String = "",
    @SerialName("simple_desc") val simpleDesc: String = "",
    @SerialName("category_id") val categoryId: String? = null,
    val image: String? = null,
    @SerialName("is_shelves") val isShelves: Boolean = true,
)

@Serializable
data class GoodsUpdateRequest(
    val title: String? = null,
    @SerialName("simple_desc") val simpleDesc: String? = null,
    @SerialName("spec_desc") val specDesc: String? = null,
    @SerialName("category_id") val categoryId: String? = null,
    val image: String? = null,
    @SerialName("inventory_unit") val inventoryUnit: String? = null,
)

@Serializable
data class GoodsCreated(val id: String = "", @SerialName("sku_id") val skuId: String = "")

// ============================================================
// 统计 / 应收 / 通知
// ============================================================

@Serializable
data class StatsData(
    val from: String = "",
    val to: String = "",
    @SerialName("received_wechat_fen") val receivedWechatFen: Long = 0,
    @SerialName("received_cod_fen") val receivedCodFen: Long = 0,
    @SerialName("received_offline_qr_fen") val receivedOfflineQrFen: Long = 0,
    @SerialName("refunded_fen") val refundedFen: Long = 0,
    @SerialName("received_total_fen") val receivedTotalFen: Long = 0,
    @SerialName("receivable_fen") val receivableFen: Long = 0,
    @SerialName("receivable_count") val receivableCount: Int = 0,
    @SerialName("order_count") val orderCount: Int = 0,
    @SerialName("cod_order_count") val codOrderCount: Int = 0,
    val daily: List<DailyStat> = emptyList(),
)

@Serializable
data class DailyStat(
    val date: String = "",
    @SerialName("received_fen") val receivedFen: Long = 0,
)

@Serializable
data class Receivable(
    @SerialName("order_id") val orderId: String = "",
    @SerialName("order_no") val orderNo: String = "",
    @SerialName("receivable_fen") val receivableFen: Long = 0,
    @SerialName("delivered_at") val deliveredAt: String? = null,
    @SerialName("contact_name") val contactName: String = "",
    @SerialName("masked_phone") val maskedPhone: String = "",
)

@Serializable
data class LedgerPage(
    val list: List<LedgerEntry> = emptyList(),
    val total: Int = 0,
    val page: Int = 1,
    @SerialName("page_size") val pageSize: Int = 20,
    val summary: LedgerSummary = LedgerSummary(),
)

@Serializable
data class LedgerEntry(
    val id: String = "",
    @SerialName("order_id") val orderId: String = "",
    @SerialName("order_no") val orderNo: String = "",
    val kind: String = "",
    @SerialName("kind_label") val kindLabel: String = "",
    @SerialName("amount_fen") val amountFen: Long = 0,
    @SerialName("is_inflow") val isInflow: Boolean = false,
    val status: String = "",
    @SerialName("status_label") val statusLabel: String = "",
    @SerialName("business_no") val businessNo: String = "",
    @SerialName("collection_method") val collectionMethod: String? = null,
    @SerialName("occurred_at") val occurredAt: String = "",
)

@Serializable
data class LedgerSummary(
    @SerialName("inflow_fen") val inflowFen: Long = 0,
    @SerialName("outflow_fen") val outflowFen: Long = 0,
    @SerialName("net_fen") val netFen: Long = 0,
    @SerialName("in_flight_count") val inFlightCount: Int = 0,
)

@Serializable
data class Notifications(
    val list: List<NotificationItem> = emptyList(),
    val total: Int = 0,
    @SerialName("unread_count") val unreadCount: Int = 0,
)

@Serializable
data class NotificationItem(
    val id: String = "",
    @SerialName("event_type") val eventType: String = "",
    val title: String = "",
    val body: String = "",
    @SerialName("order_id") val orderId: String? = null,
    val level: String = "normal",
    @SerialName("is_unread") val isUnread: Boolean = false,
    @SerialName("created_at") val createdAt: String = "",
)

// ============================================================
// 修订历史
// ============================================================

@Serializable
data class RevisionRecord(
    val id: String = "",
    @SerialName("from_version") val fromVersion: Int = 0,
    @SerialName("to_version") val toVersion: Int = 0,
    @SerialName("state") val status: String = "",
    @SerialName("delta_fen") val deltaFen: Long = 0,
    @SerialName("created_at") val createdAt: String = "",
    val summary: String = "",
)

@Serializable
data class RevisionHistory(
    val list: List<RevisionRecord> = emptyList(),
    val total: Int = 0,
)
