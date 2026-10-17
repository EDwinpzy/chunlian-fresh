package com.chunlian.merchant.core.push

import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class GetuiPushInitializationPolicyTest {
    @Test
    fun missingPrivacyConsentKeepsSdkDisabled() {
        assertFalse(
            shouldInitializeGetui(
                "public-app-id",
                "https://example.test/privacy",
                explicitPrivacyConsent = false,
                notificationsEnabled = true,
            ),
        )
    }

    @Test
    fun missingAppIdKeepsSdkDisabledEvenAfterConsent() {
        assertFalse(
            shouldInitializeGetui(
                "",
                "https://example.test/privacy",
                explicitPrivacyConsent = true,
                notificationsEnabled = true,
            ),
        )
    }

    @Test
    fun missingPrivacyPolicyKeepsSdkDisabledEvenAfterConsent() {
        assertFalse(shouldInitializeGetui("public-app-id", "", explicitPrivacyConsent = true, notificationsEnabled = true))
        assertFalse(shouldInitializeGetui("public-app-id", "http://example.test/privacy", explicitPrivacyConsent = true, notificationsEnabled = true))
        assertFalse(hasValidPrivacyPolicyUrl("not a url"))
        assertFalse(hasValidPrivacyPolicyUrl("http://example.test/privacy"))
        assertTrue(hasValidPrivacyPolicyUrl("https://example.test/privacy"))
    }

    @Test
    fun initializationRequiresAppIdPolicyConsentAndNotificationPermission() {
        assertFalse(shouldInitializeGetui("public-app-id", "https://example.test/privacy", explicitPrivacyConsent = true, notificationsEnabled = false))
        assertTrue(shouldInitializeGetui("public-app-id", "https://example.test/privacy", explicitPrivacyConsent = true, notificationsEnabled = true))
    }
}
