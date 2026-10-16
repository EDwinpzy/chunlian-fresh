package com.chunlian.customer.data

import java.net.URI

/** Resolve relative ShopXO image paths against the configured server origin. */
fun resolveCustomerImageUrl(rawUrl: String, apiBaseUrl: String): String? {
    val value = rawUrl.trim()
    if (value.isEmpty()) return null

    val base = runCatching {
        URI(apiBaseUrl.trim().trimEnd('/') + "/")
    }.getOrNull() ?: return null
    if (base.scheme !in setOf("http", "https") || base.host.isNullOrBlank() || base.rawUserInfo != null) return null

    val candidate = runCatching { URI(value) }.getOrNull() ?: return null
    val resolved = runCatching { if (candidate.isAbsolute) candidate else base.resolve(candidate) }.getOrNull() ?: return null
    if (resolved.scheme !in setOf("http", "https") || resolved.host.isNullOrBlank() || resolved.rawUserInfo != null) return null
    return resolved.toASCIIString()
}
