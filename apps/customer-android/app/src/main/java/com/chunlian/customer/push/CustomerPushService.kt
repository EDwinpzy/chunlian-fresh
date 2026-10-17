package com.chunlian.customer.push

import android.content.Context
import android.content.Intent
import com.igexin.sdk.GTIntentService
import com.igexin.sdk.message.GTNotificationMessage
import com.igexin.sdk.message.GTTransmitMessage

class CustomerPushService : GTIntentService() {
    override fun onReceiveClientId(context: Context, clientid: String) {
        CustomerPushManager.onClientIdAvailable(context, clientid)
    }

    override fun onReceiveMessageData(context: Context, msg: GTTransmitMessage) {
        // Notification-style Getui messages are displayed by the SDK. Raw transmit
        // messages are left untouched until the server defines a separate payload contract.
    }

    override fun onNotificationMessageArrived(context: Context, message: GTNotificationMessage) {
        // Getui displays notification messages itself; creating another local notice
        // here would show duplicates.
    }

    override fun onNotificationMessageClicked(context: Context, message: GTNotificationMessage) {
        val launchIntent = context.packageManager.getLaunchIntentForPackage(context.packageName) ?: return
        launchIntent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_SINGLE_TOP)
        context.startActivity(launchIntent)
    }
}
