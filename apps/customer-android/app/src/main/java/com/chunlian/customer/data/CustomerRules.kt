package com.chunlian.customer.data

import java.time.ZonedDateTime
import java.time.Instant
import java.time.LocalDateTime
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.ResolverStyle

private val mainlandMobile = Regex("^1[3-9]\\d{9}$")
private val shopZone = ZoneId.of("Asia/Shanghai")
private val expectedInput = DateTimeFormatter.ofPattern("uuuu-MM-dd HH:mm")
    .withResolverStyle(ResolverStyle.STRICT)

fun isValidMainlandMobile(value: String): Boolean = mainlandMobile.matches(value.trim())

/** Selecting a home shortcut filters the home list without switching to the category tab. */
fun CustomerUiState.withHomeCategorySelection(categoryId: String?): CustomerUiState =
    copy(tab = CustomerTab.HOME, categoryId = categoryId)

data class PurchaseQuantityBounds(val minimum: Int, val maximum: Int) {
    val canPurchase: Boolean get() = maximum >= minimum && maximum > 0
}

/** ShopXO uses buy_max_number = 0 for no configured cap; inventory remains the hard limit. */
fun purchaseQuantityBounds(buyMinNumber: Int, buyMaxNumber: Int, inventory: Int): PurchaseQuantityBounds {
    val minimum = buyMinNumber.coerceAtLeast(1)
    val stock = inventory.coerceAtLeast(0)
    val configuredMaximum = buyMaxNumber.takeIf { it > 0 } ?: Int.MAX_VALUE
    return PurchaseQuantityBounds(minimum, minOf(stock, configuredMaximum))
}

/** Parses a shop-local delivery time and enforces the same window as the ShopXO service. */
fun parseExpectedAt(
    value: String,
    now: ZonedDateTime = ZonedDateTime.now(shopZone),
    minLeadMinutes: Int = 0,
    maxDays: Long = 7,
): String? = runCatching {
    val local = LocalDateTime.parse(value.trim(), expectedInput)
    val nowAtShop = now.withZoneSameInstant(shopZone)
    val expected = local.atZone(shopZone)
    require(expected.isAfter(nowAtShop.plusMinutes(minLeadMinutes.coerceAtLeast(0).toLong())))
    require(!expected.isAfter(nowAtShop.plusDays(maxDays)))
    expected.format(DateTimeFormatter.ISO_OFFSET_DATE_TIME)
}.getOrNull()

/** Displays server timestamps as store-local wall time regardless of the device time zone. */
fun formatExpectedAt(value: String?): String {
    val raw = value?.trim().orEmpty()
    if (raw.isEmpty()) return ""

    val shopTime = runCatching { OffsetDateTime.parse(raw).atZoneSameInstant(shopZone) }.getOrNull()
        ?: runCatching { Instant.parse(raw).atZone(shopZone) }.getOrNull()
        ?: runCatching { LocalDateTime.parse(raw, DateTimeFormatter.ISO_LOCAL_DATE_TIME).atZone(shopZone) }.getOrNull()
        ?: runCatching { LocalDateTime.parse(raw.replace(' ', 'T'), DateTimeFormatter.ISO_LOCAL_DATE_TIME).atZone(shopZone) }.getOrNull()

    return shopTime?.format(expectedInput) ?: raw.replace('T', ' ').take(16)
}

fun normalizeOrderItems(items: List<OrderItemRequest>): List<OrderItemRequest> =
    items.asSequence()
        .filter { it.skuId.isNotBlank() && it.qty > 0 }
        .groupBy { it.skuId }
        .map { (skuId, sameSku) -> OrderItemRequest(skuId, sameSku.sumOf { it.qty }) }
        .sortedBy { it.skuId }

fun orderItemsFingerprint(items: List<OrderItemRequest>): String =
    normalizeOrderItems(items).joinToString("|") { "${it.skuId}:${it.qty}" }

/** An already-paid QR order still needs the shop QR when an increase is awaiting top-up. */
fun shouldShowManualPaymentQr(
    paymentMethod: String,
    paymentState: String,
    revisionState: String,
    refundInProgress: Boolean,
    cancelled: Boolean,
    receivableFen: Long,
    pendingTopupFen: Long,
): Boolean {
    val originalPaymentUnsettled = paymentState in setOf("UNPAID", "PAYING") ||
        (paymentState != "REFUNDED" && receivableFen > 0L)
    val revisionTopupPending = paymentMethod == "OFFLINE_QR" &&
        paymentState != "REFUNDED" &&
        (revisionState == "PENDING_TOPUP" || pendingTopupFen > 0L)
    return paymentMethod in setOf("OFFLINE_QR", "COD") &&
        (originalPaymentUnsettled || revisionTopupPending) &&
        !refundInProgress &&
        !cancelled
}

data class RevisionDeltaPresentation(val label: String, val message: String? = null)

/** Customer-facing copy follows the same settlement rules as the revision service. */
fun revisionDeltaPresentation(paymentMethod: String, paidFen: Long, deltaFen: Long): RevisionDeltaPresentation {
    val paid = paidFen > 0L
    return when {
        deltaFen > 0L && paymentMethod == "COD" && !paid -> RevisionDeltaPresentation(
            "货到应收增加",
            "商品修改确认后立即生效，送达时按更新后的应收金额收款。",
        )
        deltaFen > 0L && paymentMethod == "OFFLINE_QR" && !paid -> RevisionDeltaPresentation(
            "待付款金额增加",
            "商品修改确认后立即生效，请按更新后的订单总额扫码付款。",
        )
        deltaFen > 0L && paymentMethod == "OFFLINE_QR" -> RevisionDeltaPresentation(
            "需补金额",
            "已付款订单需先补差额，店主核实后新增商品才生效。",
        )
        deltaFen > 0L && paid -> RevisionDeltaPresentation(
            "需补金额",
            "此单已付款，增加金额需要补款；当前没有可用的补款方式，原订单不会改变。",
        )
        deltaFen > 0L -> RevisionDeltaPresentation(
            "待付款金额增加",
            "商品修改确认后立即生效，请按更新后的订单总额完成付款。",
        )
        deltaFen < 0L && paymentMethod == "COD" && !paid -> RevisionDeltaPresentation(
            "货到应收减少",
            "商品修改确认后立即生效，送达时按更新后的应收金额收款。",
        )
        deltaFen < 0L && paymentMethod == "OFFLINE_QR" && !paid -> RevisionDeltaPresentation(
            "待付款金额减少",
            "商品修改确认后立即生效，请按更新后的订单总额扫码付款。",
        )
        deltaFen < 0L && paid -> RevisionDeltaPresentation("预计退款")
        deltaFen < 0L -> RevisionDeltaPresentation(
            "金额减少",
            "商品修改确认后立即生效，订单金额已相应减少。",
        )
        else -> RevisionDeltaPresentation("金额变化")
    }
}
