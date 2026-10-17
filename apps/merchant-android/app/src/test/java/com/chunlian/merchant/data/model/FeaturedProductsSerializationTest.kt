package com.chunlian.merchant.data.model

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.encodeToJsonElement
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonPrimitive
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Test

class FeaturedProductsSerializationTest {
    private val json = Json { ignoreUnknownKeys = true }

    @Test
    fun storeSettingReadsFeaturedProductIdsInCarouselOrder() {
        val store = json.decodeFromString<StoreSetting>(
            """{"home_featured_product_ids":["product-b","product-a"]}""",
        )

        assertEquals(listOf("product-b", "product-a"), store.homeFeaturedProductIds)
    }

    @Test
    fun storePatchSendsFeaturedProductIdsInCarouselOrder() {
        val encoded = Json.encodeToJsonElement(
            StorePatch.serializer(),
            StorePatch(homeFeaturedProductIds = listOf("product-b", "product-a")),
        ).jsonObject

        val ids = encoded["home_featured_product_ids"]?.jsonArray
        assertNotNull(ids)
        assertEquals(listOf("product-b", "product-a"), ids!!.map { it.jsonPrimitive.content })
    }
}
