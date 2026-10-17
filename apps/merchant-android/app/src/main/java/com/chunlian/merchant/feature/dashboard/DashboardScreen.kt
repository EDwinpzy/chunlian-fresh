package com.chunlian.merchant.feature.dashboard

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.TextButton
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
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.model.DashboardData
import com.chunlian.merchant.data.model.RecentOrder
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.AutoRefreshEffect
import com.chunlian.merchant.ui.components.PullToRefreshLayout
import com.chunlian.merchant.ui.components.FeaturedCard
import com.chunlian.merchant.ui.components.deliveryText
import com.chunlian.merchant.ui.components.orderStatusLabel
import com.chunlian.merchant.ui.components.orderStatusTone
import com.chunlian.merchant.ui.components.chipColors
import com.chunlian.merchant.ui.components.money
import com.chunlian.merchant.ui.components.paymentText
import com.chunlian.merchant.ui.components.paymentTone
import com.chunlian.merchant.ui.components.tabularStyle
import coil.compose.AsyncImage
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch

private enum class DashboardOrderFilter(val title: String, val emptyText: String) {
    Today("今日订单", "今天还没有订单"),
    Pending("待配送订单", "暂无待配送订单"),
    Delivering("配送中订单", "暂无配送中的订单"),
    Uncollected("待收款订单", "暂无未收款订单"),
}

private fun DashboardData.orders(filter: DashboardOrderFilter): List<RecentOrder> = when (filter) {
    DashboardOrderFilter.Today -> todayOrders
    DashboardOrderFilter.Pending -> pendingOrders.ifEmpty {
        recentOrders.filter { it.orderStatus == "PENDING" }
    }
    DashboardOrderFilter.Delivering -> deliveringOrders.ifEmpty {
        recentOrders.filter { it.orderStatus == "DELIVERING" }
    }
    DashboardOrderFilter.Uncollected -> uncollectedOrders.ifEmpty {
        recentOrders.filter { it.orderStatus in setOf("DELIVERED", "FINISHED") && it.receivableFen > 0 }
    }
}

private fun DashboardData.orderCount(filter: DashboardOrderFilter): Int = when (filter) {
    DashboardOrderFilter.Today -> todayOrderCount
    DashboardOrderFilter.Pending -> pendingCount
    DashboardOrderFilter.Delivering -> deliveringCount
    DashboardOrderFilter.Uncollected -> deliveredUncollectedCount
}

/** 工作台直接展示服务端统计；实收与累计待收分列，不在客户端聚合。 */
class DashboardViewModel(private val repo: MerchantRepository) : ViewModel() {

    data class UiState(
        val loading: Boolean = true,
        val refreshing: Boolean = false,
        val error: String? = null,
        val data: DashboardData? = null,
        val togglingOpen: Boolean = false,
        val unreadNotifications: Int = 0,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var loadJob: Job? = null

    fun load() {
        val hasData = _state.value.data != null
        loadJob?.cancel()
        _state.value = _state.value.copy(loading = !hasData, refreshing = hasData, error = null)

        loadJob = viewModelScope.launch {
            try {
                val data = repo.dashboard()
                val unread = try {
                    repo.notifications(limit = 1).unreadCount
                } catch (e: CancellationException) {
                    throw e
                } catch (_: Exception) {
                    0
                }
                _state.value = _state.value.copy(
                    loading = false,
                    refreshing = false,
                    data = data,
                    unreadNotifications = unread,
                    error = null,
                )
            } catch (e: CancellationException) {
                throw e
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message)
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message ?: "加载失败")
            }
        }
    }

    /** 一键切换营业状态。会影响顾客端能否下单，属于敏感动作，UI 侧要二次确认。 */
    fun toggleOpen() {
        val current = _state.value.data?.store ?: return
        if (_state.value.togglingOpen) return

        _state.value = _state.value.copy(togglingOpen = true)
        viewModelScope.launch {
            try {
                repo.updateStore(com.chunlian.merchant.data.model.StorePatch(isOpen = !current.isOpen))
                load()
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message ?: "切换失败")
            } finally {
                _state.value = _state.value.copy(togglingOpen = false)
            }
        }
    }
}

@Composable
fun DashboardScreen(
    onOpenOrder: (String) -> Unit,
    onSeeAllOrders: () -> Unit,
    onOpenStats: () -> Unit,
    onOpenSettings: () -> Unit,
    onOpenNotifications: () -> Unit,
) {
    val vm = viewModel { DashboardViewModel(MerchantApp.instance.repository) }
    val state by vm.state.collectAsState()
    var confirmStoreToggle by remember { mutableStateOf(false) }
    var selectedOrderFilter by remember { mutableStateOf(DashboardOrderFilter.Pending) }

    LaunchedEffect(Unit) { vm.load() }
    AutoRefreshEffect(onRefresh = { if (!state.loading && !state.refreshing) vm.load() })

    val data = state.data
    val store = data?.store
    val displayedShopName = store?.shopName?.takeIf { it.isNotBlank() }
        ?: MerchantApp.instance.session.shopName.ifBlank { "店铺管理" }
    val shopLogo = store?.shopLogoUrl?.takeIf { it.isNotBlank() }
        ?.let(MerchantApp.instance.repository::absoluteUrl)

    PullToRefreshLayout(
        refreshing = state.refreshing,
        onRefresh = { if (!state.loading && !state.refreshing) vm.load() },
        modifier = Modifier.fillMaxSize().background(C.Canvas),
    ) {
        Column(
            modifier = Modifier.fillMaxSize()
                .verticalScroll(rememberScrollState()).padding(horizontal = dim.pagePadding),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            // 店铺名直接作为页头标题，长店名不会挤压营业开关。
            MerchantTopBar(
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                if (shopLogo == null) {
                    MerchantAssetIcon(R.mipmap.ic_launcher, null, size = 40.dp)
                } else {
                    AsyncImage(
                        model = shopLogo, contentDescription = "店铺头像",
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.size(40.dp).clip(RoundedCornerShape(dim.radiusMd)),
                    )
                }
                Column(Modifier.weight(1f)) {
                    Text(displayedShopName, fontSize = T.section, lineHeight = T.section * 1.3f,
                        fontWeight = FontWeight.SemiBold, color = C.Brand700,
                        maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                Box(
                    modifier = Modifier.clip(RoundedCornerShape(dim.radiusPill))
                        .background(if (store?.isOpen == true) C.SuccessBg else C.Neutral100)
                        .clickable(enabled = store != null && !state.togglingOpen) { confirmStoreToggle = true }
                        .heightIn(min = dim.touchTarget).padding(horizontal = 12.dp),
                    contentAlignment = Alignment.Center,
                ) {
                    Row(horizontalArrangement = Arrangement.spacedBy(5.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            when {
                                state.togglingOpen -> "切换中…"
                                store == null -> "状态待同步"
                                store.isOpen -> "营业中"
                                else -> "已打烊"
                            },
                            fontSize = T.caption, lineHeight = T.caption * 1.35f,
                            fontWeight = FontWeight.SemiBold,
                            color = if (store?.isOpen == true) C.Success else C.TextSecondary,
                        )
                        Text("切换", fontSize = T.mini, lineHeight = T.mini * 1.35f,
                            color = if (store?.isOpen == true) C.Brand600 else C.TextMuted)
                    }
                }
                DashboardNotificationButton(
                    unreadNotifications = state.unreadNotifications,
                    onOpenNotifications = onOpenNotifications,
                )
            }

            DashboardOverview(
                data = data,
                selectedFilter = selectedOrderFilter,
                onFilterSelected = { selectedOrderFilter = it },
                onOpenStats = onOpenStats,
            )

            state.error?.let { NoticeBar(it, ChipTone.Danger) }
            if (store != null && !store.isOpen) {
                NoticeBar("已打烊 · 顾客暂不能新下单，已有订单可继续处理", ChipTone.Warning)
            }

            when {
                state.loading && data == null -> LoadingBox()
                data == null -> EmptyBox(
                    title = "没能加载工作台数据",
                    description = (state.error ?: "请检查网络后重试。") + " 下拉页面即可重新加载。",
                )
                else -> {
                    val visibleOrders = data.orders(selectedOrderFilter)
                    val visibleCount = data.orderCount(selectedOrderFilter)
                    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically,
                        horizontalArrangement = Arrangement.SpaceBetween) {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(
                                selectedOrderFilter.title,
                                fontSize = T.product, lineHeight = T.product * 1.35f,
                                fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                            Box(Modifier.background(C.Brand100, RoundedCornerShape(dim.radiusPill)).padding(horizontal = 8.dp, vertical = 4.dp)) {
                                Text("$visibleCount 单", fontSize = T.mini,
                                    lineHeight = T.mini * 1.35f, fontWeight = FontWeight.SemiBold, color = C.Brand700)
                            }
                        }
                        Box(Modifier.clip(RoundedCornerShape(dim.radiusSm)).clickable(onClick = onSeeAllOrders)
                            .heightIn(min = dim.touchTarget).padding(horizontal = 8.dp), contentAlignment = Alignment.Center) {
                            Text("查看全部 ›", fontSize = T.caption, lineHeight = T.caption * 1.35f, color = C.Brand600)
                        }
                    }
                    if (visibleOrders.isEmpty()) {
                        CardBox {
                            Text(
                                if (visibleCount == 0) selectedOrderFilter.emptyText else "订单正在同步，请稍后下拉刷新",
                                Modifier.padding(14.dp), fontSize = T.caption,
                                lineHeight = T.caption * 1.35f, color = C.TextSecondary,
                            )
                        }
                    } else {
                        CardBox {
                            Column {
                                visibleOrders.forEachIndexed { index, order ->
                                    if (index > 0) DashboardDivider(Modifier.padding(horizontal = 12.dp))
                                    RecentOrderRow(order, onClick = { onOpenOrder(order.orderId) })
                                }
                            }
                        }
                    }
                    Spacer(Modifier.height(6.dp))
                }
            }
        }
    }

    if (confirmStoreToggle && store != null) {
        AlertDialog(
            onDismissRequest = { confirmStoreToggle = false },
            title = { Text(if (store.isOpen) "暂停营业" else "恢复营业") },
            text = {
                Text(if (store.isOpen) "打烊后顾客不能新下单，已存在的订单仍可继续处理。确认打烊？"
                    else "恢复营业后顾客可以立即下单。确认开门营业？")
            },
            confirmButton = {
                TextButton(onClick = { confirmStoreToggle = false; vm.toggleOpen() }) {
                    Text(if (store.isOpen) "确认打烊" else "确认营业", color = C.Brand700)
                }
            },
            dismissButton = { TextButton(onClick = { confirmStoreToggle = false }) { Text("取消") } },
        )
    }
}

@Composable
private fun DashboardDivider(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().height(1.dp).background(C.Divider))
}

@Composable
private fun DashboardOverview(
    data: DashboardData?,
    selectedFilter: DashboardOrderFilter,
    onFilterSelected: (DashboardOrderFilter) -> Unit,
    onOpenStats: () -> Unit,
) {
    val fontScale = LocalDensity.current.fontScale
    val received = data?.let { money(it.todayReceivedFen) } ?: "—"
    val receivable = data?.let { money(it.todayReceivableFen) } ?: "—"
    // 放大字体或较长金额用整行排版，金额不截断。
    val stacked = fontScale > 1.15f || maxOf(received.length, receivable.length) > 10

    FeaturedCard(minHeight = dim.revenueCardHeight + 52.dp) {
        Column(Modifier.padding(horizontal = 12.dp, vertical = 12.dp)) {
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(dim.radiusMd))
                    .clickable(onClick = onOpenStats).padding(horizontal = 4.dp, vertical = 3.dp),
            ) {
                if (stacked) {
                    Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                        OverviewAmount("今日实收", received, true, inline = true, onBrand = true)
                        OverviewAmount("累计未收款", receivable, false, inline = true, onBrand = true)
                    }
                } else {
                    Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                        OverviewAmount("今日实收", received, true, modifier = Modifier.weight(1f), onBrand = true)
                        Box(Modifier.width(1.dp).height(46.dp).background(C.OnBrandTranslucent))
                        OverviewAmount("累计未收款", receivable, false, modifier = Modifier.weight(1f), onBrand = true)
                    }
                }
                Row(
                    Modifier.fillMaxWidth().padding(top = 8.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    Text("实收按到账日，已扣成功退款", fontSize = T.mini,
                        lineHeight = T.mini * 1.35f, color = C.OnBrandMuted)
                    Text("经营统计 ›", fontSize = T.mini, lineHeight = T.mini * 1.35f,
                        fontWeight = FontWeight.Medium, color = C.OnBrand)
                }
            }

            Box(Modifier.fillMaxWidth().padding(top = 10.dp).height(1.dp).background(C.OnBrandTranslucent))

            Row(Modifier.fillMaxWidth().padding(top = 7.dp), verticalAlignment = Alignment.CenterVertically) {
                DashboardCount("今日订单", data?.todayOrderCount, Modifier.weight(1f),
                    selected = selectedFilter == DashboardOrderFilter.Today,
                    onClick = { onFilterSelected(DashboardOrderFilter.Today) })
                Box(Modifier.width(1.dp).height(32.dp).background(C.OnBrandTranslucent))
                DashboardCount("待配送", data?.pendingCount, Modifier.weight(1f),
                    selected = selectedFilter == DashboardOrderFilter.Pending,
                    onClick = { onFilterSelected(DashboardOrderFilter.Pending) })
                Box(Modifier.width(1.dp).height(32.dp).background(C.OnBrandTranslucent))
                DashboardCount("配送中", data?.deliveringCount, Modifier.weight(1f),
                    selected = selectedFilter == DashboardOrderFilter.Delivering,
                    onClick = { onFilterSelected(DashboardOrderFilter.Delivering) })
                Box(Modifier.width(1.dp).height(32.dp).background(C.OnBrandTranslucent))
                DashboardCount("未收款", data?.deliveredUncollectedCount, Modifier.weight(1f),
                    active = (data?.deliveredUncollectedCount ?: 0) > 0,
                    selected = selectedFilter == DashboardOrderFilter.Uncollected,
                    onClick = { onFilterSelected(DashboardOrderFilter.Uncollected) })
            }

            if (data != null && (data.pendingTopupCount > 0 || data.pendingRefundCount > 0)) {
                Column(
                    Modifier.fillMaxWidth().padding(top = 8.dp).clip(RoundedCornerShape(dim.radiusSm))
                        .background(C.OnBrandTranslucentMuted).clickable {
                            onFilterSelected(DashboardOrderFilter.Pending)
                        }.heightIn(min = dim.touchTarget).padding(horizontal = 10.dp, vertical = 7.dp),
                    verticalArrangement = Arrangement.spacedBy(2.dp),
                ) {
                    Text(
                        listOfNotNull(
                            "待补款 ${data.pendingTopupCount} 单".takeIf { data.pendingTopupCount > 0 },
                            "待退款 ${data.pendingRefundCount} 单".takeIf { data.pendingRefundCount > 0 },
                        ).joinToString("  ·  ") + "  ›",
                        fontSize = T.caption, lineHeight = T.caption * 1.35f,
                        fontWeight = FontWeight.Medium, color = C.OnBrandAccent,
                    )
                    Text("相关改单核实补款或退款后才可配送", fontSize = T.mini,
                        lineHeight = T.mini * 1.35f, color = C.OnBrandMuted)
                }
            }
        }
    }
}

@Composable
private fun OverviewAmount(
    label: String, value: String, received: Boolean, modifier: Modifier = Modifier,
    inline: Boolean = false, onBrand: Boolean = false,
) {
    val color = when {
        onBrand && received -> C.OnBrand
        onBrand -> C.OnBrandAccent
        received -> C.Brand700
        else -> C.Warning
    }
    if (inline) {
        Row(modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Text(label, Modifier.weight(1f), fontSize = T.caption, lineHeight = T.caption * 1.35f,
                color = if (onBrand) C.OnBrandMuted else C.TextSecondary)
            Text(value, style = tabularStyle(T.title, FontWeight.SemiBold, color))
        }
    } else {
        Column(modifier) {
            Text(label, fontSize = T.caption, lineHeight = T.caption * 1.35f,
                color = if (onBrand) C.OnBrandMuted else C.TextSecondary)
            Text(value, style = tabularStyle(T.title, FontWeight.SemiBold, color))
        }
    }
}

@Composable
private fun DashboardCount(
    label: String,
    value: Int?,
    modifier: Modifier,
    active: Boolean = false,
    selected: Boolean = false,
    onClick: () -> Unit,
) {
    Column(modifier.clickable(onClick = onClick)
        .heightIn(min = dim.touchTarget).padding(horizontal = 3.dp, vertical = 5.dp),
        horizontalAlignment = Alignment.CenterHorizontally) {
        Text(value?.toString() ?: "—", style = tabularStyle(
            T.headline, FontWeight.SemiBold,
            when {
                active -> C.OnBrandAccent
                selected -> C.OnBrand
                else -> C.OnBrandMuted
            },
        ))
        Text(label, fontSize = T.mini, lineHeight = T.mini * 1.35f,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
            color = if (selected) C.OnBrand else C.OnBrandMuted, maxLines = 1)
        Box(
            Modifier.padding(top = 4.dp).width(if (selected) 20.dp else 14.dp).height(2.dp)
                .clip(RoundedCornerShape(dim.radiusPill))
                .background(if (selected) C.OnBrand else C.OnBrandTranslucent),
        )
    }
}

@Composable
private fun DashboardNotificationButton(
    unreadNotifications: Int,
    onOpenNotifications: () -> Unit,
) {
    Box(
        modifier = Modifier.size(dim.touchTarget)
            .clip(RoundedCornerShape(dim.radiusSm))
            .clickable(onClick = onOpenNotifications),
        contentAlignment = Alignment.Center,
    ) {
        MerchantAssetIcon(
            R.drawable.merchant_notification,
            contentDescription = "通知消息",
            size = 24.dp,
        )
        if (unreadNotifications > 0) {
            Box(
                Modifier.align(Alignment.TopEnd).padding(top = 4.dp, end = 4.dp)
                    .background(C.Danger, RoundedCornerShape(dim.radiusPill))
                    .padding(horizontal = 3.dp, vertical = 1.dp),
            ) {
                Text(
                    if (unreadNotifications > 99) "99+" else unreadNotifications.toString(),
                    fontSize = T.mini, lineHeight = T.mini * 1.2f, color = C.OnBrand,
                )
            }
        }
    }
}

@Composable
private fun RecentOrderRow(order: RecentOrder, onClick: () -> Unit) {
    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(dim.radiusSm)).clickable(onClick = onClick)
        .padding(horizontal = 12.dp, vertical = 10.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            Text(order.contactName.ifBlank { "未填写" }, Modifier.weight(1f), fontSize = T.body,
                lineHeight = T.body * 1.35f, fontWeight = FontWeight.SemiBold, color = C.TextPrimary,
                maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(money(order.totalFen), style = tabularStyle(T.section, FontWeight.SemiBold, C.Price))
        }
        Text(
            buildAnnotatedString {
                withStyle(SpanStyle(color = chipColors(orderStatusTone(order.orderStatus)).first)) {
                    append(orderStatusLabel(order.orderStatus, order.orderStatusText))
                }
                append(" · ")
                withStyle(SpanStyle(color = chipColors(paymentTone(order.paymentState)).first)) {
                    append(paymentText(order.paymentState))
                }
                append(" · ")
                append(deliveryText(order.deliveryMode, order.expectedAt))
            },
            fontSize = T.caption, lineHeight = T.caption * 1.35f, color = C.TextSecondary,
        )
        Text(order.summary.ifBlank { "（无商品明细）" }, fontSize = T.body, lineHeight = T.body * 1.35f,
            color = C.TextPrimary, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Text("${order.maskedPhone} · ${order.address}", fontSize = T.caption, lineHeight = T.caption * 1.35f,
            color = C.TextSecondary, maxLines = 1, overflow = TextOverflow.Ellipsis)
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("#${order.orderNo}", Modifier.weight(1f), fontSize = T.mini, lineHeight = T.mini * 1.35f,
                color = C.TextMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            if (order.receivableFen > 0) {
                Text("未收款 ${money(order.receivableFen)}", fontSize = T.caption, lineHeight = T.caption * 1.35f,
                    color = C.Warning, fontWeight = FontWeight.Medium)
            }
        }
        val revisionNotice = when (order.revisionState) {
            "PENDING_TOPUP" -> "改单待补款 · 到账核实前不能配送"
            "APPLYING" -> "改单待退款 · 退款登记前不能配送"
            "COMMITTED" -> "顾客已改单 · 请按最新明细配送"
            else -> null
        }
        revisionNotice?.let { Text(it, fontSize = T.mini, lineHeight = T.mini * 1.35f, color = C.Warning) }
    }
}
