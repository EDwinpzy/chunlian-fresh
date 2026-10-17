package com.chunlian.merchant.feature.settings

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
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
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
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
import androidx.compose.runtime.setValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.viewmodel.compose.viewModel
import com.chunlian.merchant.BuildConfig
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
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
import com.chunlian.merchant.ui.components.fen2yuan
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
) {
    MerchantTopBar(modifier) {
        if (showBack) {
            IconButton(onClick = onBack) {
                MerchantBackIcon()
            }
        }
        Column {
            Text(title, fontSize = 22.sp, fontWeight = FontWeight.Bold, color = C.Brand700)
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
    val availableGoods = productsState.goods.filterNot { it.id in selectedSet }
    val goodsById = productsState.goods.associateBy(Goods::id)

    Column(modifier = modifier.fillMaxWidth().background(C.Canvas)) {
        Row(
            modifier = Modifier.fillMaxWidth().padding(horizontal = dim.pagePadding, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text("设置首页轮播商品", fontSize = T.body, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
                Text("仅可选择在售商品，排列顺序就是顾客端轮播顺序", fontSize = T.mini, color = C.TextMuted)
            }
            Text("${selectedIds.size}/10", fontSize = T.caption, fontWeight = FontWeight.SemiBold, color = C.Brand700)
            if (selectedIds.isNotEmpty()) {
                TextButton(onClick = onClear, enabled = !state.saving) {
                    Text("恢复默认", color = C.TextSecondary, fontSize = T.caption)
                }
            }
        }

        LazyColumn(
            modifier = Modifier.weight(1f).fillMaxWidth().padding(horizontal = dim.pagePadding),
            contentPadding = PaddingValues(bottom = 12.dp),
            verticalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            item {
                SectionTitle("已选商品")
            }
            if (selectedIds.isEmpty()) {
                item {
                    CardBox {
                        Text("尚未设置自选商品时，顾客首页会展示默认鲜选商品。", Modifier.padding(14.dp), fontSize = T.caption, color = C.TextMuted)
                    }
                }
            } else {
                itemsIndexed(selectedIds, key = { _, id -> id }) { index, id ->
                    SelectedFeaturedProductRow(
                        goods = goodsById[id],
                        position = index,
                        count = selectedIds.size,
                        imageUrl = goodsById[id]?.image?.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl),
                        productInfoLoading = productsState.loading,
                        productInfoError = productsState.error != null,
                        onMoveUp = { onMove(id, -1) },
                        onMoveDown = { onMove(id, 1) },
                        onRemove = { onToggle(id) },
                    )
                }
            }

            item {
                SectionTitle("选择在售商品")
            }
            if (productsState.loading && productsState.goods.isEmpty()) {
                item {
                    Box(Modifier.fillMaxWidth().padding(20.dp), contentAlignment = Alignment.Center) {
                        androidx.compose.material3.CircularProgressIndicator(color = C.Brand700, strokeWidth = 2.dp)
                    }
                }
            }
            if (productsState.error != null) {
                item {
                    CardBox {
                        Column(Modifier.fillMaxWidth().padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                            NoticeBar(productsState.error, ChipTone.Danger)
                            TextButton(onClick = onRetry, enabled = !productsState.loading) {
                                Text("重新加载在售商品", color = C.Brand700)
                            }
                        }
                    }
                }
            } else if (productsState.loaded && availableGoods.isEmpty()) {
                item {
                    CardBox {
                        Text(
                            if (productsState.goods.isEmpty()) "暂无在售商品" else "所有在售商品都已加入每日鲜选",
                            Modifier.padding(14.dp),
                            fontSize = T.caption,
                            color = C.TextMuted,
                        )
                    }
                }
            }
            items(availableGoods, key = Goods::id) { goods ->
                SelectableFeaturedProductRow(
                    goods = goods,
                    imageUrl = goods.image.takeIf(String::isNotBlank)?.let(app.repository::absoluteUrl),
                    onClick = { onToggle(goods.id) },
                )
            }
        }

        Column(
            modifier = Modifier.fillMaxWidth().background(C.Canvas).padding(horizontal = dim.pagePadding, vertical = 10.dp),
        ) {
            PrimaryButton(
                text = if (state.saving) "保存中…" else "保存设置",
                onClick = onSave,
                enabled = !state.saving && !state.preparingShopLogo && !state.preparingPaymentQr && !state.preparingNotificationPopupImage,
                loading = state.saving,
                modifier = Modifier.fillMaxWidth(),
            )
        }
    }
}

@Composable
private fun SelectedFeaturedProductRow(
    goods: Goods?,
    position: Int,
    count: Int,
    imageUrl: String?,
    productInfoLoading: Boolean,
    productInfoError: Boolean,
    onMoveUp: () -> Unit,
    onMoveDown: () -> Unit,
    onRemove: () -> Unit,
) {
    CardBox {
        Row(
            modifier = Modifier.fillMaxWidth().padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FeaturedProductThumbnail(title = goods?.title ?: "鲜", imageUrl = imageUrl)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(goods?.title ?: "暂不可选的商品", fontSize = T.caption, fontWeight = FontWeight.SemiBold, color = C.TextPrimary, maxLines = 1)
                Text(
                    when {
                        goods != null -> "轮播第 ${position + 1} 位 · ¥${fen2yuan(goods.minPriceFen)}"
                        productInfoLoading -> "商品信息加载中 · 轮播第 ${position + 1} 位"
                        productInfoError -> "商品信息暂不可用 · 轮播第 ${position + 1} 位"
                        else -> "该商品已下架或不存在 · 轮播第 ${position + 1} 位"
                    },
                    fontSize = T.mini,
                    color = C.TextMuted,
                    maxLines = 2,
                )
            }
            Column(horizontalAlignment = Alignment.End) {
                TextButton(
                    onClick = onMoveUp,
                    enabled = position > 0,
                    contentPadding = PaddingValues(horizontal = 6.dp, vertical = 0.dp),
                ) { Text("上移", fontSize = T.mini, color = if (position > 0) C.Brand700 else C.TextMuted) }
                TextButton(
                    onClick = onMoveDown,
                    enabled = position < count - 1,
                    contentPadding = PaddingValues(horizontal = 6.dp, vertical = 0.dp),
                ) { Text("下移", fontSize = T.mini, color = if (position < count - 1) C.Brand700 else C.TextMuted) }
                TextButton(
                    onClick = onRemove,
                    contentPadding = PaddingValues(horizontal = 6.dp, vertical = 0.dp),
                ) { Text("移除", fontSize = T.mini, color = C.TextSecondary) }
            }
        }
    }
}

@Composable
private fun SelectableFeaturedProductRow(
    goods: Goods,
    imageUrl: String?,
    onClick: () -> Unit,
) {
    CardBox {
        Row(
            modifier = Modifier.fillMaxWidth().clickable(onClick = onClick).padding(10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            FeaturedProductThumbnail(title = goods.title, imageUrl = imageUrl)
            Spacer(Modifier.width(10.dp))
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(3.dp)) {
                Text(goods.title, fontSize = T.caption, fontWeight = FontWeight.SemiBold, color = C.TextPrimary, maxLines = 1)
                Text(
                    goods.specDesc.ifBlank { goods.simpleDesc.ifBlank { goods.inventoryUnit.ifBlank { "在售商品" } } },
                    fontSize = T.mini,
                    color = C.TextMuted,
                    maxLines = 1,
                )
                Text("¥${fen2yuan(goods.minPriceFen)}", fontSize = T.caption, fontWeight = FontWeight.SemiBold, color = C.Price)
            }
            TextButton(onClick = onClick, contentPadding = PaddingValues(horizontal = 8.dp, vertical = 2.dp)) {
                Text("选择", color = C.Brand700, fontSize = T.caption)
            }
        }
    }
}

@Composable
private fun FeaturedProductThumbnail(title: String, imageUrl: String?) {
    Box(
        modifier = Modifier.size(54.dp).clip(RoundedCornerShape(10.dp)).background(C.Brand100),
        contentAlignment = Alignment.Center,
    ) {
        if (!imageUrl.isNullOrBlank()) {
            AsyncImage(
                model = imageUrl,
                contentDescription = title,
                contentScale = ContentScale.Crop,
                modifier = Modifier.fillMaxWidth().height(54.dp),
            )
        } else {
            Text(title.take(1), fontSize = T.section, fontWeight = FontWeight.SemiBold, color = C.Brand700)
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
