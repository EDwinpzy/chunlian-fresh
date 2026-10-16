package com.chunlian.merchant.feature.settings

import com.chunlian.merchant.data.model.Category
import com.chunlian.merchant.feature.products.categoryCreationSort
import org.junit.Assert.assertEquals
import org.junit.Test

class CategoryCreationSortTest {

    @Test
    fun newCategorySortStaysWithinBackendMinimum() {
        assertEquals(0, categoryCreationSort(emptyList()))
        assertEquals(0, categoryCreationSort(listOf(Category(sort = 0))))
        assertEquals(20, categoryCreationSort(listOf(Category(sort = 30))))
        assertEquals(0, categoryCreationSort(listOf(Category(sort = -4))))
    }
}
