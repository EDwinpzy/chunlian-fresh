package com.chunlian.merchant.data.model

import kotlinx.serialization.json.Json
import org.junit.Assert.assertEquals
import org.junit.Test

class CategorySerializationTest {
    @Test
    fun productCountReadsTheMerchantApiField() {
        val category = Json { ignoreUnknownKeys = true }.decodeFromString<Category>(
            """{"id":"vegetables","name":"叶菜类","product_count":128}""",
        )

        assertEquals(128, category.productCount)
    }
}
