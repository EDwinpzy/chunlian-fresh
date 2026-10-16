package com.chunlian.merchant.core.network

import android.content.SharedPreferences
import com.chunlian.merchant.feature.settings.localEncryptionStatusLabel
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertSame
import org.junit.Assert.assertTrue
import org.junit.Test
import java.lang.reflect.Proxy

class SessionStoreTest {
    @Test
    fun encryptionCreationFailureFallsBackAndIsReportedAsUnencrypted() {
        val plainPreferences = fakeSharedPreferences()
        var fallbackUsed = false

        val preferences = createSessionPreferences(
            createEncrypted = { error("Keystore unavailable") },
            createPlain = {
                fallbackUsed = true
                plainPreferences
            },
        )

        assertTrue(fallbackUsed)
        assertSame(plainPreferences, preferences)
        assertFalse(usesEncryptedPreferences(preferences))
        assertEquals("不可用（降级为普通存储）", localEncryptionStatusLabel(usesEncryptedPreferences(preferences)))
    }

    @Test
    fun settingsLabelMatchesBothStorageStates() {
        assertEquals("已启用", localEncryptionStatusLabel(true))
        assertEquals("不可用（降级为普通存储）", localEncryptionStatusLabel(false))
    }

    @Test
    fun sessionIsValidOnlyWhenTokenAndFutureExpiryArePresent() {
        val store = SessionStore.fromPreferences(memorySharedPreferences())
        store.token = "open-session-token"
        store.expiresAt = "2026-10-15 12:00:00"
        val expiry = requireNotNull(parseSessionExpiryMillis(store.expiresAt))

        assertTrue(store.hasValidSession(expiry - 1))
        assertFalse(store.hasValidSession(expiry))
        assertFalse(store.hasValidSession(expiry + 1))

        store.expiresAt = ""
        assertFalse(store.hasValidSession(expiry - 1))
    }

    @Test
    fun sessionExpiryParsesIsoOffsetsAndRejectsMalformedValues() {
        assertEquals(
            1_798_689_600_000L,
            parseSessionExpiryMillis("2026-12-31T12:00:00+08:00"),
        )
        assertEquals(
            1_798_689_600_000L,
            parseSessionExpiryMillis("2026-12-31T04:00:00Z"),
        )
        assertEquals(null, parseSessionExpiryMillis("not-a-date"))
    }

    @Test
    fun deviceIdIsStableAcrossSessionStoreInstances() {
        val preferences = memorySharedPreferences()
        val first = SessionStore.fromPreferences(preferences).getOrCreateDeviceId()
        val second = SessionStore.fromPreferences(preferences).getOrCreateDeviceId()

        assertEquals(first, second)
        assertTrue(first.isNotBlank())
    }

    private fun fakeSharedPreferences(): SharedPreferences = Proxy.newProxyInstance(
        SharedPreferences::class.java.classLoader,
        arrayOf(SharedPreferences::class.java),
    ) { _, _, _ -> null } as SharedPreferences
}
