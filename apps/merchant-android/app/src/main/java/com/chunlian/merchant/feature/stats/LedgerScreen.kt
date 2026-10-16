package com.chunlian.merchant.feature.stats

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
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
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.data.model.LedgerEntry
import com.chunlian.merchant.data.model.LedgerPage
import com.chunlian.merchant.data.model.LedgerSummary
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.StatusChip
import com.chunlian.merchant.ui.components.AutoRefreshEffect
import com.chunlian.merchant.ui.components.PullToRefreshLayout
import com.chunlian.merchant.ui.components.deltaMoney
import com.chunlian.merchant.ui.components.money
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlin.math.abs

private const val LEDGER_PAGE_SIZE = 20

class LedgerViewModel(private val repo: MerchantRepository) : ViewModel() {
    data class UiState(
        val loading: Boolean = true,
        val error: String? = null,
        val data: LedgerPage? = null,
        val requestedPage: Int = 1,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var latestRequestId = 0
    private var loadJob: Job? = null

    fun load() = loadPage(_state.value.data?.page ?: 1)

    fun refresh() = loadPage(1)

    fun retry() = loadPage(_state.value.requestedPage)

    fun previousPage() {
        val data = _state.value.data ?: return
        if (! _state.value.loading && data.page > 1) loadPage(data.page - 1)
    }

    fun nextPage() {
        val data = _state.value.data ?: return
        val pageCount = pageCount(data.total, data.pageSize)
        if (! _state.value.loading && data.page < pageCount) loadPage(data.page + 1)
    }

    private fun loadPage(page: Int) {
        val requestId = ++latestRequestId
        loadJob?.cancel()
        _state.value = _state.value.copy(loading = true, error = null, requestedPage = page)
        loadJob = viewModelScope.launch {
            try {
                val result = repo.ledger(page = page, pageSize = LEDGER_PAGE_SIZE)
                if (requestId != latestRequestId) return@launch
                _state.value = UiState(loading = false, data = result, requestedPage = result.page)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                if (requestId == latestRequestId) {
                    _state.value = _state.value.copy(loading = false, error = e.message ?: "流水加载失败")
                }
            }
        }
    }
}

internal fun pageCount(total: Int, pageSize: Int): Int =
    if (total <= 0 || pageSize <= 0) 1 else (total + pageSize - 1) / pageSize

@Composable
fun LedgerScreen(onBack: () -> Unit) {
    val vm = viewModel { LedgerViewModel(MerchantApp.instance.repository) }
    val state by vm.state.collectAsState()

    LaunchedEffect(Unit) { vm.load() }
    AutoRefreshEffect(onRefresh = { if (!state.loading) vm.load() })

    PullToRefreshLayout(
        refreshing = state.loading && state.data != null,
        onRefresh = { if (!state.loading) vm.refresh() },
        modifier = Modifier.fillMaxSize().background(C.Canvas),
    ) {
        Column(
            modifier = Modifier
                .fillMaxSize()
                .safeDrawingPadding(),
        ) {
        MerchantTopBar(Modifier.padding(end = 12.dp)) {
            IconButton(onClick = onBack) {
                MerchantBackIcon()
            }
            MerchantAssetIcon(R.drawable.merchant_payment, contentDescription = null, size = 22.dp)
            Spacer(Modifier.width(8.dp))
            Column(Modifier.weight(1f)) {
                Text("资金流水", fontSize = T.section, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                Text("只读账目记录", fontSize = T.mini, color = C.TextSecondary)
            }
        }

        val data = state.data
        val errorMessage = state.error
        when {
            state.loading && data == null -> LoadingBox(Modifier.fillMaxSize())
            data == null -> LazyColumn(Modifier.fillMaxSize()) {
                item {
                    EmptyBox(
                        title = "没能加载资金流水",
                        description = (state.error ?: "请检查网络后重试。") + " 下拉页面即可重新加载。",
                        tone = ChipTone.Danger,
                    )
                }
            }
            else -> LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = PaddingValues(start = dim.pagePadding, end = dim.pagePadding, bottom = 24.dp),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                if (errorMessage != null) {
                    item(key = "error") {
                        Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            NoticeBar(errorMessage, ChipTone.Danger)
                        }
                    }
                }

                item(key = "summary") { LedgerSummaryCard(data.summary) }

                item(key = "list-title") {
                    Text("流水明细 · 共 ${data.total} 笔", fontSize = T.section, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                }

                if (data.list.isEmpty()) {
                    item(key = "empty") {
                        EmptyBox(
                            title = "暂时没有资金流水",
                            description = "收款和退款记录会按发生时间倒序显示。下拉页面查看最新记录。",
                        )
                    }
                } else {
                    items(data.list, key = { it.id }) { entry ->
                        LedgerEntryCard(entry)
                    }
                }

                item(key = "pagination") {
                    LedgerPagination(
                        page = data.page,
                        total = data.total,
                        pageSize = data.pageSize,
                        loading = state.loading,
                        onPrevious = { vm.previousPage() },
                        onNext = { vm.nextPage() },
                    )
                }
            }
        }
        }
    }
}

@Composable
private fun LedgerSummaryCard(summary: LedgerSummary) {
    CardBox {
        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            Text("分列汇总", fontSize = T.body, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                SummaryValue("成功入账", money(summary.inflowFen), C.Success, Modifier.weight(1f))
                SummaryValue("成功退款", money(abs(summary.outflowFen)), C.Danger, Modifier.weight(1f))
            }
            Row(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                SummaryValue("成功净额", deltaMoney(summary.netFen), C.Brand700, Modifier.weight(1f))
                SummaryValue("处理中", "${summary.inFlightCount} 笔", C.Warning, Modifier.weight(1f))
            }
            Text("入账与退款分别统计；处理中记录不计入成功金额。", fontSize = T.mini, color = C.TextMuted)
        }
    }
}

@Composable
private fun SummaryValue(label: String, value: String, color: Color, modifier: Modifier = Modifier) {
    Column(modifier = modifier.padding(vertical = 2.dp), verticalArrangement = Arrangement.spacedBy(3.dp)) {
        Text(label, fontSize = T.mini, color = C.TextSecondary)
        Text(value, fontSize = T.body, fontWeight = FontWeight.SemiBold, color = color)
    }
}

@Composable
private fun LedgerEntryCard(entry: LedgerEntry) {
    val amountColor = when {
        entry.amountFen > 0 -> C.Success
        entry.amountFen < 0 -> C.Danger
        else -> C.TextSecondary
    }
    CardBox {
        Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Text(
                    entry.kindLabel.ifBlank { ledgerKindLabel(entry.kind) },
                    modifier = Modifier.weight(1f),
                    fontSize = T.body,
                    fontWeight = FontWeight.SemiBold,
                    color = C.TextPrimary,
                )
                Spacer(Modifier.padding(horizontal = 3.dp))
                StatusChip(entry.statusLabel.ifBlank { ledgerStatusLabel(entry.status) }, ledgerStatusTone(entry.status))
            }
            Row(
                modifier = Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.SpaceBetween,
                verticalAlignment = Alignment.CenterVertically,
            ) {
                val orderNumber = entry.orderNo.ifBlank { entry.orderId }
                Text(
                    if (orderNumber.isBlank()) "订单号 —" else "订单号 #$orderNumber",
                    modifier = Modifier.weight(1f),
                    fontSize = T.caption,
                    color = C.TextSecondary,
                )
                Text(deltaMoney(entry.amountFen), fontSize = T.body, fontWeight = FontWeight.Bold, color = amountColor)
            }
            Text("发生时间  ${entry.occurredAt.ifBlank { "—" }}", fontSize = T.mini, color = C.TextMuted)
            entry.collectionMethod?.takeIf { it.isNotBlank() }?.let { method ->
                Text("收款方式  ${collectionMethodLabel(method)}", fontSize = T.mini, color = C.TextMuted)
            }
        }
    }
}

@Composable
private fun LedgerPagination(
    page: Int,
    total: Int,
    pageSize: Int,
    loading: Boolean,
    onPrevious: () -> Unit,
    onNext: () -> Unit,
) {
    val pages = pageCount(total, pageSize)
    CardBox {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = 8.dp, vertical = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.SpaceBetween,
        ) {
            Text("第 $page / $pages 页", fontSize = T.mini, color = C.TextSecondary, textAlign = TextAlign.Start)
            Row(verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = onPrevious, enabled = !loading && page > 1) { Text("上一页") }
                TextButton(onClick = onNext, enabled = !loading && page < pages) { Text("下一页") }
            }
        }
    }
}

private fun ledgerStatusLabel(status: String): String = when (status) {
    "SUCCESS" -> "已成功"
    "PROCESSING" -> "处理中"
    "PENDING" -> "待处理"
    "FAILED" -> "已失败"
    else -> status.ifBlank { "未知状态" }
}

private fun ledgerStatusTone(status: String): ChipTone = when (status) {
    "SUCCESS" -> ChipTone.Success
    "PENDING", "PROCESSING" -> ChipTone.Warning
    "FAILED" -> ChipTone.Danger
    else -> ChipTone.Muted
}

private fun ledgerKindLabel(kind: String): String = when (kind) {
    "WX_PAY" -> "微信支付"
    "TOPUP" -> "改单补款"
    "WX_REFUND" -> "退款"
    "COD_RECEIPT" -> "货到付款收款"
    "OFFLINE_RECEIPT" -> "扫码收款核实"
    "OFFLINE_REFUND" -> "人工退款"
    else -> "其他资金变动"
}

private fun collectionMethodLabel(method: String): String = when (method) {
    "CASH" -> "现金"
    "OFFLINE_QR" -> "线下扫码"
    "OTHER" -> "其他"
    else -> method
}
