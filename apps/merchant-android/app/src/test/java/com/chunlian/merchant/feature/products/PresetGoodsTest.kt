package com.chunlian.merchant.feature.products

import com.chunlian.merchant.data.model.Category
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PresetGoodsTest {

    @Test
    fun parsesCatalogueAndSearchesNamesAndAliases() {
        val products = parsePresetGoods(
            """{"version":1,"products":[{"id":"greens","name":"本地小青菜","specText":"约500g/份","unit":"份","suggestedCategory":"蔬菜","aliases":["青菜","小白菜"],"imageAsset":"preset-images/P-01.jpg","unused":"ignored"}]}""",
        )

        assertEquals(1, products.size)
        assertEquals("preset-images/P-01.jpg", products.single().imageAsset)
        assertEquals("本地小青菜", searchPresetGoods(products, "  小白菜 ").single().name)
        assertEquals("greens", searchPresetGoods(products, "本地").single().id)
        assertEquals(1, searchPresetGoods(products, "").size)
        assertEquals(0, searchPresetGoods(products, "水果").size)
    }

    @Test
    fun rejectsUnsafePresetImageAssetPaths() {
        assertTrue(isSafePresetImageAssetPath("preset-images/P-01.jpg"))
        assertFalse(isSafePresetImageAssetPath("../private.jpg"))
        assertFalse(isSafePresetImageAssetPath("preset-images/../private.jpg"))
        assertFalse(isSafePresetImageAssetPath("/preset-images/P-01.jpg"))
        assertFalse(isSafePresetImageAssetPath("preset-images\\P-01.jpg"))
        assertNull(
            parsePresetGoods(
                """{"version":1,"products":[{"name":"商品","imageAsset":"../private.jpg"}]}""",
            ).single().imageAsset,
        )
    }

    @Test
    fun matchesOnlyAnExistingCategoryIgnoringCaseAndOuterWhitespace() {
        val preset = PresetGood("greens", "本地小青菜", "", "份", " 蔬菜 ", emptyList())
        val enabled = listOf(Category(id = "enabled", name = "蔬菜"))
        val disabled = listOf(Category(id = "disabled", name = "蔬菜", isEnabled = false))

        assertEquals("enabled", matchingPresetCategoryId(preset, enabled))
        assertNull(matchingPresetCategoryId(preset.copy(suggestedCategory = "水果"), enabled))
        assertNull(matchingPresetCategoryId(preset, disabled))
        assertNull(matchingPresetCategoryId(preset.copy(suggestedCategory = " "), enabled))
    }
}
