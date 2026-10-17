package com.chunlian.merchant.data.model

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable

@Serializable
data class PushDeviceRequest(
    @SerialName("app_id") val appId: String,
    val cid: String,
    @SerialName("installation_id") val installationId: String,
)
