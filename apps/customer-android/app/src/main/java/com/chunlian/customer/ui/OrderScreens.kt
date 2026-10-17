package com.chunlian.customer.ui

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CenterAlignedTopAppBar
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBarDefaults
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.draw.clip
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalView
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.view.WindowCompat
import com.chunlian.customer.data.Address
import com.chunlian.customer.data.CustomerUiState
import com.chunlian.customer.data.CustomerViewModel
import com.chunlian.customer.data.Order
import com.chunlian.customer.data.OrderItemRequest
import com.chunlian.customer.data.isValidMainlandMobile
import com.chunlian.customer.data.formatExpectedAt
import com.chunlian.customer.data.parseExpectedAt
import com.chunlian.customer.data.normalizeOrderItems
import com.chunlian.customer.data.revisionDeltaPresentation
import com.chunlian.customer.data.shouldShowManualPaymentQr
import com.chunlian.customer.R
import com.chunlian.customer.ui.theme.CustomerColors
import com.chunlian.customer.ui.theme.T
import com.chunlian.customer.ui.theme.dim
import kotlinx.coroutines.delay

@Composable
fun CheckoutScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit, onComplete: () -> Unit) {
    var name by rememberSaveable { mutableStateOf("") }
    var phone by rememberSaveable { mutableStateOf("") }
    var address by rememberSaveable { mutableStateOf("") }
    var remark by rememberSaveable { mutableStateOf("") }
    var mode by rememberSaveable { mutableStateOf("ASAP") }
    var paymentMethod by rememberSaveable { mutableStateOf("COD") }
    var expectedText by rememberSaveable { mutableStateOf("") }
    var observedOrderId by remember { mutableStateOf(state.order?.id) }
    LaunchedEffect(Unit) {
        vm.loadAddresses()
    }
    LaunchedEffect(mode, expectedText, state.cart, state.shop.minLeadMinutes) {
        if (mode == "ASAP") {
            vm.previewCheckout("ASAP", null)
        } else {
            val expectedAt = parseExpectedAt(expectedText, minLeadMinutes = state.shop.minLeadMinutes)
            if (expectedAt != null) {
                delay(350)
                vm.previewCheckout("SCHEDULED", expectedAt)
            } else {
                vm.clearCheckoutPreview()
            }
        }
    }
    LaunchedEffect(state.addresses) {
        val default = state.addresses.firstOrNull { it.isDefault } ?: state.addresses.firstOrNull()
        if (default != null && name.isBlank() && phone.isBlank() && address.isBlank()) {
            name = default.name; phone = default.phone; address = default.address
        }
    }
    LaunchedEffect(state.order?.id) {
        val current = state.order?.id
        if (current != null && current != observedOrderId) { observedOrderId = current; onComplete() }
    }
    ScreenFrame("确认订单", onBack) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).background(CustomerColors.Canvas)) {
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                item {
                    SectionCard("收货信息") {
                        if (state.addresses.isNotEmpty()) {
                            Text("常用地址", color = CustomerColors.TextSecondary, fontSize = T.caption)
                            state.addresses.forEach { saved ->
                                Row(Modifier.fillMaxWidth().clickable { name = saved.name; phone = saved.phone; address = saved.address }.padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                                    RadioButton(selected = address == saved.address && phone == saved.phone, onClick = { name = saved.name; phone = saved.phone; address = saved.address })
                                    Column(Modifier.weight(1f)) {
                                        Text("${saved.name}  ${saved.phone}", fontWeight = FontWeight.Medium)
                                        Text(saved.address, color = CustomerColors.TextSecondary, fontSize = T.caption)
                                    }
                                    if (saved.isDefault) Text("默认", color = CustomerColors.Brand700, fontSize = T.mini)
                                }
                            }
                        }
                        OutlinedTextField(name, { name = it }, label = { Text("收货人") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                        OutlinedTextField(phone, { phone = it }, label = { Text("手机号") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                        OutlinedTextField(address, { address = it }, label = { Text("详细地址（小区、楼栋、门牌）") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                    }
                }
                item {
                    SectionCard("配送时间") {
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            SelectionChip("尽快配送", selected = mode == "ASAP", onClick = { mode = "ASAP" })
                            SelectionChip("预约送达", selected = mode == "SCHEDULED", onClick = { mode = "SCHEDULED" })
                        }
                        if (state.shop.deliveryDescription.isNotBlank()) {
                            Text(state.shop.deliveryDescription, color = CustomerColors.TextSecondary, fontSize = T.caption, lineHeight = 18.sp, modifier = Modifier.padding(top = 8.dp))
                        }
                        if (mode == "SCHEDULED") OutlinedTextField(
                            expectedText, { expectedText = it }, label = { Text("希望送达时间") },
                            placeholder = { Text("2026-10-14 17:30") }, supportingText = { Text("格式：YYYY-MM-DD HH:mm") },
                            modifier = Modifier.fillMaxWidth(), singleLine = true,
                        )
                    }
                }
                item {
                    SectionCard("商品与支付") {
                        state.cart.forEach { item ->
                            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp), verticalAlignment = Alignment.CenterVertically) {
                                Text("${item.name}  ${item.spec} × ${item.qty}", modifier = Modifier.weight(1f), fontSize = T.body)
                                Text(money(item.unitFen * item.qty), fontWeight = FontWeight.SemiBold)
                            }
                        }
                        Row(Modifier.fillMaxWidth().padding(top = 10.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            SelectionChip("货到付款", selected = paymentMethod == "COD", onClick = { paymentMethod = "COD" })
                            SelectionChip(
                                "预付扫码",
                                selected = paymentMethod == "OFFLINE_QR",
                                enabled = state.shop.paymentQrUrl.isNotBlank(),
                                onClick = { paymentMethod = "OFFLINE_QR" },
                            )
                        }
                        if (paymentMethod == "OFFLINE_QR") {
                            Text("提交订单后，可在订单详情使用店铺二维码付款；店主核实后再刷新状态。", color = CustomerColors.TextSecondary, fontSize = T.caption, lineHeight = 18.sp)
                        } else {
                            Text("送达时可付现金或扫码，店主核实后记录收款。", color = CustomerColors.TextSecondary, fontSize = T.caption)
                            if (state.shop.paymentQrUrl.isBlank()) Text("店铺尚未上传收款二维码，目前仅支持现金。", color = CustomerColors.TextSecondary, fontSize = T.caption)
                        }
                        OutlinedTextField(remark, { remark = it }, label = { Text("给店主留言（选填）") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                    }
                }
                item {
                    val preview = state.preview
                    SectionCard("金额明细") {
                        MoneyRow("商品金额", preview?.goodsTotalFen?.let(::money) ?: "计算中")
                        MoneyRow("配送费", preview?.deliveryFeeFen?.let(::money) ?: "—")
                        MoneyRow("应付金额", preview?.totalFen?.let(::money) ?: "等待服务端报价", emphasize = true)
                        if (preview?.notice?.isNotBlank() == true) Text(preview.notice, color = CustomerColors.TextSecondary, fontSize = T.caption)
                        if (preview?.shopOpen == false) Text("店铺暂停接单，暂时不能下单", color = CustomerColors.Danger, fontSize = T.caption)
                        if (mode == "SCHEDULED" && parseExpectedAt(expectedText, minLeadMinutes = state.shop.minLeadMinutes) == null) {
                            Text("请填写至少提前 ${state.shop.minLeadMinutes} 分钟、且在 7 天内的送达时间", color = CustomerColors.Warning, fontSize = T.caption)
                        }
                    }
                }
            }
            Row(Modifier.fillMaxWidth().background(CustomerColors.Surface).padding(horizontal = 16.dp, vertical = 12.dp), verticalAlignment = Alignment.CenterVertically) {
                Text(state.preview?.totalFen?.let(::money) ?: "—", color = CustomerColors.Brand700, fontSize = T.headline, fontWeight = FontWeight.Bold, modifier = Modifier.weight(1f))
                Button(
                    onClick = { vm.submitOrder(name, phone, address, mode, if (mode == "SCHEDULED") parseExpectedAt(expectedText, minLeadMinutes = state.shop.minLeadMinutes) else null, paymentMethod, remark) },
                    enabled = !state.busy && state.preview?.shopOpen == true && state.preview != null && name.isNotBlank() && isValidMainlandMobile(phone) && address.length >= 4 && (mode == "ASAP" || parseExpectedAt(expectedText, minLeadMinutes = state.shop.minLeadMinutes) != null) && vm.identityTransportAllowed,
                    shape = RoundedCornerShape(dim.radiusMd), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700),
                ) { Text(if (paymentMethod == "OFFLINE_QR") "提交预付扫码订单" else "提交货到付款订单") }
            }
            if (!vm.secureTransport) InfoBanner(
                if (vm.loopbackTestTransport) "本机 QA 通道未加密" else "安全连接暂未就绪",
                if (vm.loopbackTestTransport) "仅使用合成测试账号和资料；正式业务必须启用 HTTPS。"
                else "服务器配置 HTTPS 后才能提交订单。",
                warning = true,
            )
        }
    }
}

@Composable
fun AddressesScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit) {
    var editingId by rememberSaveable { mutableStateOf<String?>(null) }
    var editing by rememberSaveable { mutableStateOf(false) }
    var name by rememberSaveable { mutableStateOf("") }
    var phone by rememberSaveable { mutableStateOf("") }
    var address by rememberSaveable { mutableStateOf("") }
    var isDefault by rememberSaveable { mutableStateOf(false) }
    var waitingForSave by remember { mutableStateOf(false) }
    var deleting by remember { mutableStateOf<Address?>(null) }
    LaunchedEffect(state.notice) {
        if (waitingForSave && state.notice == "地址已保存") {
            editing = false
            waitingForSave = false
        }
    }
    deleting?.let { target ->
        AlertDialog(
            onDismissRequest = { deleting = null },
            title = { Text("删除收货地址") },
            text = { Text("确定删除 ${target.name} 的这条地址吗？") },
            confirmButton = { TextButton(onClick = { deleting = null; vm.deleteAddress(target.id) }) { Text("删除", color = CustomerColors.Danger) } },
            dismissButton = { TextButton(onClick = { deleting = null }) { Text("保留") } },
        )
    }
    ScreenFrame("收货地址", onBack) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).background(CustomerColors.Canvas)) {
            if (editing) {
                Column(Modifier.fillMaxWidth().verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    Text(if (editingId == null) "新增地址" else "编辑地址", style = androidx.compose.material3.MaterialTheme.typography.titleLarge)
                    OutlinedTextField(name, { name = it }, label = { Text("收货人") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
                    OutlinedTextField(phone, { phone = it }, label = { Text("手机号") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
                    OutlinedTextField(address, { address = it }, label = { Text("详细地址") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
                    Row(verticalAlignment = Alignment.CenterVertically) { androidx.compose.material3.Checkbox(isDefault, { isDefault = it }); Text("设为默认地址") }
                    Button(onClick = { waitingForSave = true; vm.saveAddress(editingId, name, phone, address, isDefault) }, enabled = !state.busy && name.isNotBlank() && isValidMainlandMobile(phone) && address.length >= 4, modifier = Modifier.fillMaxWidth(), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700)) { Text("保存地址") }
                }
            } else {
                if (state.addresses.isEmpty()) EmptyState("还没有收货地址", "添加地址，下单时就能快速选择")
                LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(state.addresses, key = { it.id }) { item -> AddressCard(item, onDefault = { vm.setDefaultAddress(item.id) }, onEdit = {
                        editingId = item.id; name = item.name; phone = item.phone; address = item.address; isDefault = item.isDefault; editing = true
                    }, onDelete = { deleting = item }) }
                }
                Button(onClick = { editingId = null; name = ""; phone = ""; address = ""; isDefault = state.addresses.isEmpty(); editing = true }, modifier = Modifier.fillMaxWidth().padding(16.dp), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700)) { Text("添加新地址") }
            }
        }
    }
}

@Composable
private fun AddressCard(address: Address, onDefault: () -> Unit, onEdit: () -> Unit, onDelete: () -> Unit) {
    CardBox {
        Column(Modifier.fillMaxWidth().padding(dim.cardPadding)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(address.name, fontWeight = FontWeight.Bold)
                Text(address.phone, modifier = Modifier.padding(start = 10.dp), color = CustomerColors.TextSecondary)
                Spacer(Modifier.weight(1f))
                if (address.isDefault) StatusChip("默认地址", ChipTone.Info)
            }
            Text(address.address, color = CustomerColors.TextSecondary, modifier = Modifier.padding(top = 8.dp))
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End) {
                if (!address.isDefault) TextButton(onClick = onDefault) { Text("设为默认") }
                TextButton(onClick = onEdit) { Text("编辑") }
                TextButton(onClick = onDelete) { Text("删除", color = CustomerColors.Danger) }
            }
        }
    }
}

@Composable
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
fun OrderDetailScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit, onEditInfo: () -> Unit, onEditItems: () -> Unit) {
    var confirmCancel by remember { mutableStateOf(false) }
    var confirmReceive by remember { mutableStateOf(false) }
    val order = state.order
    val view = LocalView.current
    DisposableEffect(view) {
        val window = view.context.findActivity()?.window
        val controller = window?.let { WindowCompat.getInsetsController(it, view) }
        val previousLightStatusBars = controller?.isAppearanceLightStatusBars
        controller?.isAppearanceLightStatusBars = false
        onDispose {
            if (controller != null && previousLightStatusBars != null) {
                controller.isAppearanceLightStatusBars = previousLightStatusBars
            }
        }
    }
    if (confirmCancel && order != null) ConfirmDialog("取消订单", "确定取消这笔订单吗？已支付金额的退款以服务端处理结果为准。", "确认取消", { confirmCancel = false; vm.cancelOrder(order) }, { confirmCancel = false })
    if (confirmReceive && order != null) ConfirmDialog("确认收货", "请确认商品已经送达。确认后，剩余应付金额会自动记为已付款，适用于所有付款方式。", "确认收货", { confirmReceive = false; vm.receiveOrder(order) }, { confirmReceive = false })
    Scaffold(
        containerColor = CustomerColors.Brand700,
        topBar = {
            Column(Modifier.fillMaxWidth().background(CustomerColors.Brand700)) {
                CenterAlignedTopAppBar(
                    title = { Text("订单详情", fontSize = T.section, fontWeight = FontWeight.SemiBold) },
                    navigationIcon = {
                        IconButton(onClick = onBack) {
                            Icon(Icons.AutoMirrored.Filled.ArrowBack, contentDescription = "返回")
                        }
                    },
                    colors = TopAppBarDefaults.topAppBarColors(
                        containerColor = CustomerColors.Brand700,
                        titleContentColor = CustomerColors.OnBrand,
                        navigationIconContentColor = CustomerColors.OnBrand,
                    ),
                )
                order?.let {
                    OrderStatusHeader(
                        order = it,
                        busy = state.busy,
                        onRefresh = { vm.refreshOrder(it.id) },
                    )
                }
            }
        },
    ) { padding ->
        if (order == null) {
            Box(
                Modifier.fillMaxSize().padding(padding).background(CustomerColors.Canvas),
                contentAlignment = Alignment.Center,
            ) { CircularProgressIndicator(color = CustomerColors.Brand700) }
        } else Column(Modifier.fillMaxSize().padding(padding).background(CustomerColors.Canvas)) {
            LazyColumn(
                Modifier.weight(1f),
                contentPadding = PaddingValues(horizontal = 16.dp, vertical = 12.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                item {
                    val refundInProgress = order.paymentState == "REFUNDING" || order.refundingFen > 0
                    val cancelled = order.orderStatus == "CANCELLED"
                    if (shouldShowManualPaymentQr(
                            paymentMethod = order.paymentMethod,
                            paymentState = order.paymentState,
                            revisionState = order.revisionState,
                            refundInProgress = refundInProgress,
                            cancelled = cancelled,
                            receivableFen = order.receivableFen,
                            pendingTopupFen = order.pendingTopupFen,
                        )) {
                        SectionCard(if (order.paymentMethod == "OFFLINE_QR") "预付扫码" else "货到收款") {
                            when {
                                order.revisionState == "PENDING_TOPUP" -> {
                                    val amount = order.pendingTopupFen.takeIf { it > 0 }
                                    Text(
                                        amount?.let { "改单待补款 ${money(it)}。扫码后请联系店主核实，新增商品核实后生效。" }
                                            ?: "改单待补款。请按订单修订差额扫码，并联系店主核实后生效。",
                                        color = CustomerColors.Warning,
                                        fontSize = T.body,
                                    )
                                }
                                order.paymentMethod == "OFFLINE_QR" -> Text("扫码预付后联系店主核实；点击上方“刷新订单状态”查看核实结果。扫码本身不会自动确认付款。", color = CustomerColors.TextSecondary, fontSize = T.body)
                                else -> Text("送达时可付现金或扫码。店主核实收款后会更新付款状态。", color = CustomerColors.TextSecondary, fontSize = T.body)
                            }
                            if (state.shop.paymentQrUrl.isNotBlank()) {
                                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                                    CustomerImage(
                                        url = state.shop.paymentQrUrl,
                                        contentDescription = "店铺收款二维码",
                                        modifier = Modifier.size(190.dp).clip(RoundedCornerShape(dim.radiusSm)),
                                        contentScale = ContentScale.Fit,
                                        placeholderText = "二维码加载失败",
                                        cacheEnabled = false,
                                    )
                                }
                            } else {
                                Box(
                                    Modifier.fillMaxWidth().height(140.dp).clip(RoundedCornerShape(dim.radiusSm)).background(CustomerColors.Brand100),
                                    contentAlignment = Alignment.Center,
                                ) { Text("店铺收款二维码暂不可用，请联系店主", color = CustomerColors.TextSecondary) }
                            }
                        }
                    } else if (refundInProgress) {
                        SectionCard("退款进度", iconResource = R.drawable.cust_refund) {
                            Text("退款由店主人工处理；当前仍在处理中，订单不会自动显示为退款成功。", color = CustomerColors.Warning, fontSize = T.body)
                            if (order.refundingFen > 0) MoneyRow("退款处理中", money(order.refundingFen))
                        }
                    }
                }
                item { SectionCard("收货信息") {
                    Text("${order.contact.name}  ${order.contact.phone}", fontWeight = FontWeight.SemiBold)
                    Text(order.contact.address, color = CustomerColors.TextSecondary, modifier = Modifier.padding(top = 5.dp))
                    Text(if (order.deliveryMode == "ASAP") "尽快配送" else "预约：${formatExpectedAt(order.expectedAt)}", color = CustomerColors.TextSecondary, fontSize = T.caption, modifier = Modifier.padding(top = 8.dp))
                    if (order.remark.isNotBlank()) Text("留言：${order.remark}", color = CustomerColors.TextSecondary, modifier = Modifier.padding(top = 5.dp))
                } }
                item { SectionCard("商品清单", iconResource = R.drawable.cust_product_detail) {
                    order.lines.forEach { line ->
                        Row(Modifier.fillMaxWidth().padding(vertical = 5.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) { Text(line.name, fontWeight = FontWeight.Medium); Text("${line.spec} × ${line.qty}", color = CustomerColors.TextSecondary, fontSize = T.caption) }
                            Text(money(line.subtotalFen), fontWeight = FontWeight.SemiBold)
                        }
                    }
                    MoneyRow("商品金额", money(order.goodsTotalFen)); MoneyRow("配送费", money(order.deliveryFeeFen)); MoneyRow("订单总额", money(order.totalFen), emphasize = true)
                    if (order.receivableFen > 0) MoneyRow("尚待收款", money(order.receivableFen), emphasize = true)
                } }
                item { SectionCard("订单信息") {
                    MoneyRow("付款状态", order.paymentStateText)
                    if (order.revisionState != "NONE") MoneyRow("商品修改", revisionLabel(order.revisionState))
                    if (order.refundingFen > 0) MoneyRow("退款处理中", money(order.refundingFen))
                    if (order.refundedFen > 0) MoneyRow("已退款", money(order.refundedFen))
                } }
            }
            if (order.canEdit || order.canCancel || order.canReceive) Row(Modifier.fillMaxWidth().background(CustomerColors.Surface).padding(12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                if (order.canEdit) {
                    OutlinedButton(onClick = onEditInfo, modifier = Modifier.weight(1f)) { Text("修改地址/留言") }
                    OutlinedButton(onClick = onEditItems, modifier = Modifier.weight(1f)) { Text("修改商品") }
                }
                if (order.canCancel) OutlinedButton(onClick = { confirmCancel = true }, modifier = Modifier.weight(1f)) { Text("取消订单") }
                if (order.canReceive) Button(onClick = { confirmReceive = true }, modifier = Modifier.weight(1f), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700)) { Text("确认收货") }
            }
        }
    }
}

@Composable
private fun OrderStatusHeader(order: Order, busy: Boolean, onRefresh: () -> Unit) {
    val progress = when (order.orderStatus) {
        "PENDING" -> 0
        "DELIVERING" -> 1
        "DELIVERED", "FINISHED" -> 2
        else -> -1
    }

    Column(
        Modifier.fillMaxWidth().padding(start = 20.dp, end = 20.dp, top = 2.dp, bottom = 18.dp),
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text(
            order.orderStatusText,
            color = CustomerColors.OnBrand,
            fontSize = T.headline,
            fontWeight = FontWeight.Bold,
        )
        Text(
            orderStatusHint(order),
            color = CustomerColors.OnBrandMuted,
            fontSize = T.caption,
            lineHeight = 18.sp,
        )
        if (progress >= 0) {
            Column(Modifier.fillMaxWidth().padding(top = 5.dp)) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    OrderProgressDot(active = progress >= 0)
                    Spacer(
                        Modifier.weight(1f).height(1.dp)
                            .background(if (progress >= 1) CustomerColors.OnBrand else CustomerColors.OnBrandTranslucent),
                    )
                    OrderProgressDot(active = progress >= 1)
                    Spacer(
                        Modifier.weight(1f).height(1.dp)
                            .background(if (progress >= 2) CustomerColors.OnBrand else CustomerColors.OnBrandTranslucent),
                    )
                    OrderProgressDot(active = progress >= 2)
                }
                Row(
                    Modifier.fillMaxWidth().padding(top = 6.dp),
                    horizontalArrangement = Arrangement.SpaceBetween,
                ) {
                    listOf("已下单", "配送中", "已送达").forEachIndexed { index, label ->
                        Text(
                            label,
                            color = if (progress >= index) CustomerColors.OnBrand else CustomerColors.OnBrandMuted,
                            fontSize = T.mini,
                        )
                    }
                }
            }
        }
        Box(
            Modifier.padding(top = 2.dp)
                .clip(RoundedCornerShape(50))
                .background(CustomerColors.OnBrandTranslucent)
                .padding(horizontal = 10.dp, vertical = 6.dp),
        ) {
            Text(
                "${order.paymentMethodText} · ${order.paymentStateText}",
                color = CustomerColors.OnBrand,
                fontSize = T.mini,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
        }
        Row(
            Modifier.fillMaxWidth(),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(
                "订单号 ${order.orderNo}",
                modifier = Modifier.weight(1f),
                color = CustomerColors.OnBrandMuted,
                fontSize = T.mini,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
            )
            TextButton(
                onClick = onRefresh,
                enabled = !busy,
                contentPadding = PaddingValues(horizontal = 8.dp, vertical = 0.dp),
                colors = ButtonDefaults.textButtonColors(
                    contentColor = CustomerColors.OnBrand,
                    disabledContentColor = CustomerColors.OnBrandMuted,
                ),
            ) {
                Text(if (busy) "刷新中…" else "刷新状态", fontSize = T.mini)
            }
        }
    }
}

@Composable
private fun OrderProgressDot(active: Boolean) {
    Box(
        Modifier.size(9.dp)
            .clip(CircleShape)
            .background(if (active) CustomerColors.OnBrand else CustomerColors.OnBrandTranslucent),
    )
}

private fun orderStatusHint(order: Order): String = when {
    order.orderStatus == "CANCELLED" && order.paymentState == "REFUNDING" ->
        "订单已取消，款项退款处理中，请留意退款进度。"
    order.orderStatus == "CANCELLED" && order.paymentState == "UNPAID" ->
        "订单已取消，无需付款。"
    order.orderStatus == "CANCELLED" ->
        "订单已取消，如需了解退款进度请联系店主。"
    order.orderStatus == "DELIVERED" ->
        "店主已标记送达，收到商品后请确认收货。"
    order.orderStatus == "FINISHED" ->
        "已确认收货，订单已完成。"
    order.orderStatus == "DELIVERING" ->
        "店主正在为你配送。"
    else -> "订单已提交，店主会尽快为你安排。"
}

private tailrec fun Context.findActivity(): Activity? = when (this) {
    is Activity -> this
    is ContextWrapper -> baseContext.findActivity()
    else -> null
}

@Composable
fun OrderInfoScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit) {
    val order = state.order
    var name by rememberSaveable(order?.id) { mutableStateOf(order?.contact?.name.orEmpty()) }
    var phone by rememberSaveable(order?.id) { mutableStateOf(order?.contact?.phone.orEmpty()) }
    var address by rememberSaveable(order?.id) { mutableStateOf(order?.contact?.address.orEmpty()) }
    var remark by rememberSaveable(order?.id) { mutableStateOf(order?.remark.orEmpty()) }
    var mode by rememberSaveable(order?.id) { mutableStateOf(order?.deliveryMode ?: "ASAP") }
    var expectedText by rememberSaveable(order?.id) { mutableStateOf(formatExpectedAt(order?.expectedAt)) }
    ScreenFrame("修改订单信息", onBack) { padding ->
        Column(Modifier.fillMaxSize().padding(padding).verticalScroll(rememberScrollState()).padding(18.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
            if (order == null) return@ScreenFrame
            InfoBanner("配送开始前可修改", "店主开始配送后，订单信息将锁定。")
            OutlinedTextField(name, { name = it }, label = { Text("收货人") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
            OutlinedTextField(phone, { phone = it }, label = { Text("手机号") }, modifier = Modifier.fillMaxWidth(), singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Phone))
            OutlinedTextField(address, { address = it }, label = { Text("详细地址") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                SelectionChip("尽快配送", selected = mode == "ASAP", onClick = { mode = "ASAP" })
                SelectionChip("预约送达", selected = mode == "SCHEDULED", onClick = { mode = "SCHEDULED" })
            }
            if (mode == "SCHEDULED") OutlinedTextField(expectedText, { expectedText = it }, label = { Text("希望送达时间") }, placeholder = { Text("2026-10-14 17:30") }, modifier = Modifier.fillMaxWidth(), singleLine = true)
            OutlinedTextField(remark, { remark = it }, label = { Text("留言") }, modifier = Modifier.fillMaxWidth(), minLines = 2)
            Button(
                onClick = {
                    vm.updateOrderInfo(
                        order,
                        name,
                        phone,
                        address,
                        mode,
                        if (mode == "SCHEDULED") parseExpectedAt(expectedText, minLeadMinutes = state.shop.minLeadMinutes) else null,
                        remark,
                    )
                },
                enabled = !state.busy && name.isNotBlank() && isValidMainlandMobile(phone) && address.length >= 4 && (mode != "SCHEDULED" || parseExpectedAt(expectedText, minLeadMinutes = state.shop.minLeadMinutes) != null) && vm.identityTransportAllowed,
                modifier = Modifier.fillMaxWidth(), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700),
            ) { Text("保存修改") }
        }
    }
}

@Composable
fun OrderItemsScreen(state: CustomerUiState, vm: CustomerViewModel, onBack: () -> Unit) {
    val order = state.order
    val groupedLines = remember(order?.id, order?.lines) {
        order?.lines.orEmpty().groupBy { it.skuId }.values.map { lines ->
            lines.first().copy(qty = lines.sumOf { it.qty }, subtotalFen = lines.sumOf { it.subtotalFen })
        }
    }
    var quantities by remember(order?.id) {
        mutableStateOf(groupedLines.associate { it.skuId to it.qty })
    }
    var submittedVersion by remember(order?.id) { mutableStateOf(order?.version) }
    var showProductPicker by rememberSaveable { mutableStateOf(false) }
    var productKeyword by rememberSaveable { mutableStateOf("") }
    val currentItems = normalizeOrderItems(quantities.map { (sku, qty) -> OrderItemRequest(sku, qty) })
    LaunchedEffect(productKeyword) {
        delay(250)
        vm.loadRevisionCatalog(productKeyword)
    }
    LaunchedEffect(order?.version) {
        if (order != null && submittedVersion != null && order.version != submittedVersion) onBack()
    }
    ScreenFrame("修改商品", onBack) { padding ->
        if (order == null) Box(Modifier.fillMaxSize().padding(padding), contentAlignment = Alignment.Center) { CircularProgressIndicator() }
        else Column(Modifier.fillMaxSize().padding(padding).background(CustomerColors.Canvas)) {
            LazyColumn(Modifier.weight(1f), contentPadding = PaddingValues(16.dp), verticalArrangement = Arrangement.spacedBy(9.dp)) {
                item { InfoBanner("商品修改说明", "商品和数量由你调整，价格、库存与差额以服务端预览为准。配送开始后不可修改。") }
                items(groupedLines, key = { it.skuId }) { line ->
                    CardBox {
                        Row(Modifier.fillMaxWidth().padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text(line.name, fontWeight = FontWeight.SemiBold)
                                Text("${line.spec}  原单小计 ${money(line.subtotalFen)}", color = CustomerColors.TextSecondary, fontSize = T.caption)
                            }
                            QuantityControl(quantities[line.skuId] ?: 0, onMinus = { quantities = quantities.toMutableMap().apply { this[line.skuId] = ((this[line.skuId] ?: 0) - 1).coerceAtLeast(0) } }, onPlus = { quantities = quantities.toMutableMap().apply { this[line.skuId] = (this[line.skuId] ?: 0) + 1 } })
                        }
                    }
                }
                item {
                    OutlinedButton(onClick = { showProductPicker = true }, modifier = Modifier.fillMaxWidth()) { Text("添加其他商品") }
                    Text("可添加当前在售商品及规格，提交前会重新核对库存和价格。", color = CustomerColors.TextSecondary, fontSize = T.caption)
                }
                item {
                    val preview = state.revisionPreview
                    SectionCard("修改后金额预览") {
                        if (preview == null) Text("调整商品数量后，点击下方按钮重新计算", color = CustomerColors.TextSecondary, fontSize = T.body)
                        else {
                            if (state.revisionPreviewItems != currentItems) Text("商品内容已变化，请重新计算金额。", color = CustomerColors.Warning, fontSize = T.caption)
                            MoneyRow("原订单金额", money(preview.oldTotalFen))
                            MoneyRow("修改后金额", money(preview.newTotalFen), emphasize = true)
                            val deltaPresentation = revisionDeltaPresentation(order.paymentMethod, order.paidFen, preview.deltaFen)
                            MoneyRow(deltaPresentation.label, money(kotlin.math.abs(preview.deltaFen)), emphasize = true)
                            val customerMessage = deltaPresentation.message ?: preview.customerMessage
                            if (customerMessage.isNotBlank()) Text(customerMessage, color = CustomerColors.TextSecondary, fontSize = T.caption)
                            preview.warnings.forEach { Text(it, color = CustomerColors.Warning, fontSize = T.caption) }
                        }
                    }
                }
            }
            Column(Modifier.fillMaxWidth().background(CustomerColors.Surface).padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(
                    onClick = { vm.previewRevision(order, currentItems) },
                    enabled = !state.busy && currentItems.isNotEmpty(), modifier = Modifier.fillMaxWidth(),
                ) { Text("查看金额变化") }
                Button(
                    onClick = { submittedVersion = order.version; vm.submitRevision(order, currentItems) },
                    enabled = !state.busy && currentItems.isNotEmpty() && state.revisionPreview != null && state.revisionPreviewItems == currentItems && (state.revisionPreview.deltaFen <= 0 || order.paidFen == 0L || order.paymentMethod == "OFFLINE_QR") && vm.identityTransportAllowed,
                    modifier = Modifier.fillMaxWidth(), colors = ButtonDefaults.buttonColors(containerColor = CustomerColors.Brand700),
                ) { Text("确认商品修改") }
            }
        }
    }
    if (showProductPicker) {
        AlertDialog(
            onDismissRequest = { showProductPicker = false },
            title = { Text("选择要添加的商品") },
            text = {
                Column {
                    CustomerSearchField(
                        value = productKeyword,
                        onValueChange = { productKeyword = it },
                        placeholder = "搜索商品",
                        onSearch = { vm.loadRevisionCatalog(productKeyword) },
                    )
                    if (state.revisionCatalog.isEmpty()) {
                        Text("没有找到在售商品", color = CustomerColors.TextSecondary, modifier = Modifier.padding(top = 14.dp))
                    } else {
                        LazyColumn(Modifier.height(320.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            items(state.revisionCatalog, key = { it.id }) { goods ->
                                Row(
                                    Modifier.fillMaxWidth().clickable(enabled = !goods.isOutOfStock) { vm.openRevisionProduct(goods.id) }.padding(vertical = 8.dp),
                                    verticalAlignment = Alignment.CenterVertically,
                                ) {
                                    Column(Modifier.weight(1f)) {
                                        Text(goods.title, fontWeight = FontWeight.SemiBold)
                                        Text("${goods.inventoryUnit} · ${money(goods.minPriceFen)}起", color = CustomerColors.TextSecondary, fontSize = T.caption)
                                    }
                                    Text(if (goods.isOutOfStock) "售罄" else "选择", color = if (goods.isOutOfStock) CustomerColors.TextSecondary else CustomerColors.Brand700)
                                }
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = { showProductPicker = false }) { Text("完成") } },
        )
    }
    state.revisionProduct?.let { product ->
        AlertDialog(
            onDismissRequest = vm::closeRevisionProduct,
            title = { Text(product.title) },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text("选择规格后加入订单，库存和金额以服务端预览为准。", color = CustomerColors.TextSecondary, fontSize = T.caption)
                    LazyColumn(Modifier.height(260.dp)) {
                        items(product.skus, key = { it.skuId }) { sku ->
                            Row(
                                Modifier.fillMaxWidth().clickable(enabled = !sku.isOutOfStock) {
                                    quantities = quantities + (sku.skuId to ((quantities[sku.skuId] ?: 0) + 1))
                                    vm.closeRevisionProduct()
                                }.padding(vertical = 10.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(Modifier.weight(1f)) {
                                    Text(sku.specText.ifBlank { "默认规格" })
                                    Text(money(sku.priceFen), color = CustomerColors.Brand700, fontWeight = FontWeight.SemiBold)
                                }
                                Text(if (sku.isOutOfStock) "售罄" else "加入", color = if (sku.isOutOfStock) CustomerColors.TextSecondary else CustomerColors.Brand700)
                            }
                        }
                    }
                }
            },
            confirmButton = { TextButton(onClick = vm::closeRevisionProduct) { Text("取消") } },
        )
    }
}

@Composable
private fun SectionCard(title: String, iconResource: Int? = null, content: @Composable () -> Unit) {
    CardBox {
        Column(Modifier.padding(dim.cardPadding), verticalArrangement = Arrangement.spacedBy(9.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                iconResource?.let { CustomerAssetIcon(it, contentDescription = null, size = 24.dp) }
                Text(title, fontWeight = FontWeight.Bold)
            }
            content()
        }
    }
}

@Composable
private fun MoneyRow(label: String, value: String, emphasize: Boolean = false) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Text(label, color = if (emphasize) CustomerColors.TextPrimary else CustomerColors.TextSecondary, modifier = Modifier.weight(1f), fontWeight = if (emphasize) FontWeight.SemiBold else FontWeight.Normal)
        Text(value, color = if (emphasize) CustomerColors.Brand700 else CustomerColors.TextPrimary, fontWeight = if (emphasize) FontWeight.Bold else FontWeight.Normal)
    }
}

@Composable
private fun ConfirmDialog(title: String, message: String, confirm: String, onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(onDismissRequest = onDismiss, title = { Text(title) }, text = { Text(message) }, confirmButton = { TextButton(onClick = onConfirm) { Text(confirm, color = CustomerColors.Danger) } }, dismissButton = { TextButton(onClick = onDismiss) { Text("再想想") } })
}

private fun revisionLabel(state: String): String = when (state.uppercase()) {
    "PENDING_TOPUP" -> "等待补款"
    "APPLYING" -> "退款处理中"
    "COMMITTED" -> "已生效"
    "FAILED" -> "修改失败"
    else -> state
}
