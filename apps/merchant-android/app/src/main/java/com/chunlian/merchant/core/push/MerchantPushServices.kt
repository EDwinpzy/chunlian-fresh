package com.chunlian.merchant.core.push

import android.content.Context
import android.content.Intent
import com.chunlian.merchant.MainActivity
import com.chunlian.merchant.MerchantApp
import com.igexin.sdk.GTIntentService
import com.igexin.sdk.PushService
import com.igexin.sdk.message.GTCmdMessage
import com.igexin.sdk.message.GTNotificationMessage
import com.igexin.sdk.message.GTTransmitMessage
import kotlinx.coroutines.launch

/** Isolated process used by the Getui SDK push connection. */
class MerchantPushService : PushService()

/** Receives Getui CIDs and notification click callbacks. */
class MerchantPushIntentService : GTIntentService() {
    override fun onReceiveServicePid(context: Context, pid: Int) = Unit

    override fun onReceiveMessageData(context: Context, msg: GTTransmitMessage) = Unit

    override fun onReceiveClientId(context: Context, clientid: String) {
        val app = context.applicationContext as? MerchantApp ?: return
        app.applicationScope.launch {
            app.repository.onPushClientIdReceived(clientid)
        }
    }

    override fun onReceiveOnlineState(context: Context, online: Boolean) = Unit

    override fun onReceiveCommandResult(context: Context, cmdMessage: GTCmdMessage) = Unit

    override fun onNotificationMessageArrived(context: Context, msg: GTNotificationMessage) = Unit

    override fun onNotificationMessageClicked(context: Context, msg: GTNotificationMessage) {
        val launchIntent = Intent(context, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        }
        context.startActivity(launchIntent)
    }
}
