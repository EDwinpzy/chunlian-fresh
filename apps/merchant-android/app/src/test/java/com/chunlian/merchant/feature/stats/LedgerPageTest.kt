package com.chunlian.merchant.feature.stats

import com.chunlian.merchant.data.model.LedgerPage
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Test

class LedgerPageTest {
    @Test
    fun pageCountRoundsUpAndAlwaysHasOnePage() {
        assertEquals(1, pageCount(total = 0, pageSize = 20))
        assertEquals(1, pageCount(total = 20, pageSize = 20))
        assertEquals(2, pageCount(total = 21, pageSize = 20))
        assertEquals(1, pageCount(total = 21, pageSize = 0))
    }

    @Test
    fun decodesLedgerResponseUsingBackendFieldNames() {
        val response = Json.decodeFromString<LedgerPage>(
            """{
              "list": [{
                "id": "81",
                "order_id": "5001",
                "order_no": "2026101400355570544",
                "kind": "COD_RECEIPT",
                "kind_label": "货到付款收款",
                "amount_fen": 380,
                "is_inflow": true,
                "status": "SUCCESS",
                "status_label": "已成功",
                "business_no": "qa-cod-81",
                "collection_method": "CASH",
                "occurred_at": "2026-10-14 09:00:00"
              }],
              "total": 21,
              "page": 2,
              "page_size": 20,
              "summary": {
                "inflow_fen": 760,
                "outflow_fen": -100,
                "net_fen": 660,
                "in_flight_count": 1
              }
            }""",
        )

        assertEquals("2026101400355570544", response.list.single().orderNo)
        assertEquals("货到付款收款", response.list.single().kindLabel)
        assertEquals("CASH", response.list.single().collectionMethod)
        assertEquals("2026-10-14 09:00:00", response.list.single().occurredAt)
        assertEquals(-100L, response.summary.outflowFen)
        assertEquals(2, response.page)
    }
}
