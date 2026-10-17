package com.chunlian.customer.ui

import androidx.annotation.DrawableRes
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.dp
import com.chunlian.customer.R
import com.chunlian.customer.ui.theme.CustomerColors

@DrawableRes
internal fun customerCategoryArtwork(name: String): Int {
    val category = name.replace(" ", "")
    return when {
        listOf("肉", "禽").any(category::contains) -> R.drawable.cust_cat_meat
        listOf("蛋豆", "蛋奶", "鸡蛋", "牛奶", "豆类", "坚果").any(category::contains) -> R.drawable.cust_cat_egg_dairy
        listOf("水产", "海鲜", "鱼", "虾").any(category::contains) -> R.drawable.cust_cat_seafood
        listOf("净菜", "半成品", "预制").any(category::contains) -> R.drawable.cust_cat_prepared
        listOf("瓜果", "水果", "果蔬").any(category::contains) -> R.drawable.cust_cat_fruit
        listOf("根茎", "根菜", "薯类", "山药").any(category::contains) -> R.drawable.cust_cat_roots
        listOf("菌菇", "蘑菇", "食用菌").any(category::contains) -> R.drawable.cust_cat_mushrooms
        listOf("调味", "配菜", "香料").any(category::contains) -> R.drawable.cust_cat_seasoning
        listOf("粮油", "主食", "谷物", "粮食", "杂粮").any(category::contains) -> R.drawable.cust_cat_grain_oil
        listOf("特惠", "促销", "优惠").any(category::contains) -> R.drawable.cust_cat_sale
        listOf("叶菜", "蔬菜", "时蔬", "青菜").any(category::contains) -> R.drawable.cust_cat_vegetables
        else -> R.drawable.cust_cat_fresh
    }
}

@Composable
internal fun CustomerCategoryArtwork(
    name: String,
    iconUrl: String,
    modifier: Modifier = Modifier,
) {
    val fallbackResource = customerCategoryArtwork(name)
    Box(
        modifier = modifier.background(CustomerColors.Brand100, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        if (iconUrl.isBlank()) {
            CustomerAssetIcon(fallbackResource, contentDescription = name, size = 31.dp)
        } else {
            CustomerImage(
                iconUrl,
                contentDescription = name,
                modifier = Modifier.fillMaxSize().padding(10.dp),
                contentScale = ContentScale.Fit,
                fallbackResource = fallbackResource,
                showRetry = false,
            )
        }
    }
}

@Composable
internal fun CustomerFreshProduceIllustration(modifier: Modifier = Modifier) {
    Image(
        painter = painterResource(R.drawable.cust_product_fallback),
        contentDescription = null,
        modifier = modifier,
        contentScale = ContentScale.Fit,
    )
}
