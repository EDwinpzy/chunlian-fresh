package com.chunlian.customer.data

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable data class ApiEnvelope<T>(val code: String = "", val message: String? = null, val data: T? = null)
@Serializable data class PageData<T>(val list: List<T> = emptyList(), val total: Int = 0, val page: Int = 1, @SerialName("page_size") val pageSize: Int = 20)

@Serializable data class ShopSetting(
    @SerialName("shop_name") val shopName: String = "", val notice: String = "", val phone: String = "",
    @SerialName("delivery_fee_fen") val deliveryFeeFen: Long = 0, @SerialName("is_open") val isOpen: Boolean = true,
    @SerialName("min_lead_minutes") val minLeadMinutes: Int = 30,
    @SerialName("payment_qr_url") val paymentQrUrl: String = "",
    @SerialName("shop_logo_url") val shopLogoUrl: String = "",
    @SerialName("shop_address") val shopAddress: String = "",
    @SerialName("business_hours") val businessHours: String = "",
    @SerialName("shop_description") val shopDescription: String = "",
    @SerialName("delivery_description") val deliveryDescription: String = "",
    @SerialName("home_banner_title") val homeBannerTitle: String = "",
    @SerialName("home_banner_subtitle") val homeBannerSubtitle: String = "",
    @SerialName("shop_slogan") val shopSlogan: String = "",
    @SerialName("search_hot_words") val searchHotWords: List<String> = emptyList(),
    @SerialName("home_section_title") val homeSectionTitle: String = "",
)
@Serializable data class Category(val id: String = "", val name: String = "", @SerialName("icon_url") val iconUrl: String = "", val children: List<CategoryChild> = emptyList())
@Serializable data class CategoryChild(val id: String = "", val name: String = "", @SerialName("icon_url") val iconUrl: String = "")

@Serializable data class GoodsSummary(
    val id: String = "", val title: String = "", @SerialName("image_url") val imageUrl: String = "",
    @SerialName("simple_desc") val simpleDesc: String = "", @SerialName("spec_desc") val specDesc: String = "",
    @SerialName("inventory_unit") val inventoryUnit: String = "份", val inventory: Int = 0,
    @SerialName("sales_count") val salesCount: Int = 0, @SerialName("has_many_spec") val hasManySpec: Boolean = false,
    @SerialName("min_price_fen") val minPriceFen: Long = 0, @SerialName("max_price_fen") val maxPriceFen: Long = 0,
    @SerialName("is_out_of_stock") val isOutOfStock: Boolean = false,
)
@Serializable data class Sku(
    @SerialName("sku_id") val skuId: String = "", @SerialName("spec_text") val specText: String = "",
    @SerialName("price_fen") val priceFen: Long = 0, @SerialName("original_price_fen") val originalPriceFen: Long = 0,
    val inventory: Int = 0, @SerialName("is_out_of_stock") val isOutOfStock: Boolean = false,
)
@Serializable data class GoodsDetail(
    val id: String = "", val title: String = "", @SerialName("image_url") val imageUrl: String = "",
    @SerialName("simple_desc") val simpleDesc: String = "", @SerialName("spec_desc") val specDesc: String = "",
    @SerialName("inventory_unit") val inventoryUnit: String = "份", val inventory: Int = 0,
    @SerialName("sales_count") val salesCount: Int = 0, @SerialName("has_many_spec") val hasManySpec: Boolean = false,
    @SerialName("min_price_fen") val minPriceFen: Long = 0, @SerialName("max_price_fen") val maxPriceFen: Long = 0,
    @SerialName("is_out_of_stock") val isOutOfStock: Boolean = false, val skus: List<Sku> = emptyList(),
    val images: List<String> = emptyList(), val content: String = "", val origin: String = "",
    @SerialName("buy_min_number") val buyMinNumber: Int = 1, @SerialName("buy_max_number") val buyMaxNumber: Int = 99,
)

@Serializable data class AuthUser(
    @SerialName("user_id") val userId: String = "", val nickname: String = "",
    @SerialName("mobile_masked") val mobileMasked: String = "", val avatar: String = "",
    @SerialName("registered_at") val registeredAt: String = "",
)
@Serializable data class LoginResult(val token: String = "", @SerialName("expires_at") val expiresAt: String = "", val user: AuthUser = AuthUser())
@Serializable data class Address(val id: String = "", val name: String = "", val phone: String = "", val address: String = "", @SerialName("is_default") val isDefault: Boolean = false)
@Serializable data class AddressSaveRequest(val id: String? = null, val name: String, val phone: String, val address: String, @SerialName("is_default") val isDefault: Boolean = false)

@Serializable data class CartItem(
    @SerialName("sku_id") val skuId: String, @SerialName("product_id") val productId: String, val name: String,
    val spec: String, @SerialName("image_url") val imageUrl: String, @SerialName("unit_fen") val unitFen: Long,
    val qty: Int, val inventory: Int, @SerialName("minimum_qty") val minimumQty: Int = 1,
    @SerialName("maximum_qty") val maximumQty: Int = inventory,
)
@Serializable data class OrderLine(
    @SerialName("sku_id") val skuId: String = "", @SerialName("product_id") val productId: String = "",
    val name: String = "", val spec: String = "", @SerialName("image_url") val imageUrl: String = "",
    @SerialName("unit_fen") val unitFen: Long = 0, val qty: Int = 0, @SerialName("subtotal_fen") val subtotalFen: Long = 0,
)
@Serializable data class OrderContact(val name: String = "", val phone: String = "", val address: String = "")
@Serializable data class Order(
    val id: String = "", @SerialName("order_no") val orderNo: String = "", val version: Int = 1,
    val fulfillment: String = "PENDING", @SerialName("fulfillment_text") val fulfillmentText: String = "待处理",
    @SerialName("payment_state") val paymentState: String = "UNPAID", @SerialName("payment_state_text") val paymentStateText: String = "未付款",
    @SerialName("receipt_state") val receiptState: String = "", @SerialName("revision_state") val revisionState: String = "NONE",
    @SerialName("pending_topup_fen") val pendingTopupFen: Long = 0,
    @SerialName("payment_method") val paymentMethod: String = "COD", @SerialName("payment_method_text") val paymentMethodText: String = "货到付款",
    @SerialName("delivery_mode") val deliveryMode: String = "ASAP", @SerialName("expected_at") val expectedAt: String? = null,
    val lines: List<OrderLine> = emptyList(), val contact: OrderContact = OrderContact(),
    @SerialName("goods_total_fen") val goodsTotalFen: Long = 0, @SerialName("delivery_fee_fen") val deliveryFeeFen: Long = 0,
    @SerialName("total_fen") val totalFen: Long = 0, @SerialName("paid_fen") val paidFen: Long = 0,
    @SerialName("refunded_fen") val refundedFen: Long = 0, @SerialName("refunding_fen") val refundingFen: Long = 0,
    @SerialName("receivable_fen") val receivableFen: Long = 0, @SerialName("refundable_fen") val refundableFen: Long = 0,
    @SerialName("delivered_at") val deliveredAt: String? = null, @SerialName("received_at") val receivedAt: String? = null,
    val remark: String = "", @SerialName("can_edit") val canEdit: Boolean = false,
    @SerialName("can_cancel") val canCancel: Boolean = false, @SerialName("can_receive") val canReceive: Boolean = false,
    @SerialName("can_switch_to_cod") val canSwitchToCod: Boolean = false,
)
@Serializable data class CheckoutPreview(
    val lines: List<OrderLine> = emptyList(), @SerialName("goods_total_fen") val goodsTotalFen: Long = 0,
    @SerialName("delivery_fee_fen") val deliveryFeeFen: Long = 0, @SerialName("total_fen") val totalFen: Long = 0,
    @SerialName("delivery_mode") val deliveryMode: String = "ASAP", @SerialName("shop_open") val shopOpen: Boolean = true,
    @SerialName("shop_name") val shopName: String = "", val notice: String = "",
)
@Serializable data class CreateOrderResult(val order: Order? = null, @SerialName("pay_params") val payParams: Map<String, String>? = null, @SerialName("payment_ready") val paymentReady: Boolean = false, @SerialName("payment_hint") val paymentHint: String = "")

@Serializable data class OrderItemRequest(@SerialName("sku_id") val skuId: String, val qty: Int)
@Serializable data class ItemsRequest(
    val items: List<OrderItemRequest>,
    @SerialName("client_version") val clientVersion: Int? = null,
)
@Serializable data class ContactRequest(val name: String, val phone: String, val address: String)
@Serializable data class OrderInfoRequest(
    val version: Int, val contact: ContactRequest? = null, @SerialName("delivery_mode") val deliveryMode: String? = null,
    @SerialName("expected_at") val expectedAt: String? = null, val remark: String? = null,
)
@Serializable data class CancelOrderRequest(val version: Int, val reason: String)
@Serializable data class ReceiveOrderRequest(val version: Int)
@Serializable data class CreateOrderRequest(
    val items: List<OrderItemRequest>, val contact: ContactRequest, @SerialName("delivery_mode") val deliveryMode: String,
    @SerialName("expected_at") val expectedAt: String? = null, @SerialName("payment_method") val paymentMethod: String,
    val remark: String = "", @SerialName("client_type") val clientType: String = "android_native",
)
