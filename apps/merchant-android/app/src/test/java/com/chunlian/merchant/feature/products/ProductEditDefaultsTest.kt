package com.chunlian.merchant.feature.products

import com.chunlian.merchant.data.model.GoodsCreateRequest
import org.junit.Assert.assertFalse
import org.junit.Test

class ProductEditDefaultsTest {

    @Test
    fun newProductsStayOffSaleUntilMerchantEnablesThem() {
        assertFalse(ProductEditViewModel.UiState().isShelves)
        assertFalse(GoodsCreateRequest(title = "商品", priceFen = 100, inventory = 1).isShelves)
    }
}
