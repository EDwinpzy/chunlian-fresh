package com.chunlian.merchant.feature.products

import com.chunlian.merchant.data.model.Category
import com.chunlian.merchant.data.model.Goods
import org.junit.Assert.assertEquals
import org.junit.Test

class ProductListPresentationTest {
    @Test
    fun categorySelectionIncludesProductsAssignedToChildCategories() {
        val categories = listOf(
            Category(
                id = "fresh",
                name = "生鲜",
                children = listOf(
                    Category(id = "leaf", parentId = "fresh", name = "叶菜"),
                    Category(
                        id = "root",
                        parentId = "fresh",
                        name = "根茎",
                        children = listOf(Category(id = "potato", parentId = "root", name = "薯类")),
                    ),
                ),
            ),
        )

        assertEquals(setOf("fresh", "leaf", "root", "potato"), productCategorySubtreeIds(categories, "fresh"))
        assertEquals(listOf("fresh"), productCategoryRoots(categories).map { it.id })
    }

    @Test
    fun productFiltersSeparateAllOnSaleAndOffSaleWithinSelectedCategory() {
        val goods = listOf(
            Goods(id = "on-sale", categoryId = "leaf", isShelves = true),
            Goods(id = "off-sale", categoryId = "leaf", isShelves = false),
            Goods(id = "other-category", categoryId = "other", isShelves = true),
        )
        val selectedCategory = setOf("fresh", "leaf")

        assertEquals(
            listOf("on-sale", "off-sale"),
            filterGoodsForProductList(goods, selectedCategory, ProductListViewModel.Filter.All).map { it.id },
        )
        assertEquals(
            listOf("on-sale"),
            filterGoodsForProductList(goods, selectedCategory, ProductListViewModel.Filter.OnSale).map { it.id },
        )
        assertEquals(
            listOf("off-sale"),
            filterGoodsForProductList(goods, selectedCategory, ProductListViewModel.Filter.OffSale).map { it.id },
        )
    }
}
