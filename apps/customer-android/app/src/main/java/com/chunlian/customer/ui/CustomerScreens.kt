package com.chunlian.customer.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.ui.graphics.Brush
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.IconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.chunlian.customer.data.CartItem
import com.chunlian.customer.data.CustomerTab
import com.chunlian.customer.data.CustomerUiState
import com.chunlian.customer.data.CustomerViewModel
import com.chunlian.customer.data.GoodsDetail
import com.chunlian.customer.data.GoodsSummary
import com.chunlian.customer.data.isValidMainlandMobile
import com.chunlian.customer.data.Order
import com.chunlian.customer.data.purchaseQuantityBounds
import com.chunlian.customer.data.Sku
import com.chunlian.customer.R
import com.chunlian.customer.ui.theme.CustomerColors
import com.chunlian.customer.ui.theme.E
import com.chunlian.customer.ui.theme.T
import com.chunlian.customer.ui.theme.dim

@Composable
fun HomeScreen(
    state: CustomerUiState,
    vm: CustomerViewModel,
    onCategory: (String?) -> Unit,
    onSearch: (String) -> Unit,
    onProduct: () -> Unit,
) {
    var draft by rememberSaveable { mutableStateOf(state.keyword) }
    LaunchedEffect(state.keyword) { draft = state.keyword }
    val featuredImage = state.products.firstOrNull { it.imageUrl.isNotBlank() }?.imageUrl.orEmpty()

    LazyColumn(
        modifier = Modifier.fillMaxSize().background(CustomerColors.Canvas),
        contentPadding = PaddingValues(bottom = 20.dp),
        verticalArrangement = Arrangement.spacedBy(14.dp),
    ) {
        item { StorefrontHeader(state) }
        item {
            CustomerSearchField(
                value = draft,
                onValueChange = { draft = it; vm.setSearch(it) },
                placeholder = "搜索店内商品",
                onSearch = { onSearch(draft) },
                modifier = Modifier.padding(horizontal = dim.pagePadding),
            )
        }
        item {
            HomeHeroCard(
                title = state.shop.homeBannerTitle.ifBlank { "把新鲜，带回家" },
                subtitle = state.shop.homeBannerSubtitle.ifBlank {
                    state.shop.shopSlogan.ifBlank { "附近好菜 · 每日鲜选" }
                },
                imageUrl = featuredImage,
                onClick = { onCategory(null) },
                modifier = Modifier.padding(horizontal = dim.pagePadding),
            )
        }
        if (state.categories.isNotEmpty()) {
            item {
                SectionTitle(
                    text = "按分类找好菜",
                    modifier = Modifier.padding(horizontal = dim.pagePadding),
                    trailing = { TextButton(onClick = { onCategory(null) }) { Text("全部分类") } },
                )
            }
            item {
                LazyRow(
                    contentPadding = PaddingValues(horizontal = dim.pagePadding),
                    horizontalArrangement = Arrangement.spacedBy(10.dp),
                ) {
                    items(state.categories) { category ->
                        HomeCategoryShortcut(category.name, category.iconUrl) { onCategory(category.id) }
                    }
                }
            }
        }
        item {
            SectionTitle(
                text = state.shop.homeSectionTitle.ifBlank { "今日鲜选" },
                modifier = Modifier.padding(horizontal = dim.pagePadding),
                trailing = { TextButton(onClick = { onCategory(null) }) { Text("更多商品") } },
            )
        }
        if (state.loading && state.products.isEmpty()) {
            item { GoodsGridSkeleton(rows = 1, modifier = Modifier.padding(horizontal = dim.pagePadding)) }
        } else if (state.products.isEmpty()) {
            item {
                EmptyState(
                    if (state.keyword.isBlank()) "暂时没有上架商品" else "没有找到相关商品",
                    "试试换个关键词，或去分类看看",
                )
            }
        } else {
            items(state.products.take(4).chunked(2)) { row ->
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = dim.pagePadding),
                    horizontalArrangement = Arrangement.spacedBy(dim.gap),
                ) {
                    row.forEach { goods ->
                        ProductCard(goods, Modifier.weight(1f), onClick = { vm.openProduct(goods.id); onProduct() })
                    }
                    if (row.size == 1) Spacer(Modifier.weight(1f))
                }
            }
        }
        if (state.shop.notice.isNotBlank()) {
            item {
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = dim.pagePadding)
                        .background(CustomerColors.Gold50, RoundedCornerShape(dim.radiusMd))
                        .padding(horizontal = 12.dp, vertical = 10.dp),
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text("公告", color = CustomerColors.Gold700, fontSize = T.mini, fontWeight = FontWeight.Bold)
                    Text(
                        state.shop.notice,
                        modifier = Modifier.padding(start = 8.dp),
                        color = CustomerColors.TextSecondary,
                        fontSize = T.caption,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }
        if (state.shop.searchHotWords.isNotEmpty()) {
            item {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    SectionTitle("大家都在搜", Modifier.padding(horizontal = dim.pagePadding))
                    LazyRow(
                        contentPadding = PaddingValues(horizontal = dim.pagePadding),
                        horizontalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        items(state.shop.searchHotWords.distinct().take(6)) { word ->
                            SelectionChip(word, selected = state.keyword == word, onClick = {
                                draft = word
                                onSearch(word)
                            })
                        }
                    }
                }
            }
        }
    }
}

@Composable
fun CategoryScreen(
    state: CustomerUiState,
    vm: CustomerViewModel,
    onProduct: () -> Unit,
) {
    var draft by rememberSaveable { mutableStateOf(state.keyword) }
    LaunchedEffect(state.keyword) { draft = state.keyword }
    val activeCategory = state.categories.firstOrNull { category ->
        category.id == state.categoryId || category.children.any { it.id == state.categoryId }
    }
    val activeChildName = activeCategory?.children?.firstOrNull { it.id == state.categoryId }?.name

    Column(Modifier.fillMaxSize().background(CustomerColors.Canvas)) {
        Text(
            "分类选购",
            modifier = Modifier.padding(start = dim.pagePadding, end = dim.pagePadding, top = 14.dp, bottom = 10.dp),
            color = CustomerColors.TextPrimary,
            fontSize = T.headline,
            fontWeight = FontWeight.Bold,
        )
        CustomerSearchField(
            value = draft,
            onValueChange = { draft = it; vm.setSearch(it) },
            placeholder = "搜索店内商品",
            onSearch = { vm.loadCatalog(keyword = draft) },
            modifier = Modifier.padding(horizontal = dim.pagePadding, vertical = 4.dp),
        )
        Row(Modifier.fillMaxSize().padding(top = 10.dp)) {
            LazyColumn(
                modifier = Modifier.width(88.dp).fillMaxHeight().background(CustomerColors.Neutral100),
                contentPadding = PaddingValues(bottom = 20.dp),
            ) {
                item {
                    CategoryRailItem(
                        "全部",
                        iconResource = customerCategoryArtwork("全部"),
                        selected = state.categoryId == null,
                    ) { vm.setCategory(null) }
                }
                items(state.categories) { category ->
                    CategoryRailItem(
                        category.name,
                        iconResource = customerCategoryArtwork(category.name),
                        selected = activeCategory?.id == category.id,
                    ) { vm.setCategory(category.id) }
                }
            }
            LazyColumn(
                modifier = Modifier.weight(1f).fillMaxHeight(),
                contentPadding = PaddingValues(start = 12.dp, end = dim.pagePadding, bottom = 18.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item {
                    Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Text(
                            activeChildName ?: activeCategory?.name ?: "全部商品",
                            color = CustomerColors.TextPrimary,
                            fontSize = T.product,
                            fontWeight = FontWeight.Bold,
                            modifier = Modifier.padding(top = 4.dp),
                        )
                        if (activeCategory?.children?.isNotEmpty() == true) {
                            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                item {
                                    SelectionChip(
                                        "全部${activeCategory.name}",
                                        selected = state.categoryId == activeCategory.id,
                                        onClick = { vm.setCategory(activeCategory.id) },
                                    )
                                }
                                items(activeCategory.children) { child ->
                                    SelectionChip(
                                        child.name,
                                        selected = state.categoryId == child.id,
                                        onClick = { vm.setCategory(child.id) },
                                    )
                                }
                            }
                        }
                    }
                }
                if (state.loading && state.products.isEmpty()) {
                    item { GoodsGridSkeleton(rows = 2) }
                } else if (state.products.isEmpty()) {
                    item {
                        EmptyState(
                            if (state.keyword.isBlank()) "这个分类暂时没有商品" else "没有找到相关商品",
                            "换个分类看看，或调整搜索词",
                        )
                    }
                } else {
                    items(state.products.chunked(2)) { row ->
                        Row(horizontalArrangement = Arrangement.spacedBy(dim.gap)) {
                            row.forEach { goods ->
                                ProductCard(goods, Modifier.weight(1f), onClick = { vm.openProduct(goods.id); onProduct() })
                            }
                            if (row.size == 1) Spacer(Modifier.weight(1f))
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun StorefrontHeader(state: CustomerUiState) {
    Row(
        Modifier.fillMaxWidth().padding(start = dim.pagePadding, end = dim.pagePadding, top = 10.dp, bottom = 0.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        if (state.shop.shopLogoUrl.isBlank()) {
            CustomerAssetIcon(R.drawable.cust_category, contentDescription = "春连鲜选", modifier = Modifier.padding(end = 8.dp), size = 34.dp)
        } else {
            CustomerImage(
                state.shop.shopLogoUrl,
                contentDescription = "店铺图标",
                modifier = Modifier.padding(end = 8.dp).size(34.dp).clip(RoundedCornerShape(dim.radiusSm)),
                cacheEnabled = false,
            )
        }
        Column(Modifier.weight(1f)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    state.shop.shopName.ifBlank { "春连鲜选" },
                    fontSize = T.product,
                    fontWeight = FontWeight.Bold,
                    color = CustomerColors.Brand700,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Spacer(Modifier.width(6.dp))
                StatusChip(
                    text = if (state.shop.isOpen) "营业中" else "休息中",
                    tone = if (state.shop.isOpen) ChipTone.Success else ChipTone.Warning,
                )
            }
            Text(
                state.shop.shopAddress.ifBlank { "附近好菜 · 今日鲜选" },
                color = CustomerColors.TextSecondary,
                fontSize = T.mini,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
    }
}

@Composable
private fun HomeHeroCard(
    title: String,
    subtitle: String,
    imageUrl: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Box(
        modifier.fillMaxWidth()
            .clip(RoundedCornerShape(22.dp))
            .background(Brush.linearGradient(listOf(CustomerColors.Brand700, CustomerColors.Brand500)))
            .clickable(onClick = onClick)
            .padding(18.dp),
    ) {
        Box(
            Modifier.align(Alignment.CenterEnd).padding(end = 2.dp).size(142.dp)
                .background(CustomerColors.OnBrandTranslucent, CircleShape),
        )
        Box(
            Modifier.align(Alignment.CenterEnd).padding(end = 14.dp).size(118.dp)
                .background(CustomerColors.OnBrand.copy(alpha = .15f), CircleShape),
        )
        if (imageUrl.isNotBlank()) {
            ProductImage(
                imageUrl,
                Modifier.align(Alignment.CenterEnd).padding(end = 24.dp).size(98.dp)
                    .clip(CircleShape),
            )
        } else {
            Box(
                Modifier.align(Alignment.CenterEnd).padding(end = 24.dp).size(98.dp)
                    .background(CustomerColors.Brand100, CircleShape),
                contentAlignment = Alignment.Center,
            ) {
                CustomerFreshProduceIllustration(Modifier.size(84.dp))
            }
        }
        Column(Modifier.align(Alignment.CenterStart).fillMaxWidth(.65f)) {
            Text("每日鲜选", color = CustomerColors.Gold200, fontSize = T.mini, fontWeight = FontWeight.SemiBold)
            Text(
                title,
                modifier = Modifier.padding(top = 6.dp),
                color = CustomerColors.OnBrand,
                fontSize = T.section,
                lineHeight = 24.sp,
                fontWeight = FontWeight.Bold,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                subtitle,
                modifier = Modifier.padding(top = 4.dp),
                color = CustomerColors.OnBrandMuted,
                fontSize = T.mini,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                "去挑好菜  ›",
                modifier = Modifier.padding(top = 8.dp).background(CustomerColors.OnBrand, RoundedCornerShape(dim.radiusPill))
                    .padding(horizontal = 11.dp, vertical = 5.dp),
                color = CustomerColors.Brand700,
                fontSize = T.mini,
                fontWeight = FontWeight.SemiBold,
            )
        }
    }
}

@Composable
private fun HomeCategoryShortcut(name: String, iconUrl: String, onClick: () -> Unit) {
    Column(
        modifier = Modifier.width(68.dp).clickable(onClick = onClick).padding(vertical = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(6.dp),
    ) {
        CustomerCategoryArtwork(name, iconUrl, Modifier.size(54.dp))
        Text(name, color = CustomerColors.TextSecondary, fontSize = T.mini, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

@Composable
private fun CategoryRailItem(
    name: String,
    @androidx.annotation.DrawableRes iconResource: Int,
    selected: Boolean,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth()
            .background(if (selected) CustomerColors.Surface else CustomerColors.Neutral100)
            .clickable(onClick = onClick)
            .padding(horizontal = 5.dp, vertical = 13.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.Center,
    ) {
        if (selected) {
            Box(Modifier.width(3.dp).height(22.dp).background(CustomerColors.Brand600, RoundedCornerShape(dim.radiusPill)))
            Spacer(Modifier.width(5.dp))
        }
        CustomerAssetIcon(iconResource, contentDescription = null, size = 16.dp)
        Spacer(Modifier.width(3.dp))
        Text(
            name,
            color = if (selected) CustomerColors.Brand700 else CustomerColors.TextSecondary,
            fontSize = T.mini,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/**
 * 商品卡（规范 §7.3 card--product）。
 *
 * 【为什么图片是 1:1 而不是固定 132dp 高】
 * 固定高度在不同屏宽下会变成不同的长宽比，同一批商品在窄屏上显得被压扁。
 * 1:1 让所有机型的商品图比例一致，也让双列网格的基线永远对齐。
 *
 * 【为什么右下角是「去选购」而不是加购圆钮】
 * 本店商品有多 SKU，必须先进详情页选规格才能加购。
 * 列表页放一个直接加购的按钮会跳过规格选择，顾客拿到的是错的商品。
 */
@Composable
private fun ProductCard(goods: GoodsSummary, modifier: Modifier = Modifier, onClick: () -> Unit) {
    CardBox(
        modifier = modifier.clickable(onClick = onClick),
        elevation = E.level1,
        bordered = false,
    ) {
        Column(Modifier.padding(8.dp)) {
            ProductImage(
                goods.imageUrl,
                Modifier.fillMaxWidth().aspectRatio(1f).clip(RoundedCornerShape(dim.radiusMd)),
            )
            Spacer(Modifier.height(8.dp))
            Text(
                goods.title,
                fontSize = T.product,
                fontWeight = FontWeight.SemiBold,
                color = CustomerColors.TextPrimary,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                goods.simpleDesc.ifBlank { goods.specDesc },
                fontSize = T.mini,
                color = CustomerColors.TextMuted,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.padding(top = 2.dp),
            )
            Spacer(Modifier.height(8.dp))
            Row(
                modifier = Modifier.fillMaxWidth(),
                verticalAlignment = Alignment.Bottom,
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Column {
                    PriceText(
                        fen = goods.minPriceFen,
                        size = T.headline,
                        unit = if (goods.maxPriceFen > goods.minPriceFen) "起" else null,
                    )
                    Text(
                        "${goods.inventoryUnit} · 已售 ${goods.salesCount}",
                        fontSize = T.mini,
                        color = CustomerColors.TextMuted,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                }

                if (goods.isOutOfStock) {
                    StatusChip("已售罄", ChipTone.Muted)
                } else {
                    Box(
                        Modifier
                            .clip(RoundedCornerShape(dim.radiusPill))
                            .background(CustomerColors.Brand100)
                            .clickable(onClick = onClick)
                            .padding(horizontal = 10.dp, vertical = 5.dp),
                    ) {
                        Text(
                            "去选购",
                            color = CustomerColors.Brand700,
                            fontSize = T.mini,
                            fontWeight = FontWeight.SemiBold,
                        )
                    }
                }
            }
        }
    }
}

@Composable
fun ProductScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit) {
    val product = state.product
    var productShown by remember { mutableStateOf(false) }
    LaunchedEffect(product?.id) {
        if (product != null) productShown = true
        else if (productShown) onBack()
    }
    var selectedSkuId by rememberSaveable(product?.id) { mutableStateOf("") }
    var count by rememberSaveable(product?.id) { mutableIntStateOf(1) }
    LaunchedEffect(product?.id) { selectedSkuId = product?.skus?.firstOrNull()?.skuId.orEmpty(); count = (product?.buyMinNumber ?: 1).coerceAtLeast(1) }
    ScreenFrame(title = "商品详情", onBack = onBack) { padding ->
        Column(Modifier.fillMaxSize().padding(padding)) {
            if (product == null) {
                Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) { CircularProgressIndicator(color = CustomerColors.Brand700) }
            } else {
                val sku = product.skus.firstOrNull { it.skuId == selectedSkuId } ?: product.skus.firstOrNull()
                val quantityBounds = purchaseQuantityBounds(product.buyMinNumber, product.buyMaxNumber, sku?.inventory ?: 0)
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
                    ProductImage(product.images.firstOrNull().takeUnless { it.isNullOrBlank() } ?: product.imageUrl, Modifier.fillMaxWidth().height(290.dp))
                    Column(Modifier.padding(20.dp)) {
                        Text(product.title, style = MaterialTheme.typography.titleLarge)
                        Text(product.simpleDesc.ifBlank { product.content }, color = CustomerColors.TextSecondary, modifier = Modifier.padding(top = 6.dp))
                        Spacer(Modifier.height(12.dp))
                        // 详情页价格用 title 号（24）+ 等宽数字：这是全页最重要的信息，
                        // 也是顾客决定买不买的唯一依据，字号和字重都要给足。
                        Text(
                            sku?.let { money(it.priceFen) } ?: money(product.minPriceFen),
                            style = tabularStyle(T.title, FontWeight.Bold, CustomerColors.Price),
                        )
                        if (product.skus.isNotEmpty()) {
                            Text("选择规格", fontWeight = FontWeight.SemiBold, modifier = Modifier.padding(top = 20.dp, bottom = 8.dp))
                            androidx.compose.foundation.lazy.LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                                items(product.skus) { option ->
                                    SelectionChip(option.specText.ifBlank { "默认规格" }, selected = option.skuId == (sku?.skuId ?: ""), enabled = !option.isOutOfStock, onClick = {
                                        selectedSkuId = option.skuId
                                        val bounds = purchaseQuantityBounds(product.buyMinNumber, product.buyMaxNumber, option.inventory)
                                        count = if (bounds.canPurchase) count.coerceIn(bounds.minimum, bounds.maximum) else bounds.minimum
                                    })
                                }
                            }
                        }
                        Text(
                            listOfNotNull(product.origin.takeIf(String::isNotBlank), "库存 ${sku?.inventory ?: product.inventory}").joinToString(" · "),
                            color = CustomerColors.TextSecondary,
                            fontSize = T.body,
                            modifier = Modifier.padding(top = 14.dp),
                        )
                        if (product.content.isNotBlank()) Text(product.content, color = CustomerColors.TextPrimary, lineHeight = 23.sp, modifier = Modifier.padding(top = 18.dp))
                        Spacer(Modifier.height(20.dp))
                    }
                }
                Row(Modifier.fillMaxWidth().background(CustomerColors.Surface).padding(horizontal = 18.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                    QuantityControl(
                        count,
                        onMinus = { count = (count - 1).coerceAtLeast(quantityBounds.minimum) },
                        onPlus = { count = (count + 1).coerceAtMost(quantityBounds.maximum) },
                        max = quantityBounds.maximum,
                    )
                    Spacer(Modifier.width(16.dp))
                    Button(
                        onClick = {
                            val added = sku?.let { vm.addToCart(product, it, count) } == true
                            if (added) onBack()
                        },
                        enabled = sku != null && quantityBounds.canPurchase && !sku.isOutOfStock && !state.busy,
                        modifier = Modifier.weight(1f).height(48.dp), shape = RoundedCornerShape(dim.radiusLg),
                        colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700),
                    ) { Text(if (sku?.isOutOfStock == true) "暂时售罄" else "加入购物车") }
                }
            }
        }
    }
}

@Composable
fun CartScreen(state: CustomerUiState, vm: CustomerViewModel, onCheckout: () -> Unit) {
    Column(Modifier.fillMaxSize().background(CustomerColors.Canvas)) {
        Text("购物车", style = MaterialTheme.typography.titleLarge, modifier = Modifier.padding(20.dp))
        if (state.cart.isEmpty()) {
            EmptyState("购物车还是空的", "去挑几样今天的新鲜", R.drawable.cust_empty_basket)
        } else {
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(horizontal = 16.dp, vertical = 6.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                items(state.cart, key = { it.skuId }) { item -> CartRow(item, vm) }
            }
            val total = state.cart.sumOf { it.unitFen * it.qty }
            Row(Modifier.fillMaxWidth().background(CustomerColors.Surface).padding(horizontal = 18.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("合计", color = CustomerColors.TextSecondary, fontSize = T.caption)
                    Text(money(total), color = CustomerColors.Brand700, fontWeight = FontWeight.Bold, fontSize = T.headline)
                }
                Button(onClick = onCheckout, shape = RoundedCornerShape(dim.radiusMd), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700)) { Text("去结算") }
            }
        }
    }
}

@Composable
private fun CartRow(item: CartItem, vm: CustomerViewModel) {
    CardBox {
        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            ProductImage(item.imageUrl, Modifier.size(82.dp).clip(RoundedCornerShape(dim.radiusSm)))
            Column(Modifier.weight(1f).padding(horizontal = 12.dp)) {
                Text(item.name, fontWeight = FontWeight.SemiBold, maxLines = 1, overflow = TextOverflow.Ellipsis)
                Text(item.spec, color = CustomerColors.TextSecondary, fontSize = T.caption, modifier = Modifier.padding(top = 4.dp))
                PriceText(item.unitFen, modifier = Modifier.padding(top = 8.dp), size = T.product)
            }
            QuantityControl(
                item.qty,
                onMinus = { vm.changeCart(item.skuId, -1) },
                onPlus = { vm.changeCart(item.skuId, 1) },
                max = minOf(item.inventory, item.maximumQty),
            )
        }
    }
}

@Composable
fun OrdersScreen(
    state: CustomerUiState,
    onOrder: (String) -> Unit,
    onLogin: () -> Unit,
    onKeywordChange: (String) -> Unit,
) {
    Column(Modifier.fillMaxSize().background(CustomerColors.Canvas)) {
        OrdersHeader(orderCount = state.orders.size.takeIf { state.user != null })
        if (state.user == null) {
            EmptyState("登录后查看订单", "订单进度与收货信息都在这里")
            Button(onClick = onLogin, modifier = Modifier.fillMaxWidth().padding(horizontal = 28.dp), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700)) { Text("登录 / 注册") }
        } else {
            CustomerSearchField(
                value = state.ordersKeyword,
                onValueChange = onKeywordChange,
                placeholder = "搜索订单号或商品名称",
                modifier = Modifier.padding(horizontal = dim.pagePadding),
                showSearchButton = false,
                imeAction = ImeAction.Done,
            )
            Spacer(Modifier.height(12.dp))
            if (state.orders.isEmpty()) {
                if (state.ordersLoading) {
                    Box(Modifier.fillMaxWidth().padding(top = 36.dp), contentAlignment = Alignment.Center) {
                        CircularProgressIndicator(color = CustomerColors.Brand600, strokeWidth = 2.dp)
                    }
                } else if (state.ordersKeyword.isNotBlank()) {
                    EmptyState("没有找到相关订单", "试试订单号或商品名称")
                } else {
                    EmptyState("还没有订单", "买菜后可在这里查看配送进度")
                }
            } else {
                LazyColumn(contentPadding = PaddingValues(start = dim.pagePadding, top = 4.dp, end = dim.pagePadding, bottom = 16.dp), verticalArrangement = Arrangement.spacedBy(dim.gap)) {
                    items(state.orders, key = { it.id }) { order ->
                        OrderSummaryCard(order, onClick = { onOrder(order.id) })
                    }
                }
            }
        }
    }
}

@Composable
private fun OrdersHeader(orderCount: Int?) {
    Row(
        Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, top = 18.dp, bottom = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Column(Modifier.weight(1f)) {
            Text("我的订单", fontSize = T.title, fontWeight = FontWeight.Bold, color = CustomerColors.Brand700)
            Text("配送进度与收货信息，随时查看", color = CustomerColors.TextSecondary, fontSize = T.body, modifier = Modifier.padding(top = 4.dp))
        }
        if (orderCount != null) {
            Column(
                Modifier.background(CustomerColors.Brand100, RoundedCornerShape(dim.radiusMd)).padding(horizontal = 14.dp, vertical = 8.dp),
                horizontalAlignment = Alignment.CenterHorizontally,
            ) {
                Text("$orderCount", color = CustomerColors.Brand700, fontSize = T.section, fontWeight = FontWeight.Bold)
                Text("笔订单", color = CustomerColors.Brand600, fontSize = T.mini)
            }
        }
    }
}

@Composable
private fun OrderSummaryCard(order: Order, onClick: () -> Unit) {
    CardBox(modifier = Modifier.clickable(onClick = onClick)) {
        Column(Modifier.padding(dim.cardPadding)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("订单编号", color = CustomerColors.TextMuted, fontSize = T.mini)
                    Text(
                        order.orderNo,
                        color = CustomerColors.Brand700,
                        fontSize = T.body,
                        fontWeight = FontWeight.SemiBold,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.padding(top = 2.dp),
                    )
                }
                StatusChip(order.fulfillmentText, ChipTone.Info)
            }
            Text(
                order.lines.joinToString("、") { "${it.name} ×${it.qty}" }.ifBlank { "查看订单商品" },
                color = CustomerColors.TextPrimary,
                fontSize = T.product,
                fontWeight = FontWeight.Medium,
                maxLines = 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 14.dp),
            )
            DividerLine()
            Row(
                Modifier.fillMaxWidth().padding(top = 12.dp),
                verticalAlignment = Alignment.Bottom,
            ) {
                Column(Modifier.weight(1f)) {
                    Text("${order.paymentMethodText} · ${order.paymentStateText}", color = CustomerColors.TextSecondary, fontSize = T.caption, maxLines = 2, overflow = TextOverflow.Ellipsis)
                    Text("查看订单详情  ›", color = CustomerColors.Brand600, fontSize = T.mini, fontWeight = FontWeight.Medium, modifier = Modifier.padding(top = 6.dp))
                }
                Column(horizontalAlignment = Alignment.End) {
                    Text("订单金额", color = CustomerColors.TextMuted, fontSize = T.mini, modifier = Modifier.padding(bottom = 2.dp))
                    PriceText(order.totalFen, size = T.headline)
                }
            }
        }
    }
}

@Composable
fun LoginScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit, onSuccess: () -> Unit) {
    var phone by rememberSaveable { mutableStateOf("") }
    var password by rememberSaveable { mutableStateOf("") }
    var nickname by rememberSaveable { mutableStateOf("") }
    var register by rememberSaveable { mutableStateOf(false) }
    LaunchedEffect(state.user?.userId) { if (state.user != null) onSuccess() }
    ScreenFrame("登录 / 注册", onBack) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(24.dp), verticalArrangement = Arrangement.spacedBy(14.dp)) {
            Text("欢迎回来", fontSize = T.title, fontWeight = FontWeight.Bold, color = CustomerColors.Brand700)
            Text("手机号登录后，可下单并管理收货地址", color = CustomerColors.TextSecondary)
            if (!vm.identityTransportAllowed) InfoBanner("暂不能登录", "当前服务器只有 HTTP。请先配置 HTTPS，再开放手机号和密码登录。", warning = true)
            else if (vm.loopbackTestTransport) InfoBanner("仅限本机 QA", "此未加密测试连接只允许使用合成测试账号；请勿输入真实手机号或密码。", warning = true)
            if (register) OutlinedTextField(nickname, { nickname = it }, label = { Text("怎么称呼你") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
            OutlinedTextField(phone, { phone = it }, label = { Text("手机号") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
            OutlinedTextField(password, { password = it }, label = { Text("密码") }, modifier = Modifier.fillMaxWidth(), singleLine = true, visualTransformation = PasswordVisualTransformation(), keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password))
            Button(
                onClick = { vm.login(phone, password, nickname.takeIf { register }) }, enabled = vm.identityTransportAllowed && !state.busy && isValidMainlandMobile(phone) && password.length in 6..32 && (!register || nickname.isNotBlank()),
                modifier = Modifier.fillMaxWidth().height(50.dp), shape = RoundedCornerShape(dim.radiusMd), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700),
            ) { Text(if (register) "创建账号" else "登录") }
            TextButton(onClick = { register = !register }) { Text(if (register) "已有账号？返回登录" else "还没有账号？立即注册", color = CustomerColors.Brand700) }
        }
    }
}

/**
 * 页面骨架（规范 §5）。
 *
 * 标题栏白底 + 深绿标题 + 底部 1px 分隔线，正文区是奶油白 Canvas。
 * 【为什么不用 Material 默认的 surfaceVariant 标题栏】
 * Material 3 默认会给标题栏一个偏灰的容器色，在奶油白背景上会形成
 * 一条看不出意图的灰带；这里显式指定，让「白条 + 细线」的层级成立。
 */
@Composable
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
fun ScreenFrame(title: String, onBack: () -> Unit, content: @Composable (PaddingValues) -> Unit) {
    Scaffold(
        containerColor = CustomerColors.Canvas,
        topBar = {
            Column {
                TopAppBar(
                    title = { Text(title, fontSize = T.section, fontWeight = FontWeight.SemiBold) },
                    navigationIcon = {
                        IconButton(onClick = onBack) {
                            Icon(
                                imageVector = Icons.AutoMirrored.Filled.ArrowBack,
                                contentDescription = "返回",
                                tint = CustomerColors.Brand700,
                            )
                        }
                    },
                    colors = androidx.compose.material3.TopAppBarDefaults.topAppBarColors(
                        containerColor = CustomerColors.Surface,
                        titleContentColor = CustomerColors.Brand700,
                    ),
                )
                DividerLine()
            }
        },
        content = content,
    )
}

@Composable
fun ProductImage(url: String, modifier: Modifier = Modifier) {
    CustomerImage(
        url,
        contentDescription = null,
        modifier = modifier.background(CustomerColors.Brand100),
        contentScale = ContentScale.Crop,
        placeholderText = "商品图加载失败",
        fallbackResource = R.drawable.cust_product_fallback,
    )
}

@Composable
fun QuantityControl(value: Int, onMinus: () -> Unit, onPlus: () -> Unit, max: Int = 99) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Box(
            Modifier.size(40.dp).clip(CircleShape).clickable(role = Role.Button, onClick = onMinus),
            contentAlignment = Alignment.Center,
        ) {
            CustomerAssetIcon(R.drawable.cust_quantity_minus, contentDescription = "减少", size = 26.dp)
        }
        Text(value.toString(), modifier = Modifier.width(24.dp), fontWeight = FontWeight.SemiBold, textAlign = androidx.compose.ui.text.style.TextAlign.Center)
        Box(
            Modifier.size(40.dp).clip(CircleShape).clickable(enabled = value < max, role = Role.Button, onClick = onPlus),
            contentAlignment = Alignment.Center,
        ) {
            CustomerAssetIcon(R.drawable.cust_quantity_plus, contentDescription = "增加", size = 26.dp, alpha = if (value < max) 1f else .42f)
        }
    }
}

/**
 * 分类胶囊（规范 §7.1 的 pill 形态）。
 *
 * 选中态用实心深绿 + 白字，而不是淡绿底 —— 淡绿底在奶油白背景上
 * 几乎看不出选中，顾客会反复点两次确认「到底选了哪个」。
 */
@Composable
fun SelectionChip(text: String, selected: Boolean, enabled: Boolean = true, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .alpha(if (enabled) 1f else .45f)
            .background(
                if (selected) CustomerColors.Brand700 else CustomerColors.Neutral100,
                RoundedCornerShape(dim.radiusPill),
            )
            .clickable(enabled = enabled, onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 7.dp),
    ) {
        Text(
            text,
            color = if (selected) CustomerColors.OnBrand else CustomerColors.TextSecondary,
            fontSize = T.caption,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            maxLines = 1,
        )
    }
}

@Composable
fun EmptyState(title: String, subtitle: String, iconResource: Int? = null) {
    Column(
        Modifier.fillMaxWidth().padding(horizontal = dim.sectionGap, vertical = 48.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        if (iconResource != null) {
            CustomerAssetIcon(iconResource, contentDescription = null, modifier = Modifier.padding(bottom = 12.dp), size = 48.dp)
        }
        Text(title, fontSize = T.product, fontWeight = FontWeight.SemiBold, color = CustomerColors.TextPrimary)
        Text(
            subtitle,
            color = CustomerColors.TextSecondary,
            fontSize = T.caption,
            textAlign = androidx.compose.ui.text.style.TextAlign.Center,
            modifier = Modifier.padding(top = 6.dp),
        )
    }
}

/**
 * 提示条（规范 §7.5）。
 *
 * 用语义色的浅底 + 语义色文字，而不是写死的 `#FFF3E2` ——
 * 写死色值会在换主题时漏掉这一处。
 */
@Composable
fun InfoBanner(title: String, message: String, warning: Boolean = false) {
    val tone = if (warning) ChipTone.Warning else ChipTone.Info
    val (fg, bg) = chipColors(tone)

    Box(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = dim.pagePadding, vertical = 8.dp)
            .background(bg, RoundedCornerShape(dim.radiusSm))
            .padding(14.dp),
    ) {
        Column {
            Text(title, color = fg, fontSize = T.product, fontWeight = FontWeight.SemiBold)
            Text(
                message,
                color = CustomerColors.TextSecondary,
                fontSize = T.caption,
                lineHeight = 18.sp,
                modifier = Modifier.padding(top = 4.dp),
            )
        }
    }
}

/**
 * 整数分 → 「¥3.50」。
 *
 * 【为什么委托给 [fen2yuan] 而不是自己算】
 * 这里原本是 `"¥${fen / 100}.${(fen % 100)...}"`，对负分是错的：
 * `-50 / 100` 得 0、`-50 % 100` 得 -50，会输出「¥0.-50」。
 * 退款、差额冲减都会走到负分。格式化逻辑统一收在 fen2yuan 一处。
 */
fun money(fen: Long): String = "¥${fen2yuan(fen)}"
