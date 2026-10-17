package com.chunlian.merchant.feature.products

import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.assertNull
import org.junit.Test

class PresetCategoriesTest {

    @Test
    fun bundledCategoryTemplatesHaveImagesAndCanBeLoaded() {
        val assets = File("src/main/assets")
        val categories = parsePresetCategories(File(assets, "preset-categories.json").readText())

        assertEquals(13, categories.size)
        assertEquals(13, categories.map { it.name }.distinct().size)
        categories.forEach { category ->
            val imageAsset = category.imageAsset
            assertTrue("${category.name} needs an image asset", imageAsset != null)
            assertTrue("Missing image for ${category.name}: $imageAsset", File(assets, imageAsset!!).isFile)
        }
    }

    @Test
    fun parsesNamesImageAssetsAndSortOrder() {
        val categories = parsePresetCategories(
            """[
                {"id":"vegetables","name":"蔬菜","imageAsset":"preset-images/category-vegetables.jpg","sort":10},
                {"id":"fruit","name":"水果","imageAsset":"preset-images/category-fruit.jpg","sort":20}
            ]""",
        )

        assertEquals(2, categories.size)
        assertEquals("vegetables", categories[0].id)
        assertEquals("蔬菜", categories[0].name)
        assertEquals("preset-images/category-vegetables.jpg", categories[0].imageAsset)
        assertEquals(10, categories[0].sort)
    }

    @Test
    fun ignoresBlankNamesAndRejectsUnsafeImagePaths() {
        val categories = parsePresetCategories(
            """[
                {"id":"unsafe","name":"肉禽","imageAsset":"../private.jpg","sort":1},
                {"id":"blank","name":"   ","imageAsset":"preset-images/category.jpg","sort":2}
            ]""",
        )

        assertEquals(1, categories.size)
        assertEquals("肉禽", categories.single().name)
        assertNull(categories.single().imageAsset)
    }
}
