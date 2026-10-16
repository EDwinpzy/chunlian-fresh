package com.chunlian.merchant.feature.stats

import com.chunlian.merchant.data.model.Receivable
import com.chunlian.merchant.data.model.StatsData
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.fail
import org.junit.Test
import java.io.IOException

class StatsLoadTest {
    @Test
    fun successfulLoadReturnsStatsAndReceivablesTogether() = runBlocking {
        val stats = StatsData(orderCount = 4)
        val receivables = listOf(Receivable(orderNo = "QA-1", receivableFen = 250))

        val result = loadStatsAndReceivables(
            loadStats = { stats },
            loadReceivables = { receivables },
        )

        assertEquals(stats, result.stats)
        assertEquals(receivables, result.receivables)
    }

    @Test
    fun receivableFailurePropagatesInsteadOfReturningAnEmptyList() = runBlocking {
        val expected = IOException("receivables unavailable")
        try {
            loadStatsAndReceivables(
                loadStats = { StatsData(orderCount = 4) },
                loadReceivables = { throw expected },
            )
            fail("Expected the receivables error to reach the screen's retry state")
        } catch (actual: IOException) {
            assertEquals(expected.message, actual.message)
        }
    }
}
