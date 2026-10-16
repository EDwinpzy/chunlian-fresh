package com.chunlian.merchant.feature.orders

import android.content.Intent
import android.net.Uri
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
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.IconButton
import androidx.compose.material3.RadioButton
import androidx.compose.material3.RadioButtonDefaults
import androidx.compose.material3.Text
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
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalClipboardManager
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.core.network.ApiException
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.model.CollectionMethod
import com.chunlian.merchant.data.model.Order
import com.chunlian.merchant.data.model.RevisionRecord
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.DividerLine
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.GhostButton
import com.chunlian.merchant.ui.components.KeyValueRow
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.PrimaryButton
import com.chunlian.merchant.ui.components.SecondaryButton
import com.chunlian.merchant.ui.components.SectionTitle
import com.chunlian.merchant.ui.components.StatusChip
import com.chunlian.merchant.ui.components.deliveryText
import com.chunlian.merchant.ui.components.fulfillmentText
import com.chunlian.merchant.ui.components.fulfillmentTone
import com.chunlian.merchant.ui.components.money
import com.chunlian.merchant.ui.components.paymentMethodText
import com.chunlian.merchant.ui.components.paymentText
import com.chunlian.merchant.ui.components.paymentTone
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import coil.compose.AsyncImage

/**
 * 订单详情（方案 §6.2 UI-A03 + §3.6 商家「确认送达及收款」合并流程）。
 *
 * 【本项目资金安全上最关键的一个页面】
 * 三条不变量必须同时成立：
 *   1. 「已收款并送达」默认选中；收款渠道仍须店主明确选择，避免自动登记收款方式。
 *   2. 收款金额由**服务端**以订单应收为准，客户端只做展示与渠道选择。
 *      客户端传的金额只用于显示，服务端会再校验一次必须等于应收。
 *   3. 幂等键在**点击那一刻**生成，重试复用 —— 否则信号差时点两次
 *      就会记两笔现金收入。
 */
class OrderDetailViewModel(
    private val repo: MerchantRepository,
    private val orderId: String,
) : ViewModel() {

    data class UiState(
        val loading: Boolean = true,
        val error: String? = null,
        val order: Order? = null,
        val revisionHistory: List<RevisionRecord> = emptyList(),
        val paymentQrUrl: String = "",
        val acting: Boolean = false,
        val message: String? = null,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()

    fun load() {
        _state.value = _state.value.copy(loading = _state.value.order == null, error = null)

        viewModelScope.launch {
            try {
                val order = repo.orderDetail(orderId)
                val history = runCatching { repo.revisions(orderId).list }.getOrDefault(emptyList())
                val paymentQrUrl = if (order.paymentMethod == "OFFLINE_QR") {
                    runCatching { repo.store().paymentQrUrl }.getOrDefault("")
                } else {
                    ""
                }
                _state.value = _state.value.copy(
                    loading = false,
                    order = order,
                    revisionHistory = history,
                    paymentQrUrl = paymentQrUrl,
                    error = null,
                )
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = e.message ?: "加载失败")
            }
        }
    }

    /** 开始配送 */
    fun dispatch() {
        val order = _state.value.order ?: return
        if (_state.value.acting) return

        _state.value = _state.value.copy(acting = true, error = null)
        viewModelScope.launch {
            try {
                val updated = repo.dispatch(order.id, order.version, ApiClient.idempotencyKey())
                _state.value = _state.value.copy(acting = false, order = updated, message = "已开始配送")
            } catch (e: ApiException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
                // 版本冲突/待补款都要重新拉一次最新状态，否则店主会一直点同一个错
                load()
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
            }
        }
    }

    /**
     * 确认送达。
     *
     * @param collectedOnDelivery COD 是否同时登记收款；在线已付款单必须传 false
     */
    fun confirmDelivery(collectedOnDelivery: Boolean, method: CollectionMethod?) {
        val order = _state.value.order ?: return
        if (_state.value.acting) return

        _state.value = _state.value.copy(acting = true, error = null)
        viewModelScope.launch {
            try {
                val updated = repo.confirmDelivery(
                    id = order.id,
                    version = order.version,
                    collectedOnDelivery = collectedOnDelivery,
                    collectionMethod = method?.value,
                    // 金额交给服务端按应收算：本地缓存稍旧就会少收/多收却显示成功
                    collectedFen = if (collectedOnDelivery) order.receivableFen else null,
                    idempotencyKey = ApiClient.idempotencyKey(),
                )
                _state.value = _state.value.copy(
                    acting = false,
                    order = updated,
                    message = when {
                        updated.receivableFen > 0 -> "已送达（货款未收，已记入应收）"
                        collectedOnDelivery -> "已送达并登记收款"
                        else -> "已送达（货款已结清）"
                    },
                )
            } catch (e: ApiException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
                load()
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
            }
        }
    }

    /** 送达后补记 COD 收款（纠错入口） */
    fun recordCodReceipt(method: CollectionMethod) {
        val order = _state.value.order ?: return
        if (_state.value.acting) return

        _state.value = _state.value.copy(acting = true, error = null)
        viewModelScope.launch {
            try {
                val updated = repo.codReceipt(
                    id = order.id,
                    version = order.version,
                    collectedFen = order.receivableFen,
                    collectionMethod = method.value,
                    note = null,
                    idempotencyKey = ApiClient.idempotencyKey(),
                )
                _state.value = _state.value.copy(acting = false, order = updated, message = "已补记收款")
            } catch (e: ApiException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
                load()
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
            }
        }
    }

    fun confirmOfflineQrReceipt(amountFen: Long) {
        val order = _state.value.order ?: return
        if (_state.value.acting || amountFen <= 0) return
        _state.value = _state.value.copy(acting = true, error = null)
        viewModelScope.launch {
            try {
                repo.confirmQrReceipt(order.id, order.version, amountFen, ApiClient.idempotencyKey())
                _state.value = _state.value.copy(acting = false, message = "扫码到账已登记")
                load()
            } catch (e: ApiException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
                load()
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
            }
        }
    }

    fun recordManualRefund(revisionId: String, amountFen: Long) {
        val order = _state.value.order ?: return
        if (_state.value.acting || revisionId.isBlank() || amountFen <= 0) return
        _state.value = _state.value.copy(acting = true, error = null)
        viewModelScope.launch {
            try {
                repo.manualRefund(
                    id = order.id,
                    version = order.version,
                    amountFen = amountFen,
                    idempotencyKey = ApiClient.idempotencyKey(),
                )
                _state.value = _state.value.copy(acting = false, message = "线下退款已登记")
                load()
            } catch (e: ApiException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
                load()
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(acting = false, error = e.message)
            }
        }
    }

    fun consumeMessage() {
        _state.value = _state.value.copy(message = null)
    }

    fun consumeError() {
        _state.value = _state.value.copy(error = null)
    }
}

@Composable
fun OrderDetailScreen(orderId: String, onBack: () -> Unit) {
    val vm = androidx.lifecycle.viewmodel.compose.viewModel {
        OrderDetailViewModel(MerchantApp.instance.repository, orderId)
    }
    val state by vm.state.collectAsState()

    val context = LocalContext.current
    val clipboard = LocalClipboardManager.current

    var showCodSheet by remember { mutableStateOf(false) }
    var showReceiptSheet by remember { mutableStateOf(false) }
    var confirmQrReceipt by remember { mutableStateOf(false) }
    var confirmManualRefund by remember { mutableStateOf(false) }

    LaunchedEffect(orderId) { vm.load() }

    val order = state.order
    val pendingQrTopup = state.revisionHistory.lastOrNull { it.status == "PENDING_TOPUP" }
    val pendingManualRefund = order?.pendingManualRefunds?.firstOrNull()
    val qrReceiptAmountFen = if (order?.revisionState == "PENDING_TOPUP") {
        pendingQrTopup?.deltaFen ?: 0L
    } else {
        order?.receivableFen ?: 0L
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .background(C.Canvas)
            .safeDrawingPadding(),
    ) {
        Column(Modifier.fillMaxSize()) {
            // ---------- 顶部栏 ----------
            MerchantTopBar(Modifier.padding(horizontal = 8.dp)) {
                IconButton(onClick = onBack) {
                    MerchantBackIcon()
                }
                Text(
                    "订单详情 ${if (order != null) "#${order.orderNo}" else ""}",
                    fontSize = T.section,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
                if (order != null) {
                    StatusChip(
                        "v${order.version}",
                        ChipTone.Muted,
                    )
                }
            }

            if (state.error != null) {
                Box(Modifier.padding(horizontal = dim.pagePadding)) {
                    NoticeBar(state.error!!, ChipTone.Danger)
                }
                Spacer(Modifier.height(8.dp))
            }

            when {
                state.loading -> LoadingBox()

                order == null -> EmptyBox(
                    title = "订单不存在或无权查看",
                    actionText = "返回",
                    onAction = onBack,
                )

                else -> Column(
                    modifier = Modifier
                        .weight(1f)
                        .verticalScroll(rememberScrollState())
                        .padding(horizontal = dim.pagePadding),
                ) {
                    val fullPhone = order.contact.phone.trim()
                    Spacer(Modifier.height(8.dp))

                    // ---------- 双状态 ----------
                    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        StatusChip(fulfillmentText(order.fulfillment), fulfillmentTone(order.fulfillment))
                        StatusChip(
                            "${paymentMethodText(order.paymentMethod)} · ${paymentText(order.paymentState)}",
                            paymentTone(order.paymentState),
                        )
                    }

                    if (order.hasPendingTopup || order.hasRefundAwaitingVerification) {
                        Spacer(Modifier.height(10.dp))
                        NoticeBar(
                            when {
                                order.hasPendingTopup -> "顾客订单变更待补款，补款确认前不能开始配送。"
                                else -> "顾客订单变更待核实退款，完成退款登记前不能开始配送。"
                            },
                            ChipTone.Warning,
                        )
                    }

                    Spacer(Modifier.height(14.dp))

                    // ---------- 收货信息 ----------
                    CardBox {
                        Column(Modifier.padding(14.dp)) {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Text(
                                    "${order.contact.name.ifBlank { "未填写" }} · ${order.contact.maskedPhone}",
                                    fontSize = T.product,
                                    fontWeight = FontWeight.SemiBold,
                                )
                                Row {
                                    IconButton(
                                        enabled = fullPhone.isNotBlank(),
                                        onClick = {
                                            context.startActivity(
                                                Intent(Intent.ACTION_DIAL, Uri.parse("tel:$fullPhone")),
                                            )
                                        },
                                    ) {
                                        MerchantAssetIcon(
                                            R.drawable.merchant_phone,
                                            contentDescription = "拨号",
                                            size = 24.dp,
                                        )
                                    }
                                    IconButton(
                                        enabled = fullPhone.isNotBlank(),
                                        onClick = {
                                            clipboard.setText(
                                                AnnotatedString(
                                                    listOf(order.contact.name, fullPhone, order.contact.address)
                                                        .filter(String::isNotBlank)
                                                        .joinToString(" "),
                                                ),
                                            )
                                        },
                                    ) {
                                        MerchantAssetIcon(
                                            R.drawable.merchant_copy,
                                            contentDescription = "复制联系方式和地址",
                                            size = 24.dp,
                                        )
                                    }
                                }
                            }

                            Text(order.contact.address, fontSize = T.body, lineHeight = 22.sp)

                            Spacer(Modifier.height(6.dp))

                            Text(
                                "希望送达：${deliveryText(order.deliveryMode, order.expectedAt)}",
                                fontSize = T.caption,
                                color = C.TextSecondary,
                            )

                            if (order.remark.isNotBlank()) {
                                Text("备注：${order.remark}", fontSize = T.caption, color = C.TextSecondary)
                            }

                            Spacer(Modifier.height(4.dp))
                            Text(
                                if (fullPhone.isBlank()) {
                                    "订单未提供完整电话号码，拨号与复制已禁用。"
                                } else {
                                    "可拨打或复制完整电话；拨号会打开系统拨号盘供确认。"
                                },
                                fontSize = T.mini,
                                color = C.TextMuted,
                            )
                        }
                    }

                    // ---------- 商品清单 ----------
                    Spacer(Modifier.height(20.dp))
                    SectionTitle("商品清单（最新版本）")

                    CardBox {
                        Column(Modifier.padding(14.dp)) {
                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Spacer(Modifier.width(60.dp))
                                Text(
                                    "商品",
                                    modifier = Modifier.weight(1f).padding(end = 8.dp),
                                    fontSize = T.mini,
                                    color = C.TextMuted,
                                )
                                Text(
                                    "数量",
                                    modifier = Modifier.width(48.dp),
                                    fontSize = T.mini,
                                    color = C.TextMuted,
                                    textAlign = TextAlign.Center,
                                )
                                Text(
                                    "小计",
                                    modifier = Modifier.width(72.dp),
                                    fontSize = T.mini,
                                    color = C.TextMuted,
                                    textAlign = TextAlign.End,
                                )
                            }
                            Spacer(Modifier.height(8.dp))

                            order.lines.forEachIndexed { index, line ->
                                Row(
                                    modifier = Modifier.fillMaxWidth(),
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Box(
                                        modifier = Modifier
                                            .size(52.dp)
                                            .clip(RoundedCornerShape(10.dp))
                                            .background(C.Brand100),
                                        contentAlignment = Alignment.Center,
                                    ) {
                                        if (line.imageSnapshot.isNotBlank()) {
                                            AsyncImage(
                                                model = MerchantApp.instance.repository.absoluteUrl(line.imageSnapshot),
                                                contentDescription = line.nameSnapshot,
                                                contentScale = ContentScale.Crop,
                                                modifier = Modifier.fillMaxSize(),
                                            )
                                        } else {
                                            Text(
                                                line.nameSnapshot.take(1),
                                                fontSize = T.section,
                                                fontWeight = FontWeight.SemiBold,
                                                color = C.Brand700.copy(alpha = 0.35f),
                                            )
                                        }
                                    }
                                    Spacer(Modifier.width(8.dp))
                                    Column(
                                        modifier = Modifier.weight(1f).padding(end = 8.dp),
                                        verticalArrangement = Arrangement.spacedBy(2.dp),
                                    ) {
                                        Text(
                                            line.nameSnapshot,
                                            fontSize = T.caption,
                                            fontWeight = FontWeight.Medium,
                                            maxLines = 2,
                                        )
                                        Text(
                                            line.specSnapshot,
                                            fontSize = T.mini,
                                            color = C.TextMuted,
                                            maxLines = 1,
                                        )
                                    }
                                    Box(
                                        modifier = Modifier
                                            .width(48.dp)
                                            .background(C.Brand50, RoundedCornerShape(8.dp))
                                            .padding(vertical = 6.dp),
                                        contentAlignment = Alignment.Center,
                                    ) {
                                        Text(
                                            "×${line.qty}",
                                            fontSize = T.caption,
                                            fontWeight = FontWeight.Bold,
                                            color = C.Brand700,
                                            textAlign = TextAlign.Center,
                                            maxLines = 1,
                                        )
                                    }
                                    Text(
                                        money(line.subtotalFen),
                                        modifier = Modifier.width(72.dp),
                                        fontSize = T.mini,
                                        fontWeight = FontWeight.Medium,
                                        textAlign = TextAlign.End,
                                        maxLines = 1,
                                    )
                                }
                                if (index < order.lines.lastIndex) {
                                    Spacer(Modifier.height(8.dp))
                                    DividerLine()
                                    Spacer(Modifier.height(8.dp))
                                }
                            }

                            Spacer(Modifier.height(6.dp))
                            DividerLine()
                            Spacer(Modifier.height(6.dp))

                            KeyValueRow("商品小计", money(order.goodsTotalFen))
                            KeyValueRow("配送费", money(order.deliveryFeeFen))
                            KeyValueRow("订单金额", money(order.totalFen), emphasize = true)
                            KeyValueRow("已实付", money(order.paidFen))
                            if (order.refundedFen > 0) KeyValueRow("已退款", money(order.refundedFen), valueColor = C.Danger)
                            if (order.refundingFen > 0) KeyValueRow("退款处理中", money(order.refundingFen), valueColor = C.Warning)

                            Spacer(Modifier.height(4.dp))
                            DividerLine()
                            Spacer(Modifier.height(4.dp))

                            KeyValueRow(
                                "应收金额",
                                money(order.receivableFen),
                                emphasize = true,
                                valueColor = if (order.receivableFen > 0) C.Price else C.Success,
                            )
                            KeyValueRow(
                                "收款状态",
                                if (order.receivableFen > 0) "未收" else "已结清",
                                valueColor = if (order.receivableFen > 0) C.Warning else C.Success,
                            )
                        }
                    }

                    Spacer(Modifier.height(20.dp))
                    SectionTitle("订单信息")
                    CardBox {
                        Column(Modifier.padding(14.dp)) {
                            KeyValueRow("订单号", order.orderNo)
                            KeyValueRow("支付方式", paymentMethodText(order.paymentMethod))
                            KeyValueRow("付款状态", paymentText(order.paymentState))
                            KeyValueRow("履约状态", fulfillmentText(order.fulfillment))
                            KeyValueRow("订单版本", "v${order.version}")
                        }
                    }

                    if (state.revisionHistory.isNotEmpty()) {
                        Spacer(Modifier.height(20.dp))
                        SectionTitle("订单修改记录")
                        CardBox {
                            Column(Modifier.padding(14.dp)) {
                                state.revisionHistory.asReversed().forEachIndexed { index, revision ->
                                    if (index > 0) {
                                        Spacer(Modifier.height(10.dp))
                                        DividerLine()
                                        Spacer(Modifier.height(10.dp))
                                    }
                                    Row(
                                        Modifier.fillMaxWidth(),
                                        horizontalArrangement = Arrangement.SpaceBetween,
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        Text(
                                            "v${revision.fromVersion} → v${revision.toVersion}",
                                            fontSize = T.caption,
                                            fontWeight = FontWeight.SemiBold,
                                        )
                                        StatusChip(revisionStatusText(revision.status), revisionStatusTone(revision.status))
                                    }
                                    if (revision.summary.isNotBlank()) {
                                        Spacer(Modifier.height(4.dp))
                                        Text(revision.summary, fontSize = T.caption, color = C.TextSecondary)
                                    }
                                    if (revision.deltaFen != 0L) {
                                        Spacer(Modifier.height(4.dp))
                                        val increase = revision.deltaFen > 0
                                        Text(
                                            (if (increase) "应收增加 " else "应收减少 ") + money(kotlin.math.abs(revision.deltaFen)),
                                            fontSize = T.caption,
                                            color = if (increase) C.Warning else C.Success,
                                        )
                                    }
                                    Text(revision.createdAt, fontSize = T.mini, color = C.TextMuted)
                                }
                            }
                        }
                    }

                    if (order.paymentMethod == "OFFLINE_QR") {
                        val canConfirmQrReceipt = order.fulfillment == "PENDING" &&
                            ((order.revisionState == "PENDING_TOPUP" && pendingQrTopup != null && qrReceiptAmountFen > 0) ||
                                (order.paymentState == "UNPAID" && order.revisionState != "PENDING_TOPUP" && qrReceiptAmountFen > 0))

                        Spacer(Modifier.height(20.dp))
                        SectionTitle(
                            text = "线下扫码收款",
                            trailing = {
                                MerchantAssetIcon(R.drawable.merchant_payment, null, size = 20.dp)
                            },
                        )
                        CardBox {
                            Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                                if (state.paymentQrUrl.isNotBlank()) {
                                    AsyncImage(
                                        model = MerchantApp.instance.repository.absoluteUrl(state.paymentQrUrl),
                                        contentDescription = "店铺收款二维码",
                                        contentScale = ContentScale.Fit,
                                        modifier = Modifier.fillMaxWidth().height(220.dp),
                                    )
                                } else {
                                    NoticeBar("店铺还没有配置收款二维码，请先到「我的 / 店铺设置」上传。", ChipTone.Warning)
                                }
                                NoticeBar(
                                    "扫码本身不会自动确认付款。请先在收款账户核实到账，再登记收款；登记前不能开始配送。",
                                    ChipTone.Info,
                                )
                                if (order.revisionState == "PENDING_TOPUP" && pendingQrTopup == null) {
                                    NoticeBar("找不到待补款修订记录，请刷新订单或联系维护者核对。", ChipTone.Danger)
                                }
                                if (canConfirmQrReceipt) {
                                    PrimaryButton(
                                        text = if (state.acting) "登记中…" else "我已核实到账 · ${money(qrReceiptAmountFen)}",
                                        onClick = { confirmQrReceipt = true },
                                        loading = state.acting,
                                        modifier = Modifier.fillMaxWidth(),
                                    )
                                } else if (order.paymentState == "PAID" && order.revisionState != "PENDING_TOPUP") {
                                    NoticeBar("扫码预付款已登记。", ChipTone.Success)
                                } else {
                                    NoticeBar("当前订单状态不能登记新的扫码收款。", ChipTone.Muted)
                                }

                                if (pendingManualRefund != null && pendingManualRefund.id.isNotBlank()) {
                                    DividerLine()
                                    Text(
                                        "本单有待人工处理的退款 ${money(pendingManualRefund.amountFen)}。",
                                        fontSize = T.caption,
                                        color = C.Warning,
                                    )
                                    SecondaryButton(
                                        text = if (state.acting) "登记中…" else "已线下退还 · 登记退款",
                                        onClick = { confirmManualRefund = true },
                                        enabled = !state.acting,
                                        modifier = Modifier.fillMaxWidth(),
                                        leadingIconRes = R.drawable.merchant_refund,
                                    )
                                }
                            }
                        }
                    }

                    // Leave room for the fixed action bar so the final payment/refund
                    // controls in the scrollable content can be brought fully into view.
                    Spacer(Modifier.height(160.dp))
                }
            }
        }

        // ---------- 底部主操作 ----------
        if (order != null) {
            Box(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .background(C.Surface)
                    .navigationBarsPadding()
                    .padding(horizontal = dim.pagePadding, vertical = 10.dp),
            ) {
                Column {
                    when {
                        // 待配送 → 开始配送
                        order.fulfillment == "PENDING" -> {
                            if (!order.mayStartDelivery) {
                                NoticeBar(
                                    when {
                                        order.hasPendingTopup -> "顾客订单变更待补款，补款确认前不能配送。"
                                        order.hasRefundAwaitingVerification -> "顾客订单变更待核实退款，完成退款登记前不能配送。"
                                        order.hasOutstandingPrepayment && order.paymentMethod == "OFFLINE_QR" ->
                                            "扫码预付款尚未核实足额到账，核实到账后才能配送。"
                                        order.hasOutstandingPrepayment -> "在线付款尚未确认，到账后才能配送。"
                                        else -> "当前订单状态暂不能开始配送，请刷新后重试。"
                                    },
                                    ChipTone.Warning,
                                )
                                Spacer(Modifier.height(8.dp))
                            }
                            PrimaryButton(
                                text = if (state.acting) "处理中…" else "开始配送",
                                onClick = { vm.dispatch() },
                                enabled = order.mayStartDelivery,
                                loading = state.acting,
                                modifier = Modifier.fillMaxWidth(),
                            )
                            Spacer(Modifier.height(6.dp))
                            Text(
                                "点击后顾客将不能再自行修改或取消订单。",
                                fontSize = T.mini,
                                color = C.TextMuted,
                            )
                        }

                        // 配送中 → 确认送达（COD 合并收款）
                        order.fulfillment == "DELIVERING" -> {
                            PrimaryButton(
                                text = if (state.acting) "处理中…" else "确认送达",
                                onClick = {
                                    if (order.paymentMethod == "COD" && order.receivableFen > 0) {
                                        showCodSheet = true
                                    } else {
                                        // 在线已付款单：只需二次确认，不涉及收款
                                        vm.confirmDelivery(collectedOnDelivery = false, method = null)
                                    }
                                },
                                loading = state.acting,
                                modifier = Modifier.fillMaxWidth(),
                            )
                        }

                        // 已送达且 COD 未收 → 补记收款（纠错入口，非日常主操作）
                        order.isCodUncollected -> {
                            NoticeBar("本单已送达，货款尚未收到。", ChipTone.Warning)
                            Spacer(Modifier.height(8.dp))
                            SecondaryButton(
                                text = "补记收款 ${money(order.receivableFen)}",
                                onClick = { showReceiptSheet = true },
                                modifier = Modifier.fillMaxWidth(),
                                leadingIconRes = R.drawable.merchant_payment,
                            )
                        }

                        else -> {
                            NoticeBar("本单已结束，无需操作。", ChipTone.Muted)
                        }
                    }
                }
            }
        }

        // ---------- COD 确认送达弹层 ----------
        if (showCodSheet && order != null) {
            CodCollectSheet(
                receivableFen = order.receivableFen,
                acting = state.acting,
                onDismiss = { showCodSheet = false },
                onConfirm = { collected, method ->
                    showCodSheet = false
                    vm.confirmDelivery(collected, method)
                },
            )
        }

        // ---------- 补记收款弹层 ----------
        if (showReceiptSheet && order != null) {
            CodReceiptSheet(
                receivableFen = order.receivableFen,
                acting = state.acting,
                onDismiss = { showReceiptSheet = false },
                onConfirm = { method ->
                    showReceiptSheet = false
                    vm.recordCodReceipt(method)
                },
            )
        }

        if (confirmQrReceipt && order != null) {
            AlertDialog(
                onDismissRequest = { confirmQrReceipt = false },
                title = { Text("确认扫码到账") },
                text = {
                    Text(
                        "请确认已在收款账户中看到 ${money(qrReceiptAmountFen)} 实际到账。" +
                            "登记后会记录线下收款流水，并允许订单继续配送；二维码扫描本身不会自动入账。",
                    )
                },
                confirmButton = {
                    TextButton(onClick = {
                        confirmQrReceipt = false
                        vm.confirmOfflineQrReceipt(qrReceiptAmountFen)
                    }) { Text("确认已到账", color = C.Brand700) }
                },
                dismissButton = {
                    TextButton(onClick = { confirmQrReceipt = false }) { Text("返回") }
                },
            )
        }

        if (confirmManualRefund && order != null && pendingManualRefund != null) {
            val refundAmountFen = pendingManualRefund.amountFen
            AlertDialog(
                onDismissRequest = { confirmManualRefund = false },
                title = { Text("登记线下退款") },
                text = {
                    Text(
                        "请只在已通过扫码账户、银行或现金实际退还 ${money(refundAmountFen)} 后确认。" +
                            "确认后会核销这笔待退款记录。请先完成实际退款。",
                    )
                },
                confirmButton = {
                    TextButton(onClick = {
                        confirmManualRefund = false
                        vm.recordManualRefund(pendingManualRefund.id, refundAmountFen)
                    }) { Text("已实际退还，登记", color = C.Danger) }
                },
                dismissButton = {
                    TextButton(onClick = { confirmManualRefund = false }) { Text("返回") }
                },
            )
        }

        // ---------- 结果提示 ----------
        if (state.message != null) {
            AlertDialog(
                onDismissRequest = { vm.consumeMessage() },
                title = { Text("操作成功") },
                text = { Text(state.message!!) },
                confirmButton = {
                    TextButton(onClick = { vm.consumeMessage() }) { Text("知道了", color = C.Brand700) }
                },
            )
        }
    }
}

/**
 * 确认送达 + COD 收款合并弹层（方案 §6.2 UI-A03 下半部分）。
 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun CodCollectSheet(
    receivableFen: Long,
    acting: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (Boolean, CollectionMethod?) -> Unit,
) {
    var collected by remember { mutableStateOf(true) }
    var method by remember { mutableStateOf<CollectionMethod?>(null) }

    val canConfirm = !collected || method != null

    androidx.compose.material3.ModalBottomSheet(
        onDismissRequest = onDismiss,
        containerColor = C.Surface,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .padding(horizontal = dim.pagePadding)
                .padding(bottom = 20.dp),
        ) {
            Text("确认送达", fontSize = T.section, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.height(4.dp))
            Text(
                "本单应收 ${money(receivableFen)}",
                fontSize = T.title,
                fontWeight = FontWeight.Bold,
                color = C.Price,
            )

            Spacer(Modifier.height(16.dp))

            // 选项一：已收款
            CollectOption(
                selected = collected,
                title = "已收款并送达",
                onClick = { collected = true },
            )

            if (collected) {
                Spacer(Modifier.height(8.dp))
                Row(
                    modifier = Modifier.fillMaxWidth().padding(start = 8.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                ) {
                    CollectionMethod.entries.forEach { m ->
                        val active = method == m
                        Box(
                            modifier = Modifier
                                .background(
                                    if (active) C.Brand100 else C.Surface,
                                    RoundedCornerShape(dim.radiusPill),
                                )
                                .clickable { method = m }
                                .padding(horizontal = 14.dp, vertical = 8.dp),
                        ) {
                            Text(
                                m.label,
                                fontSize = T.caption,
                                color = if (active) C.Brand700 else C.TextSecondary,
                                fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                            )
                        }
                    }
                }
                Spacer(Modifier.height(6.dp))
                Text(
                    "实收金额将与应收 ${money(receivableFen)} 一致，由服务端校验。",
                    fontSize = T.mini,
                    color = C.TextMuted,
                    modifier = Modifier.padding(start = 8.dp),
                )
            }

            Spacer(Modifier.height(10.dp))

            // 选项二：仅送达
            CollectOption(
                selected = !collected,
                title = "仅确认送达（货款未收）",
                subtitle = "订单会一直出现在「未收款」里，之后可补记。",
                onClick = { collected = false; method = null },
            )

            Spacer(Modifier.height(20.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                GhostButton(
                    text = "返回",
                    onClick = onDismiss,
                    modifier = Modifier.weight(1f),
                )
                Box(Modifier.weight(1.4f)) {
                    PrimaryButton(
                        text = if (acting) "提交中…" else "确认提交",
                        onClick = { onConfirm(collected, method) },
                        enabled = canConfirm,
                        loading = acting,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
        }
    }
}

@Composable
private fun CollectOption(
    selected: Boolean,
    title: String,
    subtitle: String? = null,
    onClick: () -> Unit,
) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .background(
                if (selected) C.Brand100 else C.Surface,
                RoundedCornerShape(dim.radiusMd),
            )
            .clickable { onClick() }
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        RadioButton(
            selected = selected,
            onClick = onClick,
            colors = RadioButtonDefaults.colors(selectedColor = C.Brand700),
        )
        Spacer(Modifier.width(4.dp))
        Column {
            Text(title, fontSize = T.body, fontWeight = FontWeight.Medium)
            if (subtitle != null) {
                Text(subtitle, fontSize = T.mini, color = C.TextSecondary, lineHeight = 16.sp)
            }
        }
    }
}

/** 送达后补记收款 */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun CodReceiptSheet(
    receivableFen: Long,
    acting: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (CollectionMethod) -> Unit,
) {
    var method by remember { mutableStateOf<CollectionMethod?>(null) }

    androidx.compose.material3.ModalBottomSheet(
        onDismissRequest = onDismiss,
        containerColor = C.Surface,
    ) {
        Column(
            modifier = Modifier
                .fillMaxWidth()
                .navigationBarsPadding()
                .padding(horizontal = dim.pagePadding)
                .padding(bottom = 20.dp),
        ) {
            Text("补记收款", fontSize = T.section, fontWeight = FontWeight.SemiBold)
            Spacer(Modifier.height(4.dp))
            Text(
                "本单应收 ${money(receivableFen)}",
                fontSize = T.title,
                fontWeight = FontWeight.Bold,
                color = C.Price,
            )
            Spacer(Modifier.height(12.dp))
            NoticeBar(
                "这是纠错入口：用于送达时漏登记收款的订单。操作会写入审计日志。",
                ChipTone.Warning,
            )

            Spacer(Modifier.height(14.dp))

            Text("到账渠道", fontSize = T.caption, color = C.TextSecondary)
            Spacer(Modifier.height(8.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                CollectionMethod.entries.forEach { m ->
                    val active = method == m
                    Box(
                        modifier = Modifier
                            .background(
                                if (active) C.Brand100 else C.Surface,
                                RoundedCornerShape(dim.radiusPill),
                            )
                            .clickable { method = m }
                            .padding(horizontal = 14.dp, vertical = 8.dp),
                    ) {
                        Text(
                            m.label,
                            fontSize = T.caption,
                            color = if (active) C.Brand700 else C.TextSecondary,
                            fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
                        )
                    }
                }
            }

            Spacer(Modifier.height(20.dp))

            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                GhostButton(text = "返回", onClick = onDismiss, modifier = Modifier.weight(1f))
                Box(Modifier.weight(1.4f)) {
                    PrimaryButton(
                        text = if (acting) "提交中…" else "确认已收到",
                        onClick = { method?.let(onConfirm) },
                        enabled = method != null,
                        loading = acting,
                        modifier = Modifier.fillMaxWidth(),
                    )
                }
            }
        }
    }
}

private fun revisionStatusText(status: String): String = when (status) {
    "PENDING_TOPUP" -> "待补款"
    "APPLYING" -> "退款处理中"
    "COMMITTED" -> "已生效"
    "FAILED" -> "处理失败"
    else -> status.ifBlank { "状态未知" }
}

private fun revisionStatusTone(status: String): ChipTone = when (status) {
    "PENDING_TOPUP", "APPLYING" -> ChipTone.Warning
    "FAILED" -> ChipTone.Danger
    "COMMITTED" -> ChipTone.Success
    else -> ChipTone.Muted
}
