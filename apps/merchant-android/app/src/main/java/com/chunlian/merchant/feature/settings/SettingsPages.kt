package com.chunlian.merchant.feature.settings

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.DragHandle
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
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
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.zIndex
import androidx.lifecycle.viewmodel.compose.viewModel
import com.chunlian.merchant.BuildConfig
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.E
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.TABULAR_FIGURES
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.media.stagePickedImage
import com.chunlian.merchant.data.model.Goods
import com.chunlian.merchant.feature.products.ProductCategoryManagementScreen
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.DividerLine
import com.chunlian.merchant.ui.components.KeyValueRow
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.PrimaryButton
import com.chunlian.merchant.ui.components.SecondaryButton
import com.chunlian.merchant.ui.components.SectionTitle
import com.chunlian.merchant.ui.components.money
import com.chunlian.merchant.ui.components.tabularStyle
import coil.compose.AsyncImage
import kotlinx.coroutines.launch

private enum class SettingsPage(val title: String) {
    Home("我的"),
    Profile("店铺资料"),
    Delivery("配送设置"),
    FeaturedProducts("每日鲜选商品"),
    NotificationPush("通知推送"),
    Operating("营业状态"),
    Payment("收款二维码"),
    Categories("商品分类"),
    AppInfo("账户与应用"),
}

@Composable
internal fun SettingsScreenContent(onBack: (() -> Unit)?) {
    val app = MerchantApp.instance
    val context = LocalContext.current
    val updateState by app.appUpdater.state.collectAsState()
    val vm = viewModel { SettingsViewModel(app.repository) }
    val state by vm.state.collectAsState()
    val featuredProductsState by vm.featuredProductsState.collectAsState()
    val scope = rememberCoroutineScope()

    var page by rememberSaveable { mutableStateOf(SettingsPage.Home) }
    var leaveTarget by rememberSaveable { mutableStateOf<SettingsPage?>(null) }
    var savingToLeave by rememberSaveable { mutableStateOf<SettingsPage?>(null) }
    val isDirty = vm.hasPendingChanges()

    val paymentQrPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            vm.paymentQrSelectionStarted(uri.toString())
            scope.launch {
                try {
                    vm.paymentQrSelected(stagePickedImage(context, uri, "merchant-payment-qr"))
                } catch (e: Exception) {
                    vm.paymentQrSelectionFailed(e.message ?: "读取二维码失败，请重新选择")
                }
            }
        }
    }
    val shopLogoPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            vm.shopLogoSelectionStarted(uri.toString())
            scope.launch {
                try {
                    vm.shopLogoSelected(stagePickedImage(context, uri, "merchant-shop-logo"))
                } catch (e: Exception) {
                    vm.shopLogoSelectionFailed(e.message ?: "读取店铺头像失败，请重新选择")
                }
            }
        }
    }

    val notificationPopupImagePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            vm.notificationPopupImageSelectionStarted(uri.toString())
            scope.launch {
                try {
                    vm.notificationPopupImageSelected(stagePickedImage(context, uri, "merchant-notification-popup"))
                } catch (e: Exception) {
                    vm.notificationPopupImageSelectionFailed(e.message ?: "读取通知推送图片失败，请重新选择")
                }
            }
        }
    }

    LaunchedEffect(Unit) { vm.load() }
    LaunchedEffect(page) {
        if (page == SettingsPage.FeaturedProducts) vm.loadFeaturedProducts()
    }
    LaunchedEffect(state.loading, state.message, state.saving, state.error) {
        if (state.message != null && !vm.hasPendingChanges()) {
            page = savingToLeave ?: leaveTarget ?: SettingsPage.Home
            leaveTarget = null
            savingToLeave = null
            vm.consumeMessage()
        }
        if (!state.saving && state.error != null && savingToLeave != null) {
            savingToLeave = null
        }
    }

    fun openPage(target: SettingsPage) {
        if (target == page) return
        if (page != SettingsPage.Home && isDirty) {
            leaveTarget = target
            if (state.saving) savingToLeave = target
        } else {
            page = target
        }
    }

    fun discardAndLeave() {
        val target = leaveTarget ?: return
        vm.discardPendingChanges()
        page = target
        leaveTarget = null
    }

    BackHandler(enabled = page != SettingsPage.Home || onBack != null) {
        if (page != SettingsPage.Home) openPage(SettingsPage.Home) else onBack?.invoke()
    }

    Column(
        modifier = Modifier
            .fillMaxSize()
            .background(C.Canvas),
    ) {
        SettingsHeader(
            title = page.title,
            showBack = page != SettingsPage.Home || onBack != null,
            onBack = {
                if (page != SettingsPage.Home) openPage(SettingsPage.Home) else onBack?.invoke()
            },
            trailing = if (page == SettingsPage.FeaturedProducts) {
                {
                    val count = state.homeFeaturedProductIds.size
                    Text(
                        "$count / $MAX_FEATURED_PRODUCTS",
                        style = tabularStyle(T.caption, FontWeight.SemiBold, C.Brand600),
                    )
                }
            } else null,
            modifier = Modifier.padding(horizontal = dim.pagePadding),
        )

        if (state.error != null && page != SettingsPage.Categories) {
            NoticeBar(state.error!!, ChipTone.Danger, Modifier.padding(horizontal = dim.pagePadding))
            Spacer(Modifier.height(8.dp))
        }

        if (state.loading) {
            LoadingBox(Modifier.weight(1f))
        } else {
            when (page) {
                SettingsPage.Home -> SettingsOverview(
                    state = state,
                    modifier = Modifier.weight(1f),
                    onOpen = ::openPage,
                )

                SettingsPage.Categories -> ProductCategoryManagementScreen(Modifier.weight(1f))

                SettingsPage.FeaturedProducts -> FeaturedProductsSettingsPage(
                    modifier = Modifier.weight(1f),
                    state = state,
                    productsState = featuredProductsState,
                    onToggle = vm::toggleFeaturedProduct,
                    onMove = vm::moveFeaturedProduct,
                    onClear = { vm.update { copy(homeFeaturedProductIds = emptyList()) } },
                    onRetry = vm::retryLoadFeaturedProducts,
                    onSave = vm::save,
                )

                else -> SettingsDetail(
                    page = page,
                    state = state,
                    modifier = Modifier.weight(1f),
                    updateState = updateState,
                    onUpdate = vm::update,
                    onSave = vm::save,
                    onPickShopLogo = { shopLogoPicker.launch("image/*") },
                    onRemoveShopLogo = vm::removeShopLogo,
                    onPickPaymentQr = { paymentQrPicker.launch("image/*") },
                    onPickNotificationPopupImage = { notificationPopupImagePicker.launch("image/*") },
                    onRemoveNotificationPopupImage = vm::removeNotificationPopupImage,
                )
            }
        }
    }

    if (leaveTarget != null) {
        val isPreparingImage = state.preparingPaymentQr || state.preparingShopLogo || state.preparingNotificationPopupImage
        val isBusy = state.saving || isPreparingImage
        AlertDialog(
            onDismissRequest = { if (!isBusy) leaveTarget = null },
            title = { Text("修改还没有保存") },
            text = {
                Text(
                    when {
                        state.saving -> "正在保存设置，请稍候。"
                        isPreparingImage -> "图片仍在准备中，请稍候完成后再离开。"
                        state.error != null -> "保存失败：${state.error}。可以重试、放弃修改，或继续编辑。"
                        else -> "保存当前页面的修改后离开，或放弃修改。"
                    },
                )
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        savingToLeave = leaveTarget
                        vm.save()
                    },
                    enabled = !isBusy,
                ) {
                    Text(if (state.saving) "保存中…" else "保存并离开", color = C.Brand700)
                }
            },
            dismissButton = {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    TextButton(onClick = ::discardAndLeave, enabled = !isBusy) {
                        Text("放弃修改", color = C.TextSecondary)
                    }
                    TextButton(onClick = { leaveTarget = null }, enabled = !isBusy) {
                        Text("继续编辑", color = C.TextSecondary)
                    }
                }
            },
        )
    }

}

@Composable
private fun SettingsHeader(
    title: String,
    showBack: Boolean,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    trailing: (@Composable () -> Unit)? = null,
) {
    MerchantTopBar(modifier) {
        if (showBack) {
            IconButton(onClick = onBack) {
                MerchantBackIcon()
            }
        }
        Row(
            modifier = Modifier.weight(1f),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text(title, fontSize = 22.sp, fontWeight = FontWeight.Bold, color = C.Brand700)
            Spacer(Modifier.weight(1f))
            trailing?.invoke()
        }
    }
}

@Composable
private fun SettingsOverview(
    state: SettingsViewModel.UiState,
    modifier: Modifier,
    onOpen: (SettingsPage) -> Unit,
) {
    val app = MerchantApp.instance
    val contactSummary = listOf(state.phone, state.shopAddress)
        .filter(String::isNotBlank)
        .joinToString(" · ")

    Column(
        modifier = modifier
            .fillMaxWidth()
            .verticalScroll(rememberScrollState())
            .padding(horizontal = dim.pagePadding),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        CardBox {
            Row(
                modifier = Modifier.fillMaxWidth().padding(14.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                val image = state.shopLogoUrl.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl)
                if (image != null) {
                    AsyncImage(
                        model = image,
                        contentDescription = "店铺头像",
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.size(48.dp).clip(RoundedCornerShape(14.dp)),
                    )
                } else {
                    Box(
                        modifier = Modifier.size(48.dp).background(C.Brand100, RoundedCornerShape(14.dp)),
                        contentAlignment = Alignment.Center,
                    ) {
                        MerchantAssetIcon(R.drawable.merchant_user, "店铺头像", size = 24.dp)
                    }
                }
                Spacer(Modifier.width(14.dp))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            state.shopName.ifBlank { "设置店铺名称" },
                            fontSize = T.product,
                            fontWeight = FontWeight.Bold,
                            color = C.TextPrimary,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis,
                            modifier = Modifier.weight(1f, fill = false),
                        )
                        Spacer(Modifier.width(8.dp))
                        StoreStatus(state.isOpen)
                    }
                    Text(
                        contactSummary.ifBlank { "完善电话和门店地址" },
                        fontSize = T.mini,
                        color = C.TextSecondary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                    )
                }
            }
        }

        SettingsEntryGroup(
            title = "店铺管理",
            entries = listOf(
                SettingsEntry("店铺资料", R.drawable.merchant_user, onOpen = { onOpen(SettingsPage.Profile) }),
                SettingsEntry("每日鲜选商品", R.drawable.merchant_inventory, value = "最多 10 个", onOpen = { onOpen(SettingsPage.FeaturedProducts) }),
                SettingsEntry(
                    "配送设置",
                    R.drawable.merchant_delivery,
                    onOpen = { onOpen(SettingsPage.Delivery) },
                ),
                SettingsEntry(
                    "营业状态",
                    R.drawable.merchant_settings,
                    onOpen = { onOpen(SettingsPage.Operating) },
                ),
                SettingsEntry(
                    "收款二维码",
                    R.drawable.merchant_payment,
                    onOpen = { onOpen(SettingsPage.Payment) },
                ),
                SettingsEntry("通知推送", R.drawable.merchant_dashboard, onOpen = { onOpen(SettingsPage.NotificationPush) }),
            ),
        )

        SettingsEntryGroup(
            title = "更多设置",
            entries = listOf(
                SettingsEntry("账户与应用", R.drawable.merchant_settings, value = BuildConfig.VERSION_NAME, onOpen = { onOpen(SettingsPage.AppInfo) }),
            ),
        )
        Spacer(Modifier.height(8.dp))
    }
}

private data class SettingsEntry(
    val title: String,
    val icon: Int,
    val value: String? = null,
    val onOpen: () -> Unit,
)

@Composable
private fun SettingsEntryGroup(title: String, entries: List<SettingsEntry>) {
    Column {
        Text(title, fontSize = 13.sp, fontWeight = FontWeight.SemiBold, color = C.TextSecondary)
        Spacer(Modifier.height(8.dp))
        CardBox {
            Column {
                entries.forEachIndexed { index, entry ->
                    Row(
                        modifier = Modifier
                            .fillMaxWidth()
                            .heightIn(min = 56.dp)
                            .clickable(onClick = entry.onOpen)
                            .padding(horizontal = 14.dp, vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Box(
                            modifier = Modifier.size(32.dp).background(C.Brand100, RoundedCornerShape(12.dp)),
                            contentAlignment = Alignment.Center,
                        ) {
                            MerchantAssetIcon(entry.icon, null, size = 18.dp)
                        }
                        Spacer(Modifier.width(12.dp))
                        Text(entry.title, fontSize = T.body, fontWeight = FontWeight.SemiBold, color = C.TextPrimary, modifier = Modifier.weight(1f))
                        if (entry.value != null) {
                            Text(entry.value, fontSize = T.mini, color = C.TextSecondary)
                            Spacer(Modifier.width(6.dp))
                        }
                        Text("›", fontSize = 25.sp, color = C.TextMuted)
                    }
                    if (index != entries.lastIndex) DividerLine(Modifier.padding(start = 66.dp))
                }
            }
        }
    }
}

@Composable
private fun StoreStatus(isOpen: Boolean) {
    val statusColor = if (isOpen) C.Success else C.Warning
    Box(
        modifier = Modifier.background(statusColor.copy(alpha = 0.12f), RoundedCornerShape(dim.radiusPill))
            .padding(horizontal = 9.dp, vertical = 4.dp),
    ) {
        Text(
            if (isOpen) "营业中" else "暂停营业",
            fontSize = T.mini,
            fontWeight = FontWeight.SemiBold,
            color = statusColor,
        )
    }
}

@Composable
private fun FeaturedProductsSettingsPage(
    modifier: Modifier,
    state: SettingsViewModel.UiState,
    productsState: SettingsViewModel.FeaturedProductsState,
    onToggle: (String) -> Unit,
    onMove: (String, Int) -> Unit,
    onClear: () -> Unit,
    onRetry: () -> Unit,
    onSave: () -> Unit,
) {
    val app = MerchantApp.instance
    val selectedIds = state.homeFeaturedProductIds
    val selectedSet = selectedIds.toSet()
    val goodsById = productsState.goods.associateBy(Goods::id)
    var showClearDialog by remember { mutableStateOf(false) }
    var query by rememberSaveable { mutableStateOf("") }
    val availableGoods = productsState.goods
        .filterNot { it.id in selectedSet }
        .filter { query.isBlank() || it.title.contains(query.trim(), ignoreCase = true) }

    // 长按拖拽排序：手柄上挂手势，累计位移越过一行高度就步进一格（复用 onMove ±1）。
    // 列表经 rememberUpdatedState 提供——pointerInput 闭包不因列表变动重启，
    // 手势回调里读到的必须始终是最新队列，否则连续跨两行会走错边界。
    val density = LocalDensity.current
    val rowStepPx = with(density) { FEATURED_ROW_HEIGHT.toPx() }
    val latestIds by rememberUpdatedState(selectedIds)
    val latestMove by rememberUpdatedState(onMove)
    var draggingId by remember { mutableStateOf<String?>(null) }
    var dragOffsetY by remember { mutableStateOf(0f) }

    fun startDrag(id: String) {
        draggingId = id
        dragOffsetY = 0f
    }

    fun dragBy(deltaY: Float) {
        val id = draggingId ?: return
        var offset = dragOffsetY + deltaY
        while (offset >= rowStepPx) {
            val index = latestIds.indexOf(id)
            if (index < 0 || index >= latestIds.lastIndex) {
                offset = 0f
                break
            }
            latestMove(id, 1)
            offset -= rowStepPx
        }
        while (offset <= -rowStepPx) {
            val index = latestIds.indexOf(id)
            if (index <= 0) {
                offset = 0f
                break
            }
            latestMove(id, -1)
            offset += rowStepPx
        }
        dragOffsetY = offset
    }

    fun endDrag() {
        draggingId = null
        dragOffsetY = 0f
    }

    Column(modifier = modifier.fillMaxWidth().background(C.Canvas)) {
        LazyColumn(
            modifier = Modifier.weight(1f).fillMaxWidth().padding(horizontal = dim.pagePadding),
            contentPadding = PaddingValues(top = 2.dp, bottom = 12.dp),
        ) {
            item {
                SectionTitle(
                    "已选鲜选",
                    trailing = {
                        Text("顺序即轮播顺序", fontSize = T.mini, color = C.TextMuted)
                        Spacer(Modifier.width(4.dp))
                        TextButton(onClick = { showClearDialog = true }, enabled = !state.saving) {
                            Text("恢复默认", color = C.TextSecondary, fontSize = T.caption)
                        }
                    },
                )
            }
            if (selectedIds.isEmpty()) {
                item {
                    CardBox {
                        Text(
                            "尚未自选时，顾客首页展示默认鲜选商品。",
                            Modifier.padding(14.dp),
                            fontSize = T.caption,
                            color = C.TextMuted,
                        )
                    }
                }
            } else {
                item {
                    CardBox {
                        Column {
                            selectedIds.forEachIndexed { index, id ->
                                SelectedFeaturedProductRow(
                                    goods = goodsById[id],
                                    imageUrl = goodsById[id]?.image?.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl),
                                    productInfoLoading = productsState.loading,
                                    productInfoError = productsState.error != null,
                                    isDragging = draggingId == id,
                                    dragOffsetY = if (draggingId == id) dragOffsetY else 0f,
                                    showDividerAbove = index > 0,
                                    onDragStart = { startDrag(id) },
                                    onDrag = ::dragBy,
                                    onDragEnd = ::endDrag,
                                    onRemove = { onToggle(id) },
                                )
                            }
                        }
                    }
                }
            }

            item {
                SectionTitle("从在售商品添加")
            }
            item {
                CardBox {
                    Column {
                        CandidateSearchHeader(query = query, onQueryChange = { query = it })
                        Box(Modifier.fillMaxWidth().height(1.dp).background(C.Divider))
                        when {
                            productsState.loading && productsState.goods.isEmpty() -> {
                                Box(Modifier.fillMaxWidth().padding(vertical = 24.dp), contentAlignment = Alignment.Center) {
                                    androidx.compose.material3.CircularProgressIndicator(color = C.Brand700, strokeWidth = 2.dp)
                                }
                            }
                            productsState.error != null -> {
                                Column(Modifier.fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                                    NoticeBar(productsState.error, ChipTone.Danger)
                                    TextButton(onClick = onRetry, enabled = !productsState.loading) {
                                        Text("重新加载在售商品", color = C.Brand700, fontSize = T.caption)
                                    }
                                }
                            }
                            availableGoods.isEmpty() -> {
                                Text(
                                    when {
                                        productsState.goods.isEmpty() -> "暂无在售商品"
                                        query.isBlank() -> "所有在售商品都已加入每日鲜选"
                                        else -> "没有匹配的商品，换个关键词试试"
                                    },
                                    Modifier.padding(14.dp),
                                    fontSize = T.caption,
                                    color = C.TextMuted,
                                )
                            }
                            else -> {
                                availableGoods.take(CANDIDATE_RENDER_LIMIT).forEachIndexed { index, goods ->
                                    SelectableFeaturedProductRow(
                                        goods = goods,
                                        imageUrl = goods.image.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl),
                                        enabled = selectedIds.size < MAX_FEATURED_PRODUCTS,
                                        showDividerAbove = index > 0,
                                        onClick = { onToggle(goods.id) },
                                    )
                                }
                                if (availableGoods.size > CANDIDATE_RENDER_LIMIT) {
                                    Text(
                                        "共 ${availableGoods.size} 件在售商品，输入关键词缩小范围",
                                        Modifier.padding(horizontal = 16.dp, vertical = 12.dp),
                                        fontSize = T.mini,
                                        color = C.TextMuted,
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }

        Column(
            modifier = Modifier.fillMaxWidth().background(C.Canvas).padding(horizontal = dim.pagePadding, vertical = 10.dp),
        ) {
            Text(
                "已选 ${selectedIds.size}/$MAX_FEATURED_PRODUCTS · 保存后顾客端立即生效",
                fontSize = T.mini,
                color = C.TextMuted,
                modifier = Modifier.padding(bottom = 8.dp),
            )
            PrimaryButton(
                text = if (state.saving) "保存中…" else "保存设置",
                onClick = onSave,
                enabled = !state.saving && !state.preparingShopLogo && !state.preparingPaymentQr && !state.preparingNotificationPopupImage,
                loading = state.saving,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }

    if (showClearDialog) {
        AlertDialog(
            onDismissRequest = { showClearDialog = false },
            title = { Text("恢复默认鲜选？") },
            text = { Text("将清空当前自选商品，顾客首页恢复展示默认鲜选。需点击「保存设置」后才会生效。") },
            confirmButton = {
                TextButton(onClick = {
                    showClearDialog = false
                    onClear()
                }) {
                    Text("清空", color = C.Danger)
                }
            },
            dismissButton = {
                TextButton(onClick = { showClearDialog = false }) {
                    Text("取消", color = C.TextSecondary)
                }
            },
        )
    }
}

/** 已选行高（含分隔线余量），同时是拖拽步进阈值 */
private val FEATURED_ROW_HEIGHT = 65.dp

/** 分隔线左缩进：16(行内距) + 46(缩略图) + 11(间距)，对齐文字起点 —— 细节上的「高级感」 */
private val FEATURED_DIVIDER_INSET = 73.dp

/** 候选区一次性渲染上限：在售商品可能上百，超过就引导用搜索缩小范围 */
private const val CANDIDATE_RENDER_LIMIT = 60

@Composable
private fun CandidateSearchHeader(query: String, onQueryChange: (String) -> Unit) {
    Row(
        modifier = Modifier.fillMaxWidth().height(46.dp).padding(horizontal = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Icon(Icons.Outlined.Search, contentDescription = null, tint = C.Neutral300, modifier = Modifier.size(18.dp))
        Box(Modifier.weight(1f)) {
            if (query.isEmpty()) {
                Text("搜索在售商品", fontSize = T.body, color = C.Neutral400)
            }
            BasicTextField(
                value = query,
                onValueChange = onQueryChange,
                singleLine = true,
                textStyle = TextStyle(fontSize = T.body, color = C.TextPrimary),
                cursorBrush = SolidColor(C.Brand600),
                modifier = Modifier.fillMaxWidth(),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            )
        }
        if (query.isNotEmpty()) {
            Box(
                modifier = Modifier
                    .size(24.dp)
                    .clip(CircleShape)
                    .clickable { onQueryChange("") },
                contentAlignment = Alignment.Center,
            ) {
                Icon(Icons.Outlined.Close, contentDescription = "清空搜索", tint = C.TextMuted, modifier = Modifier.size(14.dp))
            }
        }
    }
}

@Composable
private fun SelectedFeaturedProductRow(
    goods: Goods?,
    imageUrl: String?,
    productInfoLoading: Boolean,
    productInfoError: Boolean,
    isDragging: Boolean,
    dragOffsetY: Float,
    showDividerAbove: Boolean,
    onDragStart: () -> Unit,
    onDrag: (Float) -> Unit,
    onDragEnd: () -> Unit,
    onRemove: () -> Unit,
) {
    Column(
        modifier = Modifier
            .zIndex(if (isDragging) 1f else 0f)
            .graphicsLayer {
                translationY = if (isDragging) dragOffsetY else 0f
                val scale = if (isDragging) 1.03f else 1f
                scaleX = scale
                scaleY = scale
            },
    ) {
        if (showDividerAbove) {
            Box(
                Modifier
                    .padding(start = FEATURED_DIVIDER_INSET)
                    .fillMaxWidth()
                    .height(1.dp)
                    .background(C.Divider),
            )
        }
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .heightIn(min = 64.dp)
                .background(C.Surface)
                .padding(start = 16.dp, end = 4.dp, top = 9.dp, bottom = 9.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FeaturedProductThumbnail(title = goods?.title ?: "鲜", imageUrl = imageUrl)
            Spacer(Modifier.width(11.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(
                    goods?.title ?: "商品暂不可用",
                    fontSize = T.product,
                    fontWeight = FontWeight.SemiBold,
                    color = C.TextPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    when {
                        goods != null -> money(goods.minPriceFen)
                        productInfoLoading -> "商品信息加载中"
                        productInfoError -> "商品信息暂不可用"
                        else -> "该商品已下架或不存在"
                    },
                    style = if (goods != null) {
                        tabularStyle(T.caption, FontWeight.SemiBold, C.Price)
                    } else {
                        TextStyle(fontSize = T.mini, color = C.TextMuted)
                    },
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            DragHandle(onDragStart = onDragStart, onDrag = onDrag, onDragEnd = onDragEnd)
            RemoveButton(onClick = onRemove)
        }
    }
}

/** 长按拖拽手柄：视觉弱化（faint），按住后整行浮起跟随手指。 */
@Composable
private fun DragHandle(
    onDragStart: () -> Unit,
    onDrag: (Float) -> Unit,
    onDragEnd: () -> Unit,
) {
    Box(
        modifier = Modifier
            .size(40.dp)
            .pointerInput(Unit) {
                detectDragGesturesAfterLongPress(
                    onDragStart = { onDragStart() },
                    onDrag = { change, amount ->
                        change.consume()
                        onDrag(amount.y)
                    },
                    onDragEnd = { onDragEnd() },
                    onDragCancel = { onDragEnd() },
                )
            },
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            Icons.Outlined.DragHandle,
            contentDescription = "长按拖动排序",
            tint = C.Neutral300,
            modifier = Modifier.size(20.dp),
        )
    }
}

@Composable
private fun RemoveButton(onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .size(40.dp)
            .clip(CircleShape)
            .clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(
            Icons.Outlined.Close,
            contentDescription = "移除",
            tint = C.TextMuted,
            modifier = Modifier.size(18.dp),
        )
    }
}

@Composable
private fun SelectableFeaturedProductRow(
    goods: Goods,
    imageUrl: String?,
    enabled: Boolean,
    showDividerAbove: Boolean,
    onClick: () -> Unit,
) {
    Column {
        if (showDividerAbove) {
            Box(
                Modifier
                    .padding(start = FEATURED_DIVIDER_INSET)
                    .fillMaxWidth()
                    .height(1.dp)
                    .background(C.Divider),
            )
        }
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .heightIn(min = 60.dp)
                .clickable(enabled = enabled, onClick = onClick)
                .background(C.Surface)
                .padding(start = 16.dp, end = 12.dp, top = 8.dp, bottom = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FeaturedProductThumbnail(title = goods.title, imageUrl = imageUrl)
            Spacer(Modifier.width(11.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(2.dp)) {
                Text(
                    goods.title,
                    fontSize = T.body,
                    fontWeight = FontWeight.SemiBold,
                    color = C.TextPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
                Text(
                    buildAnnotatedString {
                        append(goods.specDesc.ifBlank { goods.simpleDesc.ifBlank { goods.inventoryUnit.ifBlank { "在售商品" } } } + " · ")
                        withStyle(SpanStyle(color = C.Price, fontWeight = FontWeight.SemiBold, fontFeatureSettings = TABULAR_FIGURES)) {
                            append(money(goods.minPriceFen))
                        }
                    },
                    fontSize = T.mini,
                    color = C.TextMuted,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                )
            }
            Box(
                modifier = Modifier
                    .size(32.dp)
                    .clip(CircleShape)
                    .background(if (enabled) C.Brand100 else C.Brand50)
                    .clickable(enabled = enabled, onClick = onClick),
                contentAlignment = Alignment.Center,
            ) {
                Text("+", fontSize = 17.sp, fontWeight = FontWeight.SemiBold, color = if (enabled) C.Brand700 else C.Neutral300)
            }
        }
    }
}

@Composable
private fun FeaturedProductThumbnail(title: String, imageUrl: String?) {
    Box(
        modifier = Modifier
            .size(46.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(C.Brand50),
        contentAlignment = Alignment.Center,
    ) {
        if (!imageUrl.isNullOrBlank()) {
            AsyncImage(
                model = imageUrl,
                contentDescription = title,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxWidth().height(46.dp),
            )
        } else {
            Text(title.take(1), fontSize = T.product, fontWeight = FontWeight.SemiBold, color = C.Brand700)
        }
    }
}

@Composable
private fun SettingsDetail(
    page: SettingsPage,
    state: SettingsViewModel.UiState,
    modifier: Modifier,
    updateState: com.chunlian.androidupdate.AppUpdateState,
    onUpdate: (SettingsViewModel.UiState.() -> SettingsViewModel.UiState) -> Unit,
    onSave: () -> Unit,
    onPickShopLogo: () -> Unit,
    onRemoveShopLogo: () -> Unit,
    onPickPaymentQr: () -> Unit,
    onPickNotificationPopupImage: () -> Unit,
    onRemoveNotificationPopupImage: () -> Unit,
) {
    val app = MerchantApp.instance
    val canSave = page in setOf(
        SettingsPage.Profile,
        SettingsPage.Delivery,
        SettingsPage.NotificationPush,
        SettingsPage.Operating,
        SettingsPage.Payment,
    )

    Column(modifier = modifier.fillMaxWidth()) {
        Column(
            modifier = Modifier
                .weight(1f)
                .fillMaxWidth()
                .verticalScroll(rememberScrollState())
                .padding(horizontal = dim.pagePadding),
        ) {
        when (page) {
            SettingsPage.Profile -> {
                SectionTitle("店铺资料")
                CardBox {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Field("店铺名称", state.shopName, "顾客端显示的名称") { v -> onUpdate { copy(shopName = v) } }
                        Field("店铺地址", state.shopAddress, "顾客下单前可查看的门店地址") { v -> onUpdate { copy(shopAddress = v) } }
                        Field("店铺电话", state.phone, "顾客可一键拨号", KeyboardType.Phone) { v -> onUpdate { copy(phone = v) } }
                        Field("营业时间", state.businessHours, "例如：每天 08:00–20:00") { v -> onUpdate { copy(businessHours = v) } }
                        Field("店铺公告", state.notice, "例如：今日新增本地小白菜") { v -> onUpdate { copy(notice = v) } }
                    }
                }

                Spacer(Modifier.height(16.dp))
                SectionTitle("店铺头像")
                CardBox {
                    Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        val image = state.shopLogoPreview
                            ?: state.shopLogoUrl.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl)
                        if (image != null) {
                            AsyncImage(
                                model = image,
                                contentDescription = "店铺头像预览",
                                contentScale = ContentScale.Crop,
                                modifier = Modifier.size(112.dp).clip(RoundedCornerShape(dim.radiusLg)),
                            )
                        } else {
                            Text("尚未配置店铺头像", fontSize = T.caption, color = C.TextMuted)
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            SecondaryButton(
                                text = if (state.preparingShopLogo) "读取中…" else if (state.shopLogoUrl.isBlank()) "上传店铺头像" else "更换店铺头像",
                                onClick = onPickShopLogo,
                                enabled = !state.preparingShopLogo && !state.saving,
                                modifier = Modifier.weight(1f),
                                leadingIconRes = R.drawable.merchant_upload,
                            )
                            if (state.shopLogoUrl.isNotBlank() || state.shopLogoPreview != null) {
                                TextButton(onClick = onRemoveShopLogo, enabled = !state.saving) { Text("移除") }
                            }
                        }
                        Text("支持 JPG、PNG、GIF、WebP，图片不超过 5 MB。选择后保存生效。", fontSize = T.mini, color = C.TextMuted)
                    }
                }
            }

            SettingsPage.Delivery -> {
                SectionTitle("配送设置")
                CardBox {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Field("统一配送费（元）", state.deliveryFeeYuan, "0 表示免配送费", KeyboardType.Decimal) { v -> onUpdate { copy(deliveryFeeYuan = v) } }
                        Field("最早配送准备时间（分钟）", state.minLeadMinutes, "顾客选择尽快配送时使用", KeyboardType.Number) { v ->
                            onUpdate { copy(minLeadMinutes = v.filter(Char::isDigit).take(5)) }
                        }
                        MultiLineField("配送说明", state.deliveryDescription, "说明配送范围、时段或注意事项") { v ->
                            onUpdate { copy(deliveryDescription = v) }
                        }
                    }
                }
            }

            SettingsPage.NotificationPush -> {
                SectionTitle("商家新订单通知")
                NewOrderPushSettingsCard()
                Spacer(Modifier.height(14.dp))
                SectionTitle("顾客首页启动弹窗")
                CardBox {
                    Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        Field("标题", state.notificationPopupTitle, "例如：本周新品通知") { value ->
                            onUpdate { copy(notificationPopupTitle = value) }
                        }
                        val image = state.notificationPopupImagePreview
                            ?: state.notificationPopupImageUrl.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl)
                        if (image != null) {
                            AsyncImage(
                                model = image,
                                contentDescription = "通知推送图片预览",
                                contentScale = ContentScale.Fit,
                                modifier = Modifier.fillMaxWidth().height(220.dp).clip(RoundedCornerShape(dim.radiusSm)),
                            )
                        } else {
                            Box(
                                Modifier.fillMaxWidth().height(160.dp)
                                    .background(C.Canvas, RoundedCornerShape(dim.radiusSm)),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text("尚未选择图片", fontSize = T.caption, color = C.TextMuted)
                            }
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                            SecondaryButton(
                                text = if (state.preparingNotificationPopupImage) "读取中…" else if (image == null) "选择图片" else "更换图片",
                                onClick = onPickNotificationPopupImage,
                                enabled = !state.preparingNotificationPopupImage && !state.saving,
                                modifier = Modifier.weight(1f),
                                leadingIconRes = R.drawable.merchant_upload,
                            )
                            if (image != null) {
                                TextButton(onClick = onRemoveNotificationPopupImage, enabled = !state.saving) {
                                    Text("移除")
                                }
                            }
                        }
                        Text("顾客端每次启动时显示一次，支持 JPG、PNG、GIF、WebP，最大 5 MB。", fontSize = T.mini, color = C.TextMuted)
                    }
                }
            }

            SettingsPage.Operating -> {
                SectionTitle("营业状态")
                CardBox {
                    Row(Modifier.fillMaxWidth().padding(16.dp), verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                            Text("营业中", fontSize = T.body, fontWeight = FontWeight.SemiBold)
                            Text(
                                if (state.isOpen) "顾客可以正常下单" else "顾客暂时无法下单",
                                fontSize = T.caption,
                                color = if (state.isOpen) C.Success else C.Warning,
                            )
                        }
                        Switch(
                            checked = state.isOpen,
                            onCheckedChange = { value -> onUpdate { copy(isOpen = value) } },
                            enabled = !state.saving,
                            colors = SwitchDefaults.colors(
                                checkedThumbColor = Color.White,
                                checkedTrackColor = C.Brand700,
                            ),
                        )
                    }
                }
            }

            SettingsPage.Payment -> {
                SectionTitle("顾客收款二维码")
                CardBox {
                    Column(Modifier.fillMaxWidth().padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                        val image = state.paymentQrPreview
                            ?: state.paymentQrUrl.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl)
                        if (image != null) {
                            AsyncImage(
                                model = image,
                                contentDescription = "顾客收款二维码",
                                contentScale = ContentScale.Fit,
                                modifier = Modifier.fillMaxWidth().height(240.dp),
                            )
                        } else {
                            Box(
                                Modifier.fillMaxWidth().height(140.dp)
                                    .background(C.Canvas, RoundedCornerShape(dim.radiusSm)),
                                contentAlignment = Alignment.Center,
                            ) {
                                Text("尚未配置收款二维码", fontSize = T.body, color = C.TextMuted)
                            }
                            NoticeBar(
                                "顾客扫码付款不会自动确认到账。启用线下扫码预付后，请先核实实际到账，再在订单详情登记。",
                                ChipTone.Warning,
                            )
                        }
                        SecondaryButton(
                            text = if (state.preparingPaymentQr) "读取中…" else if (state.paymentQrUrl.isBlank()) "上传收款二维码" else "更换收款二维码",
                            onClick = onPickPaymentQr,
                            enabled = !state.preparingPaymentQr && !state.saving,
                            modifier = Modifier.fillMaxWidth(),
                            leadingIconRes = R.drawable.merchant_upload,
                        )
                        Text("支持 JPG、PNG、GIF、WebP，图片不超过 5 MB。选择后保存生效。", fontSize = T.mini, color = C.TextMuted)
                    }
                }
            }

            SettingsPage.AppInfo -> {
                SectionTitle("账户与应用")
                CardBox {
                    Column(Modifier.padding(14.dp)) {
                        KeyValueRow("App 版本", BuildConfig.VERSION_NAME)
                        KeyValueRow("服务器", BuildConfig.API_BASE_URL)
                        KeyValueRow("管理模式", "公开免登录")
                        KeyValueRow("会话到期", app.session.expiresAt.ifBlank { "—" })
                        KeyValueRow("本地加密存储", localEncryptionStatusLabel(app.session.isEncrypted))
                        Spacer(Modifier.height(12.dp))
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Column(Modifier.weight(1f)) {
                                Text("更新状态", fontSize = T.caption, color = C.TextSecondary)
                                Spacer(Modifier.height(4.dp))
                                Text(updateStatusText(updateState), fontSize = T.mini, color = C.TextPrimary)
                            }
                            Spacer(Modifier.width(10.dp))
                            SecondaryButton(
                                text = if (updateState.checking) "检查中…" else "检查更新",
                                onClick = { app.appUpdater.checkForUpdate(showResult = true) },
                                enabled = !updateState.checking && !updateState.downloading,
                            )
                        }
                    }
                }
                Spacer(Modifier.height(14.dp))
                NoticeBar("所有安装者均可进入管理端；服务端需启用免登录管理开关。", ChipTone.Warning)
                Spacer(Modifier.height(10.dp))
                NoticeBar(
                    "通知提醒依赖系统与厂商省电策略，可能延迟。请到系统设置中允许本应用后台运行与通知权限。",
                    ChipTone.Info,
                )
                Spacer(Modifier.height(12.dp))
                Text(
                    "${state.shopName.ifBlank { "店铺" }} · 商家管理端 ${BuildConfig.VERSION_NAME}",
                    fontSize = T.mini,
                    color = C.TextMuted,
                )
            }

            SettingsPage.Home, SettingsPage.Categories, SettingsPage.FeaturedProducts -> Unit
        }

        }

        if (canSave) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .background(C.Canvas)
                    .padding(horizontal = dim.pagePadding, vertical = 10.dp),
            ) {
            PrimaryButton(
                text = if (state.saving) "保存中…" else "保存设置",
                onClick = onSave,
                enabled = !state.preparingShopLogo && !state.preparingPaymentQr && !state.preparingNotificationPopupImage,
                loading = state.saving,
                modifier = Modifier.fillMaxWidth(),
            )
            }
        }
    }
}

private fun updateStatusText(state: com.chunlian.androidupdate.AppUpdateState): String = when {
    state.checking -> "正在检查更新…"
    state.downloading -> {
        val progress = state.progressPercent?.let { " $it%" }.orEmpty()
        "正在下载更新$progress"
    }
    state.release != null -> "发现新版本 ${state.release.versionName}"
    state.errorMessage != null -> "检查失败：${state.errorMessage}"
    state.statusMessage != null -> state.statusMessage
    else -> "启动后自动检查更新"
}

@Composable
private fun Field(
    label: String,
    value: String,
    placeholder: String,
    keyboardType: KeyboardType = KeyboardType.Text,
    onValueChange: (String) -> Unit,
) {
    Column {
        Text(label, fontSize = T.caption, color = C.TextSecondary)
        Spacer(Modifier.height(6.dp))
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            placeholder = { Text(placeholder, fontSize = T.caption, color = C.TextMuted) },
            singleLine = true,
            keyboardOptions = KeyboardOptions(keyboardType = keyboardType),
            shape = RoundedCornerShape(dim.radiusMd),
            modifier = Modifier.fillMaxWidth(),
            colors = TextFieldDefaults.colors(
                focusedContainerColor = C.Surface,
                unfocusedContainerColor = C.Surface,
            ),
        )
    }
}

@Composable
private fun MultiLineField(
    label: String,
    value: String,
    placeholder: String,
    onValueChange: (String) -> Unit,
) {
    Column {
        Text(label, fontSize = T.caption, color = C.TextSecondary)
        Spacer(Modifier.height(6.dp))
        OutlinedTextField(
            value = value,
            onValueChange = onValueChange,
            placeholder = { Text(placeholder, fontSize = T.caption, color = C.TextMuted) },
            minLines = 2,
            maxLines = 4,
            shape = RoundedCornerShape(dim.radiusMd),
            modifier = Modifier.fillMaxWidth(),
            colors = TextFieldDefaults.colors(
                focusedContainerColor = C.Surface,
                unfocusedContainerColor = C.Surface,
            ),
        )
    }
}
