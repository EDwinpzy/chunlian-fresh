package com.chunlian.merchant.feature.orders

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.KeyboardArrowDown
import androidx.compose.material.icons.outlined.Sort
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.model.Order
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.ListSkeleton
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.StatusChip
import com.chunlian.merchant.ui.components.AutoRefreshEffect
import com.chunlian.merchant.ui.components.PullToRefreshLayout
import com.chunlian.merchant.ui.components.deliveryText
import com.chunlian.merchant.ui.components.fulfillmentText
import com.chunlian.merchant.ui.components.fulfillmentTone
import com.chunlian.merchant.ui.components.money
import com.chunlian.merchant.ui.components.paymentMethodText
import com.chunlian.merchant.ui.components.paymentText
import com.chunlian.merchant.ui.components.paymentTone
import com.chunlian.merchant.ui.components.tabularStyle
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

/** 订单列表；状态筛选与后端履约状态口径一致。 */
class OrderListViewModel(private val repo: MerchantRepository) : ViewModel() {

    /** 展示口径的筛选；与后端 status 参数一一对应 */
    enum class Filter(val key: String?, val label: String) {
        Pending("PENDING", "待配送"),
        Delivering("DELIVERING", "配送中"),
        Delivered("DELIVERED", "已送达"),
        Cancelled("CANCELLED", "已取消"),
        All(null, "全部"),
    }

    enum class Sort(val key: String, val label: String) {
        Default("DEFAULT", "默认顺序"),
        DeliveryTime("EXPECTED_AT", "配送时间"),
    }

    data class UiState(
        val loading: Boolean = true,
        val refreshing: Boolean = false,
        val error: String? = null,
        val orders: List<Order> = emptyList(),
        val filter: Filter = Filter.Pending,
        val sort: Sort = Sort.Default,
        val keyword: String = "",
        val total: Int = 0,
        val nextPage: Int = 2,
        val hasMore: Boolean = false,
        val loadingMore: Boolean = false,
        val scrollToTopVersion: Int = 0,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var requestSequence = 0

    fun setFilter(filter: Filter) {
        if (_state.value.filter == filter) return
        _state.value = _state.value.copy(filter = filter)
        load(scrollToTop = true)
    }

    fun setSort(sort: Sort) {
        if (_state.value.sort == sort) return
        _state.value = _state.value.copy(sort = sort)
        load(scrollToTop = true)
    }

    fun setKeyword(keyword: String) {
        _state.value = _state.value.copy(keyword = keyword)
    }

    fun search() {
        load(scrollToTop = true)
    }

    fun load(scrollToTop: Boolean = false) {
        val current = _state.value
        val requestId = ++requestSequence
        _state.value = current.copy(
            loading = current.orders.isEmpty(),
            refreshing = current.orders.isNotEmpty(),
            loadingMore = false,
            error = null,
        )

        viewModelScope.launch {
            try {
                val page = repo.orders(
                    status = current.filter.key,
                    keyword = current.keyword.takeIf { it.isNotBlank() },
                    sort = current.sort.key,
                    page = 1,
                    pageSize = 50,
                )
                if (requestId != requestSequence) return@launch
                val latest = _state.value
                _state.value = latest.copy(
                    loading = false,
                    refreshing = false,
                    orders = page.list,
                    total = page.total,
                    nextPage = 2,
                    hasMore = page.page * page.pageSize < page.total,
                    error = null,
                    scrollToTopVersion = if (scrollToTop) latest.scrollToTopVersion + 1 else latest.scrollToTopVersion,
                )
            } catch (e: NetworkException) {
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message)
            } catch (e: Exception) {
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(
                    loading = false,
                    refreshing = false,
                    error = e.message ?: "加载失败",
                )
            }
        }
    }

    fun loadMore() {
        val current = _state.value
        if (current.loading || current.refreshing || current.loadingMore || !current.hasMore) return
        val requestId = requestSequence
        _state.value = current.copy(loadingMore = true, error = null)
        viewModelScope.launch {
            try {
                val page = repo.orders(
                    status = current.filter.key,
                    keyword = current.keyword.takeIf { it.isNotBlank() },
                    sort = current.sort.key,
                    page = current.nextPage,
                    pageSize = 50,
                )
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(
                    orders = _state.value.orders + page.list,
                    total = page.total,
                    nextPage = page.page + 1,
                    hasMore = page.page * page.pageSize < page.total,
                    loadingMore = false,
                )
            } catch (e: Exception) {
                if (requestId != requestSequence) return@launch
                _state.value = _state.value.copy(loadingMore = false, error = e.message ?: "加载失败")
            }
        }
    }

}

@Composable
fun OrderListScreen(onOpenOrder: (String) -> Unit, onBack: () -> Unit) {
    val vm = androidx.lifecycle.viewmodel.compose.viewModel {
        OrderListViewModel(MerchantApp.instance.repository)
    }
    val state by vm.state.collectAsState()
    val orderListState = rememberLazyListState()

    var keyword by remember { mutableStateOf("") }

    LaunchedEffect(Unit) { vm.load() }
    AutoRefreshEffect(onRefresh = { if (!state.loading && !state.refreshing) vm.load() })
    LaunchedEffect(state.filter, state.sort, state.scrollToTopVersion) {
        orderListState.scrollToItem(0)
    }

    PullToRefreshLayout(
        refreshing = state.refreshing,
        onRefresh = { if (!state.loading && !state.refreshing) vm.load() },
        modifier = Modifier.fillMaxSize().background(C.Canvas),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .safeDrawingPadding(),
        ) {
        Column(Modifier.padding(horizontal = dim.pagePadding)) {
            MerchantTopBar(
                horizontalArrangement = Arrangement.SpaceBetween,
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    IconButton(onClick = onBack) {
                        MerchantBackIcon()
                    }
                    Text("订单管理", fontSize = T.title, fontWeight = FontWeight.Bold, color = C.Brand700)
                }
                Text(
                    "共 ${state.total} 单",
                    fontSize = T.caption,
                    color = C.TextSecondary,
                )
            }

            Spacer(Modifier.height(10.dp))

            OutlinedTextField(
                value = keyword,
                onValueChange = { keyword = it; vm.setKeyword(it) },
                placeholder = { Text("搜索订单号 / 收货人 / 手机号", fontSize = T.caption) },
                leadingIcon = {
                    MerchantAssetIcon(R.drawable.merchant_search, null, size = 20.dp)
                },
                trailingIcon = {
                    IconButton(onClick = { vm.search() }) {
                        MerchantAssetIcon(
                            R.drawable.merchant_search,
                            contentDescription = "搜索订单",
                            size = 20.dp,
                        )
                    }
                },
                singleLine = true,
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { vm.search() }),
                shape = RoundedCornerShape(dim.radiusMd),
                modifier = Modifier.fillMaxWidth(),
                colors = TextFieldDefaults.colors(
                    focusedContainerColor = C.Surface,
                    unfocusedContainerColor = C.Surface,
                ),
            )

            Spacer(Modifier.height(10.dp))

            OrderFilterPanel(
                selectedFilter = state.filter,
                onFilterSelected = vm::setFilter,
            )

            Spacer(Modifier.height(12.dp))
            OrderListHeader(
                selectedSort = state.sort,
                onSortSelected = vm::setSort,
            )
            Spacer(Modifier.height(12.dp))
        }

        if (state.error != null) {
            Box(Modifier.padding(horizontal = dim.pagePadding)) {
                NoticeBar(state.error!!, ChipTone.Danger)
            }
            Spacer(Modifier.height(12.dp))
        }

        when {
            state.loading -> Box(Modifier.padding(horizontal = dim.pagePadding)) { ListSkeleton(rows = 4) }

            state.orders.isEmpty() -> LazyColumn(Modifier.fillMaxSize()) {
                item {
                    EmptyBox(
                        title = "没有符合条件的订单",
                        description = "换个筛选条件看看，或者下拉刷新。",
                    )
                }
            }

            else -> LazyColumn(
                modifier = Modifier.fillMaxSize(),
                state = orderListState,
                contentPadding = androidx.compose.foundation.layout.PaddingValues(
                    start = dim.pagePadding,
                    end = dim.pagePadding,
                    bottom = 24.dp,
                ),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                items(state.orders, key = { it.id }) { order ->
                    OrderCard(order, onClick = { onOpenOrder(order.id) })
                }
                if (state.hasMore) {
                    item(key = "load-more-orders") {
                        if (state.loadingMore) {
                            androidx.compose.material3.CircularProgressIndicator(
                                modifier = Modifier.padding(16.dp).width(24.dp),
                                color = C.Brand700,
                                strokeWidth = 2.dp,
                            )
                        } else {
                            com.chunlian.merchant.ui.components.SecondaryButton(
                                text = "加载更多",
                                onClick = { vm.loadMore() },
                                modifier = Modifier.fillMaxWidth().padding(vertical = 4.dp),
                            )
                        }
                    }
                }
            }
        }
        }
    }
}

@Composable
internal fun OrderFilterPanel(
    selectedFilter: OrderListViewModel.Filter,
    onFilterSelected: (OrderListViewModel.Filter) -> Unit,
) {
    val filters = listOf(
        OrderListViewModel.Filter.All,
        OrderListViewModel.Filter.Pending,
        OrderListViewModel.Filter.Delivering,
        OrderListViewModel.Filter.Delivered,
        OrderListViewModel.Filter.Cancelled,
    )

    Column(
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .height(48.dp),
            verticalAlignment = Alignment.Bottom,
        ) {
            filters.forEach { filter ->
                OrderStatusTab(
                    label = filter.label,
                    selected = selectedFilter == filter,
                    onClick = { onFilterSelected(filter) },
                    modifier = Modifier.weight(1f),
                )
            }
        }

        Spacer(
            Modifier
                .fillMaxWidth()
                .height(1.dp)
                .background(C.Divider.copy(alpha = 0.75f)),
        )
    }
}

@Composable
internal fun OrderListHeader(
    selectedSort: OrderListViewModel.Sort,
    onSortSelected: (OrderListViewModel.Sort) -> Unit,
) {
    var sortMenuExpanded by remember { mutableStateOf(false) }

    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            "订单列表",
            fontSize = T.section,
            fontWeight = FontWeight.SemiBold,
            color = C.TextPrimary,
        )

        Box {
            TextButton(
                onClick = { sortMenuExpanded = true },
                modifier = Modifier.height(40.dp),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(horizontal = 4.dp),
                colors = ButtonDefaults.textButtonColors(contentColor = C.TextSecondary),
            ) {
                Icon(
                    imageVector = Icons.Outlined.Sort,
                    contentDescription = "排序",
                    modifier = Modifier.size(18.dp),
                    tint = C.TextSecondary,
                )
                Spacer(Modifier.width(5.dp))
                Text(selectedSort.label, fontSize = T.caption, color = C.TextPrimary, maxLines = 1)
                Icon(
                    imageVector = Icons.Outlined.KeyboardArrowDown,
                    contentDescription = null,
                    modifier = Modifier.size(18.dp),
                    tint = C.TextMuted,
                )
            }

            DropdownMenu(
                expanded = sortMenuExpanded,
                onDismissRequest = { sortMenuExpanded = false },
            ) {
                OrderListViewModel.Sort.entries.forEach { sort ->
                    DropdownMenuItem(
                        text = {
                            Text(
                                sort.label,
                                color = if (selectedSort == sort) C.Brand700 else C.TextPrimary,
                            )
                        },
                        onClick = {
                            sortMenuExpanded = false
                            onSortSelected(sort)
                        },
                        trailingIcon = if (selectedSort == sort) {
                            { Text("✓", color = C.Brand700) }
                        } else {
                            null
                        },
                    )
                }
            }
        }
    }

    if (selectedSort == OrderListViewModel.Sort.DeliveryTime) {
        Text(
            "即时配送优先，预约订单按时间从早到晚",
            modifier = Modifier.padding(top = 4.dp),
            fontSize = T.mini,
            color = C.TextSecondary,
        )
    }
}

@Composable
private fun OrderStatusTab(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .height(48.dp)
            .clip(RoundedCornerShape(dim.radiusSm))
            .clickable(onClick = onClick)
            .padding(horizontal = 2.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Spacer(Modifier.weight(1f))
        Text(
            label,
            fontSize = T.caption,
            color = if (selected) C.Brand700 else C.TextSecondary,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Medium,
            maxLines = 1,
        )
        Spacer(
            Modifier
                .padding(top = 6.dp)
                .width(24.dp)
                .height(3.dp)
                .background(
                    if (selected) C.Brand500 else C.Canvas,
                    RoundedCornerShape(dim.radiusPill),
                ),
        )
    }
}

/**
 * 订单卡。
 *
 * 方案 §6.2 要求：订单号 / 履约状态 / 付款状态同一行；
 * COD 未收款用暖色文字标签，**不能和「配送中」混淆**。
 */
@Composable
fun OrderCard(order: Order, onClick: () -> Unit) {
    CardBox(modifier = Modifier.clickable { onClick() }) {
        Column(Modifier.padding(dim.cardPadding)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text("#${order.orderNo}", fontSize = T.caption, color = C.TextSecondary)
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    StatusChip(fulfillmentText(order.fulfillment), fulfillmentTone(order.fulfillment))
                    StatusChip(paymentText(order.paymentState), paymentTone(order.paymentState))
                }
            }

            Spacer(Modifier.height(8.dp))

            Text(
                "${order.contact.name.ifBlank { "未填写收货人" }} · ${order.contact.maskedPhone}",
                fontSize = T.body,
                fontWeight = FontWeight.Medium,
            )

            Text(
                order.lines.joinToString(" / ") { "${it.nameSnapshot}×${it.qty}" },
                fontSize = T.caption,
                color = C.TextSecondary,
                maxLines = 2,
            )

            Spacer(Modifier.height(4.dp))

            Text(
                order.contact.address.ifBlank { "（未填写地址）" },
                fontSize = T.caption,
                color = C.TextSecondary,
                maxLines = 2,
            )

            Spacer(Modifier.height(8.dp))

            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(deliveryText(order.deliveryMode, order.expectedAt), fontSize = T.mini, color = C.TextMuted)
                    Spacer(Modifier.width(8.dp))
                    StatusChip(paymentMethodText(order.paymentMethod), ChipTone.Info)
                }
                Text(money(order.totalFen), style = tabularStyle(T.headline, FontWeight.Bold, C.Price))
            }

            if (order.receivableFen > 0) {
                Spacer(Modifier.height(6.dp))
                Text(
                    "未收款 ${money(order.receivableFen)}",
                    fontSize = T.mini,
                    color = C.Warning,
                    fontWeight = FontWeight.SemiBold,
                )
            }

            val revisionNotice = when {
                order.hasPendingTopup -> "顾客订单变更待补款，补款确认前不能开始配送"
                order.hasRefundAwaitingVerification -> "顾客订单变更待核实退款，完成退款登记前不能开始配送"
                order.hasOutstandingPrepayment && order.paymentMethod == "OFFLINE_QR" ->
                    "扫码预付款待核实，确认足额到账前不能开始配送"
                order.hasOutstandingPrepayment -> "在线付款尚未确认，到账后才能开始配送"
                else -> null
            }
            if (revisionNotice != null) {
                Spacer(Modifier.height(6.dp))
                Text(
                    revisionNotice,
                    fontSize = T.mini,
                    color = C.Warning,
                )
            }
        }
    }
}
