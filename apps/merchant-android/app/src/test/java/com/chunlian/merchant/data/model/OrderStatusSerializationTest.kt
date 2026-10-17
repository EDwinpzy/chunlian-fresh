package com.chunlian.merchant.data.model

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Test

class OrderStatusSerializationTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun orderReadsUnifiedStatusFields() {
        val order = json.decodeFromString<Order>(
            """{"order_status":"FINISHED","order_status_text":"已完成","received_at":"2026-10-17 12:30:00"}""",
        )

        assertEquals("FINISHED", order.orderStatus)
        assertEquals("已完成", order.orderStatusText)
        assertEquals("2026-10-17 12:30:00", order.receivedAt)
    }

    @Test
    fun recentOrderReadsUnifiedStatusFields() {
        val order = json.decodeFromString<RecentOrder>(
            """{"order_status":"DELIVERED","order_status_text":"已送达"}""",
        )

        assertEquals("DELIVERED", order.orderStatus)
        assertEquals("已送达", order.orderStatusText)
    }
}
