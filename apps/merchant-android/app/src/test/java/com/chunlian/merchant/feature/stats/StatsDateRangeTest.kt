package com.chunlian.merchant.feature.stats

import java.time.Instant
import java.util.Date
import java.util.TimeZone
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class StatsDateRangeTest {
    @Test
    fun dateRangesUseShanghaiBusinessDayAcrossUtcMidnightRegardlessOfDeviceTimezone() {
        // 2026-10-13 16:30 UTC is already 2026-10-14 in the shop's timezone.
        val now = Date.from(Instant.parse("2026-10-13T16:30:00Z"))
        val originalTimeZone = TimeZone.getDefault()

        try {
            listOf("UTC", "America/Los_Angeles").forEach { deviceTimeZoneId ->
                TimeZone.setDefault(TimeZone.getTimeZone(deviceTimeZoneId))
                assertEquals(StatsDateRange("2026-10-14", "2026-10-14"), statsDateRange(0, now))
                assertEquals(StatsDateRange("2026-10-08", "2026-10-14"), statsDateRange(6, now))
            }
        } finally {
            TimeZone.setDefault(originalTimeZone)
        }
    }

    @Test
    fun newerRangeRequestMakesOlderResponseStale() {
        val gate = StatsRequestGate()

        val firstRequest = gate.begin()
        assertTrue(gate.isCurrent(firstRequest))

        val secondRequest = gate.begin()
        assertFalse(gate.isCurrent(firstRequest))
        assertTrue(gate.isCurrent(secondRequest))
    }
}
