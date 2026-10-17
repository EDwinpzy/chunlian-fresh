package com.chunlian.merchant.feature.settings

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class FeaturedProductsSelectionTest {
    @Test
    fun addingAndRemovingPreservesCarouselOrder() {
        val selected = listOf("first", "second")

        assertEquals(listOf("first", "second", "third"), toggleFeaturedProductSelection(selected, "third"))
        assertEquals(listOf("second"), toggleFeaturedProductSelection(selected, "first"))
    }

    @Test
    fun selectionIsLimitedToTenProducts() {
        val selected = (1..10).map { "product-$it" }

        assertNull(toggleFeaturedProductSelection(selected, "product-11"))
        assertEquals(selected.dropLast(1), toggleFeaturedProductSelection(selected, "product-10"))
    }

    @Test
    fun movingProductsChangesTheirSavedCarouselOrder() {
        val selected = listOf("first", "second", "third")

        assertEquals(listOf("second", "first", "third"), moveFeaturedProductSelection(selected, "second", -1))
        assertEquals(listOf("first", "third", "second"), moveFeaturedProductSelection(selected, "second", 1))
        assertEquals(selected, moveFeaturedProductSelection(selected, "first", -1))
        assertEquals(selected, moveFeaturedProductSelection(selected, "third", 1))
    }
}
