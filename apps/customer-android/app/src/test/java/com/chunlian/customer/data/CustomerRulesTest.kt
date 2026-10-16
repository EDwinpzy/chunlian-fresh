package com.chunlian.customer.data

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import kotlinx.serialization.encodeToString
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.json.Json
import java.time.ZoneId
import java.time.ZonedDateTime

class CustomerRulesTest {
    private val now = ZonedDateTime.of(2026, 10, 13, 12, 0, 0, 0, ZoneId.of("Asia/Shanghai"))

    @Test fun purchaseLimitZeroMeansInventoryBoundedUnlimitedAndMinimumIsAtLeastOne() {
        assertEquals(PurchaseQuantityBounds(1, 73), purchaseQuantityBounds(0, 0, 73))
        assertEquals(PurchaseQuantityBounds(2, 5), purchaseQuantityBounds(2, 0, 5))
        assertEquals(PurchaseQuantityBounds(1, 0), purchaseQuantityBounds(0, 0, 0))
        assertEquals(PurchaseQuantityBounds(3, 2), purchaseQuantityBounds(3, 8, 2))
        assertEquals(PurchaseQuantityBounds(2, 4), purchaseQuantityBounds(2, 4, 9))
        assertFalse(purchaseQuantityBounds(3, 0, 2).canPurchase)
        assertFalse(purchaseQuantityBounds(1, 0, 0).canPurchase)
    }

    @Test fun mainlandMobileMustMatchSupportedPrefixAndLength() {
        assertTrue(isValidMainlandMobile("13800138000"))
        assertFalse(isValidMainlandMobile("12800138000"))
        assertFalse(isValidMainlandMobile("1380013800"))
        assertFalse(isValidMainlandMobile("1380013800a"))
    }

    @Test fun scheduledTimeMustBeStrictlyAfterLeadAndWithinSevenDays() {
        assertNotNull(parseExpectedAt("2026-10-13 12:31", now, minLeadMinutes = 30))
        assertNull(parseExpectedAt("2026-10-13 12:30", now, minLeadMinutes = 30))
        assertNull(parseExpectedAt("2026-10-13 11:59", now))
        assertNull(parseExpectedAt("2026-10-20 12:01", now))
        assertNull(parseExpectedAt("2026-02-30 12:00", now))
        assertEquals("2026-10-14T17:30:00+08:00", parseExpectedAt("2026-10-14 17:30", now))
    }

    @Test fun scheduledTimeAndEditPrefillStayInShopZoneOnUtcDevice() {
        val utcDeviceNow = now.withZoneSameInstant(ZoneId.of("UTC"))
        assertEquals("2026-10-14T13:55:00+08:00", parseExpectedAt("2026-10-14 13:55", utcDeviceNow))

        val serverExpectedAt = "2026-10-14T21:55:00+08:00"
        val editInput = formatExpectedAt(serverExpectedAt)
        assertEquals("2026-10-14 21:55", editInput)
        assertEquals(serverExpectedAt, parseExpectedAt(editInput, utcDeviceNow))
        assertEquals(editInput, formatExpectedAt("2026-10-14T13:55:00Z"))
    }

    @Test fun orderItemsAreFilteredMergedAndSortedForStablePreview() {
        val normalized = normalizeOrderItems(
            listOf(
                OrderItemRequest("22", 1),
                OrderItemRequest("11", 0),
                OrderItemRequest("22", 2),
                OrderItemRequest("33", -1),
                OrderItemRequest("11", 4),
            ),
        )
        assertEquals(listOf(OrderItemRequest("11", 4), OrderItemRequest("22", 3)), normalized)
        assertEquals("11:4|22:3", orderItemsFingerprint(normalized))
    }

    @Test fun revisionSubmitPayloadCarriesVersionAndPreviewDoesNot() {
        val json = Json { encodeDefaults = true; explicitNulls = false }
        val items = listOf(OrderItemRequest("42", 2))
        val preview = json.encodeToString(ItemsRequest(items))
        val submit = json.encodeToString(ItemsRequest(items, clientVersion = 7))
        assertFalse(preview.contains("client_version"))
        assertTrue(submit.contains("\"client_version\":7"))
    }

    @Test fun revisionPreviewCopyMatchesPaymentAndSettlementState() {
        val unpaidCodIncrease = revisionDeltaPresentation("COD", paidFen = 0, deltaFen = 500)
        val unpaidCodMessage = requireNotNull(unpaidCodIncrease.message)
        assertEquals("货到应收增加", unpaidCodIncrease.label)
        assertTrue(unpaidCodMessage.contains("立即生效"))
        assertTrue(unpaidCodMessage.contains("送达时按更新后的应收金额收款"))
        assertFalse(unpaidCodMessage.contains("需补付"))

        val unpaidQrIncrease = revisionDeltaPresentation("OFFLINE_QR", paidFen = 0, deltaFen = 500)
        val unpaidQrMessage = requireNotNull(unpaidQrIncrease.message)
        assertEquals("待付款金额增加", unpaidQrIncrease.label)
        assertTrue(unpaidQrMessage.contains("更新后的订单总额扫码付款"))
        assertFalse(unpaidQrMessage.contains("核实后"))

        val paidQrIncrease = revisionDeltaPresentation("OFFLINE_QR", paidFen = 1000, deltaFen = 500)
        val paidQrMessage = requireNotNull(paidQrIncrease.message)
        assertEquals("需补金额", paidQrIncrease.label)
        assertTrue(paidQrMessage.contains("补差额"))
        assertTrue(paidQrMessage.contains("店主核实后新增商品才生效"))

        val unpaidCodDecrease = revisionDeltaPresentation("COD", paidFen = 0, deltaFen = -500)
        val unpaidCodDecreaseMessage = requireNotNull(unpaidCodDecrease.message)
        assertEquals("货到应收减少", unpaidCodDecrease.label)
        assertTrue(unpaidCodDecreaseMessage.contains("送达时按更新后的应收金额收款"))

        val paidQrDecrease = revisionDeltaPresentation("OFFLINE_QR", paidFen = 1000, deltaFen = -500)
        assertEquals("预计退款", paidQrDecrease.label)
        assertNull(paidQrDecrease.message)
    }

    @Test fun shopPaymentQrUrlDecodesFromLiveApiField() {
        val shop = Json.decodeFromString<ShopSetting>("""{"payment_qr_url":"https://example.test/qr.png"}""")
        assertEquals("https://example.test/qr.png", shop.paymentQrUrl)
    }

    @Test fun shopPresentationSettingsDecodeFromLiveApiFields() {
        val shop = Json.decodeFromString<ShopSetting>(
            """{"shop_name":"邻里鲜铺","shop_logo_url":"/uploads/logo.png","shop_address":"西湖路 18 号","business_hours":"08:00-20:00","shop_description":"社区生鲜门店","delivery_description":"周边 3 公里配送","home_banner_title":"今日鲜选","home_banner_subtitle":"下单后及时配送","shop_slogan":"新鲜到家","search_hot_words":["青菜","苹果"],"home_section_title":"本店推荐"}""",
        )
        assertEquals("邻里鲜铺", shop.shopName)
        assertEquals("/uploads/logo.png", shop.shopLogoUrl)
        assertEquals("西湖路 18 号", shop.shopAddress)
        assertEquals("08:00-20:00", shop.businessHours)
        assertEquals("社区生鲜门店", shop.shopDescription)
        assertEquals("周边 3 公里配送", shop.deliveryDescription)
        assertEquals("今日鲜选", shop.homeBannerTitle)
        assertEquals("下单后及时配送", shop.homeBannerSubtitle)
        assertEquals("新鲜到家", shop.shopSlogan)
        assertEquals(listOf("青菜", "苹果"), shop.searchHotWords)
        assertEquals("本店推荐", shop.homeSectionTitle)
    }

    @Test fun shopPresentationSettingsRemainOptionalForOlderApiResponses() {
        val shop = Json.decodeFromString<ShopSetting>("""{"shop_name":"邻里鲜铺"}""")
        assertEquals("邻里鲜铺", shop.shopName)
        assertEquals("", shop.shopLogoUrl)
        assertEquals("", shop.deliveryDescription)
        assertTrue(shop.searchHotWords.isEmpty())
    }

    @Test fun arbitraryConfiguredCategoryNamesAndChildrenDecodeWithoutFixedCatalog() {
        val categories = Json.decodeFromString<List<Category>>(
            """[{"id":"fruit-custom","name":"本地时令","children":[{"id":"pear-custom","name":"香梨"}]}]""",
        )
        assertEquals("fruit-custom", categories.single().id)
        assertEquals("本地时令", categories.single().name)
        assertEquals("pear-custom", categories.single().children.single().id)
        assertEquals("香梨", categories.single().children.single().name)
    }

    @Test fun relativeImagePathsResolveAgainstConfiguredServerOrigin() {
        val base = "https://103.236.97.137:8096"
        assertEquals(
            "https://103.236.97.137:8096/static/upload/2026/qr.png",
            resolveCustomerImageUrl("/static/upload/2026/qr.png", base),
        )
        assertEquals(
            "https://103.236.97.137:8096/static/upload/goods.png",
            resolveCustomerImageUrl("static/upload/goods.png", base),
        )
    }

    @Test fun configuredBasePathAndAbsoluteImageUrlsArePreserved() {
        assertEquals(
            "http://127.0.0.1:18096/static/qr.png",
            resolveCustomerImageUrl("/static/qr.png", "http://127.0.0.1:18096/app/"),
        )
        assertEquals("https://cdn.example.test/goods.png", resolveCustomerImageUrl("https://cdn.example.test/goods.png", "https://shop.example.test"))
    }

    @Test fun malformedOrUnsafeImageUrlsAreRejected() {
        assertNull(resolveCustomerImageUrl("", "https://shop.example.test"))
        assertNull(resolveCustomerImageUrl("javascript:alert(1)", "https://shop.example.test"))
        assertNull(resolveCustomerImageUrl("https://user:password@shop.example.test/image.png", "https://shop.example.test"))
        assertNull(resolveCustomerImageUrl("/static/image.png", "file:///tmp/shop"))
    }

    @Test fun paidQrOrderStillShowsQrForPendingRevisionTopup() {
        assertTrue(shouldShowManualPaymentQr("OFFLINE_QR", "PAID", "PENDING_TOPUP", refundInProgress = false, cancelled = false, receivableFen = 380, pendingTopupFen = 380))
        assertFalse(shouldShowManualPaymentQr("OFFLINE_QR", "PAID", "NONE", refundInProgress = false, cancelled = false, receivableFen = 0, pendingTopupFen = 0))
        assertFalse(shouldShowManualPaymentQr("OFFLINE_QR", "PAID", "PENDING_TOPUP", refundInProgress = false, cancelled = true, receivableFen = 380, pendingTopupFen = 380))
        assertFalse(shouldShowManualPaymentQr("OFFLINE_QR", "PAID", "PENDING_TOPUP", refundInProgress = true, cancelled = false, receivableFen = 380, pendingTopupFen = 380))
        assertTrue(shouldShowManualPaymentQr("OFFLINE_QR", "UNPAID", "NONE", refundInProgress = false, cancelled = false, receivableFen = 0, pendingTopupFen = 0))
        assertTrue(shouldShowManualPaymentQr("OFFLINE_QR", "PARTIAL_REFUNDED", "NONE", refundInProgress = false, cancelled = false, receivableFen = 220, pendingTopupFen = 0))
        assertFalse(shouldShowManualPaymentQr("OFFLINE_QR", "PARTIAL_REFUNDED", "NONE", refundInProgress = false, cancelled = false, receivableFen = 0, pendingTopupFen = 0))
        assertFalse(shouldShowManualPaymentQr("OFFLINE_QR", "REFUNDED", "PENDING_TOPUP", refundInProgress = false, cancelled = false, receivableFen = 380, pendingTopupFen = 380))
        assertTrue(shouldShowManualPaymentQr("COD", "UNPAID", "NONE", refundInProgress = false, cancelled = false, receivableFen = 0, pendingTopupFen = 0))
        assertTrue(shouldShowManualPaymentQr("COD", "PAID", "NONE", refundInProgress = false, cancelled = false, receivableFen = 100, pendingTopupFen = 0))
        assertFalse(shouldShowManualPaymentQr("COD", "PAID", "NONE", refundInProgress = false, cancelled = false, receivableFen = 0, pendingTopupFen = 0))
    }

    @Test fun settledPartialRefundDoesNotOfferAnotherPaymentQr() {
        assertFalse(
            shouldShowManualPaymentQr(
                paymentMethod = "OFFLINE_QR",
                paymentState = "PARTIAL_REFUNDED",
                revisionState = "NONE",
                refundInProgress = false,
                cancelled = false,
                receivableFen = 0,
                pendingTopupFen = 0,
            ),
        )
    }
}
