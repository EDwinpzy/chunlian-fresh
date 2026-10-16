package com.chunlian.merchant.feature.products

import com.chunlian.merchant.data.model.Category
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

data class PresetGood(
    val id: String,
    val name: String,
    val specText: String,
    val unit: String,
    val suggestedCategory: String,
    val aliases: List<String>,
    val imageAsset: String? = null,
)

/** Parse the bundled preset catalogue. Unknown fields are ignored for forward compatibility. */
fun parsePresetGoods(jsonText: String): List<PresetGood> {
    val root = Json.parseToJsonElement(jsonText).jsonObject
    require(root["version"]?.jsonPrimitive?.contentOrNull == "1") {
        "Unsupported preset goods version"
    }
    val products = root["products"] as? JsonArray ?: return emptyList()
    return products.mapNotNull { element ->
        val item = element as? JsonObject ?: return@mapNotNull null
        val name = item.string("name").trim()
        if (name.isEmpty()) return@mapNotNull null
        PresetGood(
            id = item.string("id").ifBlank { name },
            name = name,
            specText = item.string("specText"),
            unit = item.string("unit"),
            suggestedCategory = item.string("suggestedCategory"),
            aliases = item["aliases"]?.let { value ->
                runCatching { value.jsonArray.mapNotNull { it.jsonPrimitive.contentOrNull } }.getOrNull()
            }.orEmpty(),
            imageAsset = item.string("imageAsset").takeIf(::isSafePresetImageAssetPath),
        )
    }
}

internal fun isSafePresetImageAssetPath(path: String): Boolean =
    path.matches(Regex("[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*")) &&
        path.split('/').none { it == "." || it == ".." }

fun searchPresetGoods(products: List<PresetGood>, query: String): List<PresetGood> {
    val normalizedQuery = query.trim()
    if (normalizedQuery.isEmpty()) return products
    return products.filter { product ->
        product.name.contains(normalizedQuery, ignoreCase = true) ||
            product.aliases.any { it.contains(normalizedQuery, ignoreCase = true) }
    }
}

/** Match only an enabled category that already exists; callers must leave selection empty otherwise. */
fun matchingPresetCategoryId(preset: PresetGood, enabledCategories: List<Category>): String? {
    val suggested = preset.suggestedCategory.trim()
    if (suggested.isEmpty()) return null
    return enabledCategories.firstOrNull {
        it.isEnabled && it.name.trim().equals(suggested, ignoreCase = true)
    }?.id
}

private fun JsonObject.string(key: String): String =
    this[key]?.let { runCatching { it.jsonPrimitive.contentOrNull }.getOrNull() }.orEmpty()
