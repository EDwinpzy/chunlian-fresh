package com.chunlian.customer

import android.app.Application
import com.chunlian.customer.push.CustomerPushManager

class CustomerApplication : Application() {
    override fun onCreate() {
        super.onCreate()
        // The stored value is false by default. Push initializes here only after a
        // future privacy-consent screen explicitly calls initializeAfterPrivacyConsent.
        CustomerPushManager.resumeAfterPreviouslyAcceptedConsent(this)
    }
}
