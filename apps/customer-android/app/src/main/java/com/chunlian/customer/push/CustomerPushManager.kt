package com.chunlian.customer.push

import android.content.Context
import android.util.Log
import com.chunlian.customer.BuildConfig
import com.chunlian.customer.data.CustomerApi
import com.chunlian.customer.data.CustomerRepository
import com.chunlian.customer.data.CustomerSession
import com.chunlian.customer.data.PushDeviceRegistrationRequest
import com.igexin.sdk.PushManager
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Getui remains disabled until a real privacy-consent flow calls
 * [initializeAfterPrivacyConsent]. No app entry point calls it yet.
 */
object CustomerPushManager {
    private const val TAG = "CustomerPush"
    private const val PREFERENCES = "customer-push-consent"
    private const val CONSENT_ACCEPTED = "privacy_consent_accepted"
    private val ioScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    /** Call only after the user has reviewed and explicitly accepted the app privacy policy. */
    fun initializeAfterPrivacyConsent(context: Context): Boolean {
        val appContext = context.applicationContext
        appContext.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
            .edit()
            .putBoolean(CONSENT_ACCEPTED, true)
            .apply()
        return initializeIfPreviouslyConsented(appContext)
    }

    /** Reinitializes the SDK after process restart only when a prior explicit consent was saved. */
    fun resumeAfterPreviouslyAcceptedConsent(context: Context) {
        initializeIfPreviouslyConsented(context.applicationContext)
    }

    /** Best-effort bind after login; the CID callback retries when it becomes available. */
    suspend fun onLogin(context: Context) {
        val appContext = context.applicationContext
        if (!isEnabled(appContext)) return
        val repository = CustomerRepository(appContext)
        val cid = runCatching { PushManager.getInstance().getClientid(appContext).orEmpty() }.getOrDefault("")
        if (cid.isNotBlank()) runCatching { registerDevice(appContext, cid, repository.session, repository.api) }
    }

    /** Unbind while the current customer token is still present. */
    suspend fun onLogout(context: Context) {
        val appContext = context.applicationContext
        if (!isEnabled(appContext)) return
        val repository = CustomerRepository(appContext)
        if (repository.session.token().isBlank()) return
        runCatching {
            val result = repository.api.unregisterPushDevice(repository.session.pushInstallationId())
            check(result.code == "OK") { result.message ?: "Push device unbind failed" }
        }
    }

    fun onClientIdAvailable(context: Context, cid: String) {
        if (cid.isBlank()) return
        val appContext = context.applicationContext
        if (!isEnabled(appContext)) return
        ioScope.launch {
            val repository = CustomerRepository(appContext)
            runCatching { registerDevice(appContext, cid, repository.session, repository.api) }
        }
    }

    private fun initializeIfPreviouslyConsented(context: Context): Boolean {
        if (!hasAcceptedPrivacyConsent(context) || BuildConfig.GETUI_APP_ID.isBlank()) return false
        return runCatching {
            startSdk(context)
            val repository = CustomerRepository(context)
            val cid = PushManager.getInstance().getClientid(context).orEmpty()
            if (cid.isNotBlank()) {
                ioScope.launch { registerDevice(context, cid, repository.session, repository.api) }
            }
            true
        }.onFailure { Log.w(TAG, "Could not initialize push", it) }.getOrDefault(false)
    }

    private fun startSdk(context: Context) {
        val manager = PushManager.getInstance()
        manager.preInit(context)
        manager.initialize(context)
        manager.setNotificationIcon(context, "push_small", "push")
    }

    private fun isEnabled(context: Context): Boolean =
        hasAcceptedPrivacyConsent(context) && BuildConfig.GETUI_APP_ID.isNotBlank()

    private fun hasAcceptedPrivacyConsent(context: Context): Boolean =
        context.getSharedPreferences(PREFERENCES, Context.MODE_PRIVATE)
            .getBoolean(CONSENT_ACCEPTED, false)

    private suspend fun registerDevice(
        context: Context,
        cid: String,
        session: CustomerSession,
        api: CustomerApi,
    ) {
        if (!isEnabled(context) || cid.isBlank() || session.token().isBlank()) return
        val response = api.registerPushDevice(
            PushDeviceRegistrationRequest(
                appId = BuildConfig.GETUI_APP_ID,
                cid = cid,
                installationId = session.pushInstallationId(),
            ),
        )
        check(response.code == "OK") { response.message ?: "Push device bind failed" }
    }
}
