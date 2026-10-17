package com.chunlian.merchant.feature.products

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.activity.compose.BackHandler
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.ChevronRight
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material3.IconButton
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.platform.LocalContext
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import coil.compose.AsyncImage
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.ui.components.tabularStyle
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.model.Category
import com.chunlian.merchant.data.model.Goods
import com.chunlian.merchant.data.model.Sku
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.ProductListSkeleton
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.AutoRefreshEffect
import com.chunlian.merchant.ui.components.PullToRefreshLayout
import com.chunlian.merchant.ui.components.money
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/**
 * 商品管理（方案 §6.3 UI-A05）。
 *
 * 【为什么缺货和下架的商品要排在最前面】
 * 服务端已经按「下架 → 缺货 → 其余」排序。店主打开商品页的目的
 * 几乎总是「处理异常」：某个菜卖完了要补货，某个菜下架了要确认
 * 是不是误操作。把正常商品顶在最上面只会让他多滑几屏。
 */
class ProductListViewModel(private val repo: MerchantRepository) : ViewModel() {

    enum class Filter(val label: String, val onSaleOnly: Boolean?) {
        All("全部", null),
        OnSale("在售中", true),
        OffSale("已下架", null),
    }

    data class UiState(
        val loading: Boolean = true,
        val refreshing: Boolean = false,
        val error: String? = null,
        val goods: List<Goods> = emptyList(),
        val total: Int = 0,
        val categories: List<Category> = emptyList(),
        val categoryLoading: Boolean = true,
        val categoryError: String? = null,
        val categoryIds: Set<String> = emptySet(),
        val keyword: String = "",
        val filter: Filter = Filter.All,
        val busyId: String? = null,
        val nextPage: Int = 2,
        val hasMore: Boolean = false,
        val loadingMore: Boolean = false,
        val inventoryGoodsId: String? = null,
        val inventoryLoading: Boolean = false,
        val inventoryError: String? = null,
        val inventoryMessage: String? = null,
        val inventorySkus: List<Sku> = emptyList(),
        val inventoryBusySkuId: String? = null,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var requestSequence = 0
    private var inventoryRequestSequence = 0
    private var categoryRequestSequence = 0

    fun loadCategories() {
        val requestId = ++categoryRequestSequence
        _state.value = _state.value.copy(categoryLoading = true, categoryError = null)
        viewModelScope.launch {
            try {
                val categories = repo.categories()
                if (requestId != categoryRequestSequence) return@launch
                _state.value = _state.value.copy(categories = categories, categoryLoading = false, categoryError = null)
            } catch (e: Exception) {
                if (requestId != categoryRequestSequence) return@launch
                _state.value = _state.value.copy(categoryLoading = false, categoryError = e.message ?: "分类加载失败")
            }
        }
    }

    fun setKeyword(value: String) {
        _state.value = _state.value.copy(keyword = value)
    }

    fun setFilter(filter: Filter) {
        if (_state.value.filter == filter) return
        _state.value = _state.value.copy(filter = filter, goods = emptyList(), total = 0, hasMore = false, nextPage = 1)
        load()
    }

    fun setCategoryIds(ids: Set<String>) {
        if (_state.value.categoryIds == ids && _state.value.filter == Filter.All) return
        _state.value = _state.value.copy(
            categoryIds = ids,
            filter = Filter.All,
            goods = emptyList(),
            total = 0,
            hasMore = false,
            nextPage = 1,
        )
        load()
    }

    fun load(preserveExisting: Boolean = false) {
        val current = _state.value
        val requestId = ++requestSequence
        _state.value = current.copy(
            loading = !preserveExisting || current.goods.isEmpty(),
            refreshing = preserveExisting && current.goods.isNotEmpty(),
            goods = if (preserveExisting) current.goods else emptyList(),
            total = if (preserveExisting) current.total else 0,
            nextPage = 1,
            hasMore = false,
            loadingMore = false,
            error = null,
        )

        viewModelScope.launch {
            try {
                val loaded = mutableListOf<Goods>()
                var pageNumber = 1
                var total: Int
                var hasMore: Boolean
                do {
                    val page = repo.goods(
                        keyword = current.keyword.takeIf { it.isNotBlank() },
                        onSaleOnly = current.filter.onSaleOnly,
                        page = pageNumber,
                        pageSize = 100,
                    )
                    if (requestId != requestSequence) return@launch
                    loaded += page.list
                    total = page.total
                    hasMore = page.page * page.pageSize < page.total
                    pageNumber = page.page + 1
                    val visibleCount = filterGoodsForProductList(loaded, current.categoryIds, current.filter).size
                    val needsScan = current.categoryIds.isNotEmpty() || current.filter == Filter.OffSale
                    if (!needsScan || visibleCount >= 20 || !hasMore) break
                } while (true)

                _state.value = _state.value.copy(
                    loading = false,
                    refreshing = false,
                    goods = loaded,
                    total = total,
                    nextPage = pageNumber,
                    hasMore = hasMore,
                    loadingMore = false,
                    error = null,
                )
            } catch (e: NetworkException) {
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message)
            } catch (e: Exception) {
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message ?: "加载失败")
            }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.loading || current.loadingMore || !current.hasMore) return
        val requestId = requestSequence
        _state.value = current.copy(loadingMore = true, error = null)
        viewModelScope.launch {
            try {
                val loaded = current.goods.toMutableList()
                var pageNumber = current.nextPage
                var total = current.total
                var hasMore = current.hasMore
                val targetVisibleCount = filterGoodsForProductList(loaded, current.categoryIds, current.filter).size + 20
                while (hasMore && filterGoodsForProductList(loaded, current.categoryIds, current.filter).size < targetVisibleCount) {
                    val page = repo.goods(
                        keyword = current.keyword.takeIf { it.isNotBlank() },
                        onSaleOnly = current.filter.onSaleOnly,
                        page = pageNumber,
                        pageSize = 100,
                    )
                    if (requestId != requestSequence) return@launch
                    loaded += page.list
                    total = page.total
                    hasMore = page.page * page.pageSize < page.total
                    pageNumber = page.page + 1
                }

                _state.value = _state.value.copy(
                    goods = loaded,
                    total = total,
                    nextPage = pageNumber,
                    hasMore = hasMore,
                    loadingMore = false,
                    error = null,
                )
            } catch (e: Exception) {
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(loadingMore = false, error = e.message ?: "加载失败")
            }
        }
    }

    /** 一键上下架。误点下架会让顾客立刻买不到，因此 UI 侧要二次确认。 */
    fun toggleShelves(goods: Goods) {
        if (_state.value.busyId != null) return

        _state.value = _state.value.copy(busyId = goods.id, error = null)
        viewModelScope.launch {
            try {
                repo.setShelves(goods.id, !goods.isShelves)
                load(preserveExisting = true)
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message ?: "操作失败")
            } finally {
                _state.value = _state.value.copy(busyId = null)
            }
        }
    }

    fun loadInventory(goodsId: String) {
        val requestId = ++inventoryRequestSequence
        _state.value = _state.value.copy(
            inventoryGoodsId = goodsId,
            inventoryLoading = true,
            inventoryError = null,
            inventoryMessage = null,
            inventorySkus = emptyList(),
        )
        viewModelScope.launch {
            try {
                val goods = repo.goodsDetail(goodsId)
                if (requestId != inventoryRequestSequence) return@launch
                _state.value = _state.value.copy(
                    inventoryLoading = false,
                    inventorySkus = goods.skus,
                    inventoryError = if (goods.skus.isEmpty()) "没有可调整的商品规格，请刷新后重试。" else null,
                )
            } catch (e: Exception) {
                if (requestId != inventoryRequestSequence) return@launch
                _state.value = _state.value.copy(
                    inventoryLoading = false,
                    inventoryError = e.message ?: "读取库存失败",
                )
            }
        }
    }

    fun closeInventory() {
        inventoryRequestSequence += 1
        _state.value = _state.value.copy(
            inventoryGoodsId = null,
            inventoryLoading = false,
            inventoryError = null,
            inventoryMessage = null,
            inventorySkus = emptyList(),
        )
    }

    fun setInventory(goodsId: String, skuId: String, inventory: Int) {
        if (inventory < 0) {
            _state.value = _state.value.copy(inventoryError = "库存不能小于 0")
            return
        }
        val current = _state.value
        if (current.inventoryGoodsId != goodsId || current.inventoryBusySkuId != null) return
        if (current.inventorySkus.none { it.skuId == skuId }) {
            _state.value = current.copy(inventoryError = "商品规格已变化，请重新打开库存调整")
            return
        }

        _state.value = current.copy(inventoryBusySkuId = skuId, inventoryError = null, inventoryMessage = null)
        viewModelScope.launch {
            try {
                val updatedGoods = repo.updateSku(goodsId, skuId, priceFen = null, inventory = inventory)
                val responseSkus = updatedGoods.skus.takeIf { it.isNotEmpty() }
                    ?: _state.value.inventorySkus.map { sku ->
                        if (sku.skuId == skuId) sku.copy(inventory = inventory) else sku
                    }
                _state.value = _state.value.copy(
                    goods = _state.value.goods.map { if (it.id == goodsId) updatedGoods.copy(skus = responseSkus) else it },
                    inventorySkus = if (_state.value.inventoryGoodsId == goodsId) responseSkus else _state.value.inventorySkus,
                    inventoryMessage = if (_state.value.inventoryGoodsId == goodsId) "库存已更新" else _state.value.inventoryMessage,
                    inventoryError = null,
                )
            } catch (e: Exception) {
                if (_state.value.inventoryGoodsId == goodsId) {
                    _state.value = _state.value.copy(inventoryError = e.message ?: "库存更新失败")
                } else {
                    _state.value = _state.value.copy(error = e.message ?: "库存更新失败")
                }
            } finally {
                if (_state.value.inventoryBusySkuId == skuId) {
                    _state.value = _state.value.copy(inventoryBusySkuId = null)
                }
            }
        }
    }

    fun consumeError() {
        _state.value = _state.value.copy(error = null)
    }
}

internal fun filterGoodsForProductList(
    goods: List<Goods>,
    categoryIds: Set<String>,
    filter: ProductListViewModel.Filter,
): List<Goods> = goods.filter { item ->
    (categoryIds.isEmpty() || item.categoryId in categoryIds) && when (filter) {
        ProductListViewModel.Filter.All -> true
        ProductListViewModel.Filter.OnSale -> item.isShelves
        ProductListViewModel.Filter.OffSale -> !item.isShelves
    }
}

private fun flattenCategoryTree(categories: List<Category>): List<Category> =
    categories.flatMap { listOf(it) + flattenCategoryTree(it.children) }.distinctBy { it.id }

internal fun productCategoryRoots(categories: List<Category>): List<Category> {
    if (categories.any { it.children.isNotEmpty() }) return categories.distinctBy { it.id }
    return categories.filter { it.parentId.isNullOrBlank() || it.parentId == "0" }
}

internal fun productCategorySubtreeIds(categories: List<Category>, rootId: String): Set<String> {
    val all = flattenCategoryTree(categories)
    val ids = linkedSetOf<String>()

    fun addTree(category: Category) {
        if (!ids.add(category.id)) return
        category.children.forEach(::addTree)
    }

    all.firstOrNull { it.id == rootId }?.let(::addTree)
    ids.add(rootId)
    var changed: Boolean
    do {
        changed = false
        all.filter { it.parentId in ids && ids.add(it.id) }.forEach { category ->
            changed = true
            category.children.forEach(::addTree)
        }
    } while (changed)
    return ids
}

@Composable
fun ProductListScreen(onOpenProduct: (String) -> Unit, onCreateProduct: () -> Unit, onBack: () -> Unit) {
    val repo = MerchantApp.instance.repository
    val vm = androidx.lifecycle.viewmodel.compose.viewModel { ProductListViewModel(repo) }
    val state by vm.state.collectAsState()
    val context = LocalContext.current
    val presetGoods = remember(context) {
        runCatching {
            context.assets.open("preset-goods.json").bufferedReader().use { reader -> parsePresetGoods(reader.readText()) }
        }.getOrDefault(emptyList())
    }
    val categoryPreviewAssets = remember(presetGoods) {
        presetGoods.groupBy { it.suggestedCategory.trim() }
            .mapValues { (_, goods) -> goods.firstNotNullOfOrNull { it.imageAsset } }
    }

    var keyword by remember { mutableStateOf("") }
    var pendingToggle by remember { mutableStateOf<Goods?>(null) }
    var selectedCategory by remember { mutableStateOf<Category?>(null) }
    var showCategoryManagement by remember { mutableStateOf(false) }
    var inventoryDialogGoods by remember { mutableStateOf<Goods?>(null) }
    val visibleGoods = filterGoodsForProductList(state.goods, state.categoryIds, state.filter)
    val handleBack = {
        when {
            showCategoryManagement -> {
                showCategoryManagement = false
                vm.loadCategories()
            }
            selectedCategory != null -> {
                selectedCategory = null
                keyword = ""
                vm.setKeyword("")
                vm.setCategoryIds(emptySet())
            }
            else -> onBack()
        }
    }

    BackHandler(enabled = showCategoryManagement || selectedCategory != null, onBack = handleBack)
    LaunchedEffect(Unit) {
        vm.loadCategories()
        vm.load()
    }
    AutoRefreshEffect(
        enabled = selectedCategory != null && !showCategoryManagement,
        onRefresh = { if (!state.loading && !state.refreshing) vm.load(preserveExisting = true) },
    )

    PullToRefreshLayout(
        refreshing = selectedCategory != null && state.refreshing,
        onRefresh = {
            if (selectedCategory != null && !state.loading && !state.refreshing) vm.load(preserveExisting = true)
        },
        enabled = selectedCategory != null && !showCategoryManagement,
        modifier = Modifier.fillMaxSize().background(C.Canvas).safeDrawingPadding(),
    ) {
        Column(Modifier.fillMaxSize()) {
            Column(Modifier.padding(horizontal = dim.pagePadding)) {
                MerchantTopBar(
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.weight(1f)) {
                        IconButton(
                            onClick = handleBack,
                        ) {
                            MerchantBackIcon(size = 22.dp)
                        }
                        Text(
                            when {
                                showCategoryManagement -> "分类管理"
                                selectedCategory != null -> selectedCategory!!.name
                                else -> "商品管理"
                            },
                            fontSize = T.title,
                            fontWeight = FontWeight.Bold,
                            color = C.Brand700,
                            maxLines = 1,
                        )
                    }
                    if (!showCategoryManagement) {
                        androidx.compose.material3.TextButton(
                            onClick = onCreateProduct,
                            contentPadding = PaddingValues(horizontal = 4.dp, vertical = 4.dp),
                        ) {
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                MerchantAssetIcon(R.drawable.merchant_add, contentDescription = null, size = 17.dp)
                                Spacer(Modifier.width(4.dp))
                                Text("新增农产品", color = C.Brand700, fontSize = T.caption, fontWeight = FontWeight.SemiBold)
                            }
                        }
                    }
                }
            }

            when {
                showCategoryManagement -> ProductCategoryManagementScreen(
                    modifier = Modifier.weight(1f),
                    previewAssets = categoryPreviewAssets,
                )
                selectedCategory == null -> ProductCategoryHome(
                    modifier = Modifier.weight(1f),
                    categories = state.categories,
                    previewGoods = state.goods,
                    previewAssets = categoryPreviewAssets,
                    imageUrl = repo::absoluteUrl,
                    loading = state.categoryLoading,
                    error = state.categoryError,
                    onManage = { showCategoryManagement = true },
                    onSelect = { category ->
                        selectedCategory = category
                        keyword = ""
                        vm.setKeyword("")
                        vm.setCategoryIds(productCategorySubtreeIds(state.categories, category.id))
                    },
                )
                else -> Column(Modifier.weight(1f)) {
                    Column(Modifier.padding(horizontal = dim.pagePadding)) {
                        Spacer(Modifier.height(4.dp))
                        OutlinedTextField(
                            value = keyword,
                            onValueChange = { keyword = it; vm.setKeyword(it) },
                            placeholder = { Text("搜索商品名称", fontSize = T.caption) },
                            leadingIcon = { MerchantAssetIcon(R.drawable.merchant_search, null, size = 19.dp) },
                            trailingIcon = {
                                IconButton(onClick = { vm.load() }) {
                                    MerchantAssetIcon(R.drawable.merchant_search, "搜索商品", size = 19.dp)
                                }
                            },
                            singleLine = true,
                            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                            keyboardActions = KeyboardActions(onSearch = { vm.load() }),
                            shape = RoundedCornerShape(13.dp),
                            modifier = Modifier.fillMaxWidth(),
                            colors = TextFieldDefaults.colors(
                                focusedContainerColor = C.Surface,
                                unfocusedContainerColor = C.Surface,
                            ),
                        )
                        Spacer(Modifier.height(4.dp))
                        ProductStatusFilterRow(selected = state.filter, onSelect = vm::setFilter)
                    }

                    if (state.error != null) {
                        Box(Modifier.padding(horizontal = dim.pagePadding)) { NoticeBar(state.error!!, ChipTone.Danger) }
                        Spacer(Modifier.height(8.dp))
                    }

                    when {
                        state.loading -> Box(Modifier.padding(horizontal = dim.pagePadding)) { ProductListSkeleton(rows = 4) }
                        visibleGoods.isEmpty() -> LazyColumn(
                            Modifier.fillMaxSize().background(C.Surface),
                        ) {
                            item {
                                EmptyBox(
                                    title = if (state.filter == ProductListViewModel.Filter.OffSale) "暂无已下架商品" else "这个分类还没有商品",
                                    description = "可以新增农产品，或切换商品状态查看。",
                                )
                                androidx.compose.material3.TextButton(
                                    onClick = onCreateProduct,
                                    contentPadding = PaddingValues(horizontal = 12.dp, vertical = 8.dp),
                                ) {
                                    Row(verticalAlignment = Alignment.CenterVertically) {
                                        MerchantAssetIcon(R.drawable.merchant_add, contentDescription = null, size = 17.dp)
                                        Spacer(Modifier.width(4.dp))
                                        Text("新增农产品", color = C.Brand700, fontSize = T.caption, fontWeight = FontWeight.SemiBold)
                                    }
                                }
                            }
                        }
                        else -> LazyColumn(
                            modifier = Modifier.fillMaxSize().background(C.Surface),
                            contentPadding = PaddingValues(bottom = 16.dp),
                        ) {
                            items(visibleGoods, key = { it.id }) { goods ->
                                ProductRow(
                                    goods = goods,
                                    imageUrl = repo.absoluteUrl(goods.image),
                                    busy = state.busyId == goods.id,
                                    onClick = { onOpenProduct(goods.id) },
                                    onToggle = { pendingToggle = goods },
                                    onInventory = {
                                        inventoryDialogGoods = goods
                                        vm.loadInventory(goods.id)
                                    },
                                )
                            }
                            if (state.hasMore) {
                                item(key = "load-more") {
                                    if (state.loadingMore) {
                                        androidx.compose.material3.CircularProgressIndicator(
                                            modifier = Modifier.padding(16.dp).size(22.dp),
                                            color = C.Brand700,
                                            strokeWidth = 2.dp,
                                        )
                                    } else {
                                        androidx.compose.material3.TextButton(
                                            onClick = vm::loadMore,
                                            modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
                                        ) { Text("加载更多商品", color = C.Brand700, fontSize = T.caption) }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        pendingToggle?.let { goods ->
            androidx.compose.material3.AlertDialog(
                onDismissRequest = { pendingToggle = null },
                title = { Text(if (goods.isShelves) "下架商品" else "上架商品") },
                text = {
                    Text(
                        if (goods.isShelves) {
                            "「${goods.title}」下架后顾客将立即无法下单。确认下架？"
                        } else {
                            "「${goods.title}」上架后顾客可以立即下单购买。"
                        },
                    )
                },
                confirmButton = {
                    androidx.compose.material3.TextButton(onClick = {
                        pendingToggle = null
                        vm.toggleShelves(goods)
                    }) { Text("确认", color = C.Brand700) }
                },
                dismissButton = {
                    androidx.compose.material3.TextButton(onClick = { pendingToggle = null }) { Text("取消") }
                },
            )
        }

        inventoryDialogGoods?.let { goods ->
            InventoryAdjustDialog(
                goodsTitle = goods.title,
                inventorySkus = if (state.inventoryGoodsId == goods.id) state.inventorySkus else emptyList(),
                loading = state.inventoryLoading,
                error = if (state.inventoryGoodsId == goods.id) state.inventoryError else null,
                message = if (state.inventoryGoodsId == goods.id) state.inventoryMessage else null,
                busySkuId = state.inventoryBusySkuId,
                onDismiss = {
                    inventoryDialogGoods = null
                    vm.closeInventory()
                },
                onSave = { skuId, inventory -> vm.setInventory(goods.id, skuId, inventory) },
            )
        }
    }
}

@Composable
private fun ProductCategoryHome(
    modifier: Modifier = Modifier,
    categories: List<Category>,
    previewGoods: List<Goods>,
    previewAssets: Map<String, String?>,
    imageUrl: (String) -> String,
    loading: Boolean,
    error: String?,
    onManage: () -> Unit,
    onSelect: (Category) -> Unit,
) {
    Column(modifier.fillMaxSize().background(C.Canvas)) {
        Column(Modifier.padding(horizontal = dim.pagePadding)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("农产品分类", fontSize = T.section, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                    Spacer(Modifier.height(2.dp))
                    Text("选择分类，查看对应商品", fontSize = T.mini, color = C.TextMuted)
                }
                androidx.compose.material3.TextButton(
                    onClick = onManage,
                    contentPadding = PaddingValues(horizontal = 2.dp, vertical = 4.dp),
                ) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Icon(
                            imageVector = Icons.Outlined.Edit,
                            contentDescription = null,
                            tint = C.TextMuted,
                            modifier = Modifier.size(15.dp),
                        )
                        Spacer(Modifier.width(3.dp))
                        Text("分类管理", color = C.Brand700, fontSize = T.mini)
                    }
                }
            }
            if (error != null) {
                Spacer(Modifier.height(8.dp))
                NoticeBar(error, ChipTone.Danger)
            }
        }

        when {
            loading -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                androidx.compose.material3.CircularProgressIndicator(color = C.Brand700, strokeWidth = 2.dp)
            }
            productCategoryRoots(categories).isEmpty() -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.TopCenter) {
                EmptyBox(
                    title = "还没有农产品分类",
                    description = "先创建分类，再将商品归入对应分类。",
                    actionText = "分类管理",
                    onAction = onManage,
                )
            }
            else -> LazyColumn(
                modifier = Modifier.fillMaxSize().padding(horizontal = dim.pagePadding),
                contentPadding = PaddingValues(top = 4.dp, bottom = 20.dp),
            ) {
                items(productCategoryRoots(categories), key = { it.id }) { category ->
                    val ids = productCategorySubtreeIds(categories, category.id)
                    val preview = previewGoods.firstOrNull { it.categoryId in ids && it.image.isNotBlank() }
                    val presetPreview = previewAssets[category.name.trim()]
                    Row(
                        modifier = Modifier.fillMaxWidth().clickable { onSelect(category) }.padding(vertical = 9.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            modifier = Modifier
                                .size(54.dp)
                                .clip(RoundedCornerShape(13.dp))
                                .background(C.Brand100),
                            contentAlignment = Alignment.Center,
                        ) {
                            if (preview != null) {
                                AsyncImage(
                                    model = imageUrl(preview.image),
                                    contentDescription = category.name,
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier.fillMaxSize(),
                                )
                            } else if (presetPreview != null) {
                                AsyncImage(
                                    model = "file:///android_asset/$presetPreview",
                                    contentDescription = category.name,
                                    contentScale = ContentScale.Crop,
                                    modifier = Modifier.fillMaxSize(),
                                )
                            } else {
                                Text(
                                    category.name.take(1),
                                    fontSize = T.headline,
                                    fontWeight = FontWeight.SemiBold,
                                    color = C.Brand700,
                                )
                            }
                        }
                        Spacer(Modifier.width(12.dp))
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                            Text(category.name, fontSize = T.body, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                            Text("查看商品列表", fontSize = T.mini, color = C.TextMuted)
                        }
                        Icon(
                            imageVector = Icons.Outlined.ChevronRight,
                            contentDescription = "查看${category.name}",
                            tint = C.TextMuted,
                            modifier = Modifier.size(19.dp),
                        )
                    }
                    Box(
                        Modifier.fillMaxWidth().padding(start = 66.dp).height(1.dp).background(C.Divider),
                    )
                }
            }
        }
    }
}

@Composable
private fun ProductStatusFilterRow(
    selected: ProductListViewModel.Filter,
    onSelect: (ProductListViewModel.Filter) -> Unit,
) {
    Row(Modifier.fillMaxWidth().height(44.dp)) {
        ProductListViewModel.Filter.entries.forEach { filter ->
            val active = selected == filter
            Column(
                modifier = Modifier.weight(1f).fillMaxSize().clickable { onSelect(filter) },
                horizontalAlignment = Alignment.CenterHorizontally,
                verticalArrangement = Arrangement.Center,
            ) {
                Text(
                    filter.label,
                    fontSize = T.caption,
                    color = if (active) C.Brand700 else C.TextSecondary,
                    fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                )
                Spacer(Modifier.height(5.dp))
                Box(
                    Modifier
                        .width(22.dp)
                        .height(2.dp)
                        .background(if (active) C.Brand700 else Color.Transparent, RoundedCornerShape(1.dp)),
                )
            }
        }
    }
}

@Composable
private fun InventoryAdjustDialog(
    goodsTitle: String,
    inventorySkus: List<Sku>,
    loading: Boolean,
    error: String?,
    message: String?,
    busySkuId: String?,
    onDismiss: () -> Unit,
    onSave: (skuId: String, inventory: Int) -> Unit,
) {
    androidx.compose.material3.AlertDialog(
        onDismissRequest = { if (busySkuId == null) onDismiss() },
        title = { Text("调整库存") },
        text = {
            Column(
                modifier = Modifier.heightIn(max = 460.dp).verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                Text(goodsTitle, fontSize = T.caption, color = C.TextSecondary)
                if (loading) {
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                        androidx.compose.material3.CircularProgressIndicator(
                            modifier = Modifier.size(20.dp),
                            color = C.Brand700,
                            strokeWidth = 2.dp,
                        )
                        Text("读取规格库存…", fontSize = T.caption, color = C.TextSecondary)
                    }
                }
                if (error != null) NoticeBar(error, ChipTone.Danger)
                if (message != null) NoticeBar(message, ChipTone.Success)
                inventorySkus.forEachIndexed { index, sku ->
                    CardBox(background = C.Canvas) {
                        Column(Modifier.padding(10.dp), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                            Text(
                                sku.specText.ifBlank { if (inventorySkus.size == 1) "默认规格" else "规格 ${index + 1}" },
                                fontSize = T.caption,
                                fontWeight = FontWeight.SemiBold,
                            )
                            Text("当前库存 ${sku.inventory}", fontSize = T.mini, color = C.TextMuted)
                            var draft by remember(sku.skuId, sku.inventory) { mutableStateOf(sku.inventory.toString()) }
                            val parsedInventory = draft.toIntOrNull()
                            val busy = busySkuId == sku.skuId
                            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(2.dp)) {
                                IconButton(
                                    onClick = {
                                        val current = (draft.toIntOrNull() ?: sku.inventory).coerceAtLeast(0)
                                        draft = (current - 1).coerceAtLeast(0).toString()
                                    },
                                    enabled = busySkuId == null && parsedInventory != 0,
                                    modifier = Modifier.size(38.dp),
                                ) { Text("−", fontSize = T.headline, color = C.Brand700) }
                                OutlinedTextField(
                                    value = draft,
                                    onValueChange = { draft = it.filter(Char::isDigit).take(10) },
                                    singleLine = true,
                                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number, imeAction = ImeAction.Done),
                                    modifier = Modifier.weight(1f),
                                    shape = RoundedCornerShape(dim.radiusSm),
                                    colors = TextFieldDefaults.colors(
                                        focusedContainerColor = C.Surface,
                                        unfocusedContainerColor = C.Surface,
                                    ),
                                )
                                IconButton(
                                    onClick = {
                                        val current = (draft.toIntOrNull() ?: sku.inventory).coerceAtLeast(0)
                                        draft = (current.toLong() + 1).coerceAtMost(Int.MAX_VALUE.toLong()).toString()
                                    },
                                    enabled = busySkuId == null && (parsedInventory ?: -1) < Int.MAX_VALUE,
                                    modifier = Modifier.size(38.dp),
                                ) { Text("+", fontSize = T.headline, color = C.Brand700) }
                                androidx.compose.material3.TextButton(
                                    onClick = { parsedInventory?.let { onSave(sku.skuId, it) } },
                                    enabled = busySkuId == null && parsedInventory != null && parsedInventory >= 0 && parsedInventory != sku.inventory,
                                ) { Text(if (busy) "保存中" else "保存", fontSize = T.mini, color = C.Brand700) }
                            }
                        }
                    }
                }
            }
        },
        confirmButton = {
            androidx.compose.material3.TextButton(onClick = onDismiss, enabled = busySkuId == null) {
                Text("完成", color = C.Brand700)
            }
        },
    )
}

/** 商品行（方案 §6.4 MProductRow） */
@Composable
private fun ProductRow(
    goods: Goods,
    imageUrl: String,
    busy: Boolean,
    onClick: () -> Unit,
    onToggle: () -> Unit,
    onInventory: () -> Unit,
) {
    Column(Modifier.fillMaxWidth().background(C.Surface)) {
        Row(
            Modifier.fillMaxWidth().clickable { onClick() }.padding(horizontal = 13.dp, vertical = 9.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Box(
                modifier = Modifier
                    .size(66.dp)
                    .clip(RoundedCornerShape(12.dp))
                    .background(C.Brand100),
                contentAlignment = Alignment.Center,
            ) {
                if (imageUrl.isNotBlank()) {
                    AsyncImage(
                        model = imageUrl,
                        contentDescription = goods.title,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize(),
                    )
                } else {
                    Text(
                        goods.title.take(1),
                        fontSize = T.headline,
                        fontWeight = FontWeight.SemiBold,
                        color = C.Brand700.copy(alpha = 0.35f),
                    )
                }
            }

            Spacer(Modifier.width(10.dp))

            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(1.dp)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        goods.title,
                        modifier = Modifier.weight(1f),
                        fontSize = T.body,
                        fontWeight = FontWeight.SemiBold,
                        maxLines = 1,
                    )
                    IconButton(onClick = onClick, modifier = Modifier.size(34.dp)) {
                        Icon(
                            imageVector = Icons.Outlined.Edit,
                            contentDescription = "编辑${goods.title}",
                            tint = C.TextMuted,
                            modifier = Modifier.size(18.dp),
                        )
                    }
                }
                Text(
                    goods.specDesc.ifBlank { goods.inventoryUnit.ifBlank { "按份销售" } },
                    fontSize = T.mini,
                    color = C.TextMuted,
                    maxLines = 1,
                )
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(money(goods.minPriceFen), style = tabularStyle(T.section, FontWeight.Bold, C.Price))
                    Spacer(Modifier.width(8.dp))
                    Box(
                        Modifier.size(5.dp).background(if (goods.isShelves) C.Brand700 else C.TextMuted, CircleShape),
                    )
                    Spacer(Modifier.width(4.dp))
                    Text(
                        if (goods.isShelves) "在售" else "已下架",
                        fontSize = T.mini,
                        color = if (goods.isShelves) C.Brand700 else C.TextMuted,
                    )
                    Spacer(Modifier.width(8.dp))
                    Text(
                        "库存 ${goods.inventory}${goods.inventoryUnit}",
                        fontSize = T.mini,
                        color = if (goods.inventory <= 0) C.Danger else C.TextSecondary,
                        maxLines = 1,
                    )
                }
                Row(verticalAlignment = Alignment.CenterVertically) {
                    androidx.compose.material3.TextButton(
                        onClick = onInventory,
                        contentPadding = PaddingValues(horizontal = 2.dp, vertical = 0.dp),
                    ) {
                        MerchantAssetIcon(R.drawable.merchant_inventory, null, size = 15.dp)
                        Spacer(Modifier.width(3.dp))
                        Text("调库存", fontSize = T.mini, color = C.TextSecondary)
                    }
                    androidx.compose.material3.TextButton(
                        onClick = onToggle,
                        enabled = !busy,
                        contentPadding = PaddingValues(horizontal = 2.dp, vertical = 0.dp),
                    ) {
                        MerchantAssetIcon(
                            if (goods.isShelves) R.drawable.merchant_unpublish else R.drawable.merchant_publish,
                            null,
                            size = 15.dp,
                        )
                        Spacer(Modifier.width(3.dp))
                        Text(
                            when {
                                busy -> "处理中"
                                goods.isShelves -> "下架"
                                else -> "上架"
                            },
                            fontSize = T.mini,
                            color = C.TextSecondary,
                        )
                    }
                }
            }
        }
        Box(Modifier.fillMaxWidth().padding(start = 89.dp, end = 13.dp).height(1.dp).background(C.Divider))
    }
}
