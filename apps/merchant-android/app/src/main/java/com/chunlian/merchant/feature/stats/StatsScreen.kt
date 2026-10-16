package com.chunlian.merchant.feature.stats

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
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.IconButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
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
import com.chunlian.merchant.data.model.Receivable
import com.chunlian.merchant.data.model.StatsData
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MetricCard
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.SectionTitle
import com.chunlian.merchant.ui.components.AutoRefreshEffect
import com.chunlian.merchant.ui.components.PullToRefreshLayout
import com.chunlian.merchant.ui.components.money
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import kotlin.math.abs

/**
 * 营业统计（方案 §6.3 UI-A11）。
 *
 * 【口径必须与后端一致，客户端不做任何聚合】
 * 「今日实收 = 当天在线确认入账 + 当天 COD 实收 − 当天已成功退款」，
 * 按**资金发生日**归集；而「今日订单数」按**下单日**归集。
 * 两者不能互相推导，所以后端分别给出，这里也只负责展示。
 * 客户端自己加总最容易出的错是：把「待收货款」也算进实收 ——
 * 那是钱还没到手。
 */
class StatsViewModel(private val repo: MerchantRepository) : ViewModel() {

    enum class Range(val label: String, val days: Int) {
        Today("今天", 0),
        Week("近 7 天", 6),
        Month("近 30 天", 29),
    }

    data class UiState(
        val loading: Boolean = true,
        val refreshing: Boolean = false,
        val error: String? = null,
        val stats: StatsData? = null,
        val receivables: List<Receivable> = emptyList(),
        val range: Range = Range.Week,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private val requestGate = StatsRequestGate()
    private var loadJob: Job? = null

    fun setRange(range: Range) {
        if (_state.value.range == range) return
        _state.value = _state.value.copy(range = range, stats = null, receivables = emptyList())
        load()
    }

    fun load() {
        val hasData = _state.value.stats != null
        val requestId = requestGate.begin()
        val days = _state.value.range.days
        loadJob?.cancel()
        _state.value = _state.value.copy(
            loading = !hasData,
            refreshing = hasData,
            error = null,
        )

        loadJob = viewModelScope.launch {
            try {
                val range = statsDateRange(days)

                val result = loadStatsAndReceivables(
                    loadStats = { repo.stats(range.from, range.to) },
                    loadReceivables = { repo.receivables() },
                )
                if (!requestGate.isCurrent(requestId)) return@launch

                _state.value = _state.value.copy(
                    loading = false,
                    refreshing = false,
                    stats = result.stats,
                    receivables = result.receivables,
                    error = null,
                )
            } catch (e: CancellationException) {
                throw e
            } catch (e: NetworkException) {
                if (requestGate.isCurrent(requestId)) {
                    _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message)
                }
            } catch (e: Exception) {
                if (requestGate.isCurrent(requestId)) {
                    _state.value = _state.value.copy(loading = false, refreshing = false, error = e.message ?: "加载失败")
                }
            }
        }
    }
}

internal data class StatsDateRange(val from: String, val to: String)

/** Dates sent to the API must follow the shop's business day, regardless of device timezone. */
internal fun statsDateRange(days: Int, now: Date = Date()): StatsDateRange {
    require(days >= 0) { "days must be non-negative" }
    val businessTimeZone = TimeZone.getTimeZone("Asia/Shanghai")
    val formatter = SimpleDateFormat("yyyy-MM-dd", Locale.CHINA).apply {
        timeZone = businessTimeZone
    }
    val from = Calendar.getInstance(businessTimeZone, Locale.CHINA).apply {
        time = now
        add(Calendar.DAY_OF_YEAR, -days)
    }.time
    return StatsDateRange(from = formatter.format(from), to = formatter.format(now))
}

/** Only the newest range request may publish results or errors. */
internal class StatsRequestGate {
    private var latestRequestId = 0

    fun begin(): Int = ++latestRequestId

    fun isCurrent(requestId: Int): Boolean = requestId == latestRequestId
}

internal data class StatsLoadResult(
    val stats: StatsData,
    val receivables: List<Receivable>,
)

/** A receivables failure must fail the whole dashboard load, never masquerade as zero receivables. */
internal suspend fun loadStatsAndReceivables(
    loadStats: suspend () -> StatsData,
    loadReceivables: suspend () -> List<Receivable>,
): StatsLoadResult = StatsLoadResult(
    stats = loadStats(),
    receivables = loadReceivables(),
)

@Composable
fun StatsScreen(onBack: () -> Unit, onOpenLedger: () -> Unit) {
    val vm = androidx.lifecycle.viewmodel.compose.viewModel {
        StatsViewModel(MerchantApp.instance.repository)
    }
    val state by vm.state.collectAsState()

    LaunchedEffect(Unit) { vm.load() }
    AutoRefreshEffect(onRefresh = { if (!state.loading && !state.refreshing) vm.load() })

    PullToRefreshLayout(
        refreshing = state.refreshing,
        onRefresh = { if (!state.loading && !state.refreshing) vm.load() },
        modifier = Modifier.fillMaxSize().background(C.Canvas),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .safeDrawingPadding()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = dim.pagePadding),
        ) {
        MerchantTopBar(
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(onClick = onBack) {
                    MerchantBackIcon()
                }
                Spacer(Modifier.width(2.dp))
                MerchantAssetIcon(R.drawable.merchant_stats, contentDescription = null, size = 24.dp)
                Spacer(Modifier.width(8.dp))
                Text("营业统计", fontSize = T.title, fontWeight = FontWeight.Bold, color = C.Brand700)
            }
            TextButton(onClick = onOpenLedger) { Text("资金流水", color = C.Brand700) }
        }

        Spacer(Modifier.height(12.dp))

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            StatsViewModel.Range.entries.forEach { range ->
                val active = state.range == range
                Box(
                    modifier = Modifier
                        .background(if (active) C.Brand700 else C.Surface, RoundedCornerShape(dim.radiusPill))
                        .clickable { vm.setRange(range) }
                        .padding(horizontal = 16.dp, vertical = 8.dp),
                ) {
                    Text(
                        range.label,
                        fontSize = T.caption,
                        color = if (active) C.OnBrand else C.TextSecondary,
                        fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                    )
                }
            }
        }

        Spacer(Modifier.height(16.dp))

        if (state.error != null) {
            NoticeBar(state.error!!, ChipTone.Danger)
            Spacer(Modifier.height(12.dp))
        }

        val stats = state.stats
        when {
            state.loading && stats == null -> LoadingBox()

            stats == null -> EmptyBox(
                title = "没能加载统计数据",
                description = (state.error ?: "请检查网络后重试。") + " 下拉页面即可重新加载。",
            )

            else -> {
                MetricCard(
                    label = "${stats.from} 至 ${stats.to} 实收合计",
                    value = money(stats.receivedTotalFen),
                    highlight = true,
                    modifier = Modifier.fillMaxWidth().heightIn(min = dim.revenueCardHeight),
                )

                Spacer(Modifier.height(12.dp))

                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    MetricCard(
                        label = "订单数",
                        value = stats.orderCount.toString(),
                        modifier = Modifier.weight(1f),
                    )
                    MetricCard(
                        label = "已成功退款",
                        value = money(abs(stats.refundedFen)),
                        modifier = Modifier.weight(1f),
                    )
                }

                Spacer(Modifier.height(12.dp))

                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    MetricCard(
                        label = "未收款",
                        value = money(stats.receivableFen),
                        hint = "${stats.receivableCount} 单未收",
                        alert = stats.receivableFen > 0,
                        modifier = Modifier.weight(1f),
                    )
                    MetricCard(
                        label = "客单价",
                        value = if (stats.orderCount > 0) {
                            money(stats.receivedTotalFen / stats.orderCount)
                        } else {
                            "—"
                        },
                        hint = "按实收 / 订单数",
                        modifier = Modifier.weight(1f),
                    )
                }

                Spacer(Modifier.height(20.dp))
                SectionTitle("未收款订单")

                if (state.receivables.isEmpty()) {
                    CardBox {
                        Column(Modifier.padding(16.dp)) {
                            Text("当前没有未收款订单", fontSize = T.caption, color = C.Success)
                        }
                    }
                } else {
                    CardBox {
                        Column(Modifier.padding(14.dp)) {
                            state.receivables.forEach { item ->
                                Row(
                                    modifier = Modifier.fillMaxWidth().padding(vertical = 6.dp),
                                    horizontalArrangement = Arrangement.SpaceBetween,
                                ) {
                                    Column(Modifier.weight(1f)) {
                                        Text("#${item.orderNo}", fontSize = T.caption, fontWeight = FontWeight.Medium)
                                        Text(
                                            "${item.contactName} · ${item.maskedPhone}",
                                            fontSize = T.mini,
                                            color = C.TextMuted,
                                        )
                                    }
                                    Text(
                                        money(item.receivableFen),
                                        fontSize = T.caption,
                                        fontWeight = FontWeight.SemiBold,
                                        color = C.Warning,
                                    )
                                }
                            }

                            Spacer(Modifier.height(8.dp))
                            Text(
                                "这些钱还没到手，不计入「实收」。请到订单详情补记收款。",
                                fontSize = T.mini,
                                color = C.TextMuted,
                                lineHeight = 16.sp,
                            )
                        }
                    }
                }

                Spacer(Modifier.height(14.dp))
                Text(
                    "统计口径：按资金发生日；实收扣除成功退款，未收款不计入",
                    fontSize = T.mini,
                    color = C.TextMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )

                Spacer(Modifier.height(24.dp))
            }
        }
        }
    }
}
