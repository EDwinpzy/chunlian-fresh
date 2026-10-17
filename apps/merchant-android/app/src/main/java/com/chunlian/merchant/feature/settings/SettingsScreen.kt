package com.chunlian.merchant.feature.settings

import androidx.compose.runtime.Composable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.model.Goods
import com.chunlian.merchant.data.model.StorePatch
import com.chunlian.merchant.data.model.StoreSetting
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.fen2yuan
import com.chunlian.merchant.ui.components.yuan2fen
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.io.File

internal fun localEncryptionStatusLabel(isEncrypted: Boolean): String =
    if (isEncrypted) "已启用" else "不可用（降级为普通存储）"

/**
 * 我的 / 店铺设置（方案 §6.3 UI-A12 / UI-A14）。
 *
 * 【营业开关为什么在这里也要有】
 * 工作台顶部有快捷开关（日常最高频），这里是完整设置页。
 * 两处都会调同一个接口，服务端是唯一权威 ——
 * 因此任一处的修改都会在下次刷新时同步到另一处，不存在「两个开关不一致」。
 */
class SettingsViewModel(private val repo: MerchantRepository) : ViewModel() {
    data class UiState(
        val loading: Boolean = true,
        val saving: Boolean = false,
        val error: String? = null,
        val message: String? = null,
        val shopName: String = "",
        val shopAddress: String = "",
        val businessHours: String = "",
        val shopDescription: String = "",
        val deliveryDescription: String = "",
        val homeBannerTitle: String = "",
        val homeBannerSubtitle: String = "",
        val shopSlogan: String = "",
        val searchHotWordsText: String = "",
        val homeSectionTitle: String = "",
        val homeFeaturedProductIds: List<String> = emptyList(),
        val notificationPopupTitle: String = "",
        val notificationPopupImageUrl: String = "",
        val notificationPopupImagePreview: String? = null,
        val preparingNotificationPopupImage: Boolean = false,
        val clearNotificationPopupImage: Boolean = false,
        val notice: String = "",
        val phone: String = "",
        val deliveryFeeYuan: String = "0",
        val minLeadMinutes: String = "30",
        val isOpen: Boolean = true,
        val shopLogoUrl: String = "",
        val shopLogoPreview: String? = null,
        val preparingShopLogo: Boolean = false,
        val clearShopLogo: Boolean = false,
        val paymentQrUrl: String = "",
        val paymentQrPreview: String? = null,
        val preparingPaymentQr: Boolean = false,
    )

    data class FeaturedProductsState(
        val loading: Boolean = false,
        val loaded: Boolean = false,
        val error: String? = null,
        val goods: List<Goods> = emptyList(),
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private val _featuredProductsState = MutableStateFlow(FeaturedProductsState())
    val featuredProductsState: StateFlow<FeaturedProductsState> = _featuredProductsState.asStateFlow()
    private var persistedStore: StoreSetting? = null
    private var persistedState: UiState? = null
    private var loadStarted = false
    private var stagedPaymentQr: File? = null
    private var stagedShopLogo: File? = null
    private var stagedNotificationPopupImage: File? = null
    private var featuredProductsLoadStarted = false

    fun load() {
        val current = _state.value
        if (loadStarted || hasPendingChanges() || current.saving || current.preparingPaymentQr || current.preparingShopLogo) return
        loadStarted = true
        viewModelScope.launch {
            try {
                val store = repo.store()
                persistedStore = store
                stateFor(store).also {
                    persistedState = it
                    _state.value = it
                }
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = e.message ?: "加载失败")
            } finally {
                loadStarted = false
            }
        }
    }

    fun update(block: UiState.() -> UiState) {
        _state.value = _state.value.block().copy(error = null)
    }

    /** 商品目录只在打开每日鲜选配置页时读取，失败不会影响店铺设置的加载或保存。 */
    fun loadFeaturedProducts() {
        if (featuredProductsLoadStarted || _featuredProductsState.value.loaded) return
        featuredProductsLoadStarted = true
        _featuredProductsState.value = _featuredProductsState.value.copy(loading = true, error = null)
        viewModelScope.launch {
            try {
                val loaded = mutableListOf<Goods>()
                var pageNumber = 1
                var hasMore: Boolean
                do {
                    val page = repo.goods(onSaleOnly = true, page = pageNumber, pageSize = 100)
                    loaded += page.list.filter(Goods::isShelves)
                    hasMore = page.list.isNotEmpty() && page.page * page.pageSize < page.total
                    pageNumber = page.page + 1
                } while (hasMore)

                _featuredProductsState.value = FeaturedProductsState(loaded = true, goods = loaded.distinctBy(Goods::id))
            } catch (e: Exception) {
                _featuredProductsState.value = _featuredProductsState.value.copy(
                    loading = false,
                    error = e.message ?: "在售商品加载失败",
                )
            } finally {
                featuredProductsLoadStarted = false
            }
        }
    }

    fun retryLoadFeaturedProducts() {
        if (featuredProductsLoadStarted) return
        _featuredProductsState.value = _featuredProductsState.value.copy(loaded = false, error = null)
        loadFeaturedProducts()
    }

    fun toggleFeaturedProduct(productId: String) {
        val current = _state.value
        val selected = current.homeFeaturedProductIds
        if (productId !in selected && _featuredProductsState.value.goods.none { it.id == productId && it.isShelves }) return
        val updated = toggleFeaturedProductSelection(selected, productId)
        if (updated == null) {
            _state.value = current.copy(error = "每日鲜选最多设置 10 个商品")
            return
        }
        _state.value = current.copy(homeFeaturedProductIds = updated, error = null)
    }

    fun moveFeaturedProduct(productId: String, direction: Int) {
        val current = _state.value
        _state.value = current.copy(
            homeFeaturedProductIds = moveFeaturedProductSelection(current.homeFeaturedProductIds, productId, direction),
            error = null,
        )
    }

    fun paymentQrSelectionStarted(preview: String) {
        _state.value = _state.value.copy(paymentQrPreview = preview, preparingPaymentQr = true, error = null)
    }

    fun paymentQrSelected(file: File) {
        stagedPaymentQr?.takeIf { it != file }?.delete()
        stagedPaymentQr = file
        _state.value = _state.value.copy(preparingPaymentQr = false, error = null)
    }

    fun paymentQrSelectionFailed(message: String) {
        stagedPaymentQr?.delete()
        stagedPaymentQr = null
        _state.value = _state.value.copy(paymentQrPreview = null, preparingPaymentQr = false, error = message)
    }

    fun shopLogoSelectionStarted(preview: String) {
        _state.value = _state.value.copy(
            shopLogoPreview = preview,
            preparingShopLogo = true,
            clearShopLogo = false,
            error = null,
        )
    }

    fun shopLogoSelected(file: File) {
        stagedShopLogo?.takeIf { it != file }?.delete()
        stagedShopLogo = file
        _state.value = _state.value.copy(preparingShopLogo = false, error = null)
    }

    fun shopLogoSelectionFailed(message: String) {
        stagedShopLogo?.delete()
        stagedShopLogo = null
        _state.value = _state.value.copy(shopLogoPreview = null, preparingShopLogo = false, error = message)
    }

    fun notificationPopupImageSelectionStarted(preview: String) {
        _state.value = _state.value.copy(
            notificationPopupImagePreview = preview,
            preparingNotificationPopupImage = true,
            clearNotificationPopupImage = false,
            error = null,
        )
    }

    fun notificationPopupImageSelected(file: File) {
        stagedNotificationPopupImage?.takeIf { it != file }?.delete()
        stagedNotificationPopupImage = file
        _state.value = _state.value.copy(preparingNotificationPopupImage = false, error = null)
    }

    fun notificationPopupImageSelectionFailed(message: String) {
        stagedNotificationPopupImage?.delete()
        stagedNotificationPopupImage = null
        _state.value = _state.value.copy(
            notificationPopupImagePreview = null,
            preparingNotificationPopupImage = false,
            error = message,
        )
    }

    fun removeNotificationPopupImage() {
        stagedNotificationPopupImage?.delete()
        stagedNotificationPopupImage = null
        _state.value = _state.value.copy(
            notificationPopupImageUrl = "",
            notificationPopupImagePreview = null,
            preparingNotificationPopupImage = false,
            clearNotificationPopupImage = true,
            error = null,
        )
    }

    fun removeShopLogo() {
        stagedShopLogo?.delete()
        stagedShopLogo = null
        _state.value = _state.value.copy(
            shopLogoUrl = "",
            shopLogoPreview = null,
            preparingShopLogo = false,
            clearShopLogo = true,
            error = null,
        )
    }

    fun save() {
        val s = _state.value
        if (s.saving) return
        if (s.preparingPaymentQr || s.preparingShopLogo || s.preparingNotificationPopupImage) {
            _state.value = s.copy(error = "图片仍在准备中，请稍候")
            return
        }
        if (s.shopName.isBlank()) {
            _state.value = s.copy(error = "店铺名称不能为空")

            return
        }
        val leadMinutes = s.minLeadMinutes.toIntOrNull()
        if (leadMinutes == null || leadMinutes !in 0..(7 * 24 * 60)) {
            _state.value = s.copy(error = "最早配送准备时间需填写 0–10080 分钟")
            return
        }

        _state.value = s.copy(saving = true, error = null)
        viewModelScope.launch {
            try {
                val uploadedLogo = stagedShopLogo?.let { repo.uploadImage(it) }
                if (stagedShopLogo != null && uploadedLogo.isNullOrBlank()) {
                    throw NetworkException("店铺头像上传未返回有效地址，请重试")
                }
                val uploadedQr = stagedPaymentQr?.let { repo.uploadImage(it) }
                if (stagedPaymentQr != null && uploadedQr.isNullOrBlank()) {
                    throw NetworkException("二维码上传未返回有效地址，请重试")
                }
                val uploadedNotificationPopupImage = stagedNotificationPopupImage?.let { repo.uploadImage(it) }
                if (stagedNotificationPopupImage != null && uploadedNotificationPopupImage.isNullOrBlank()) {
                    throw NetworkException("通知推送图片上传未返回有效地址，请重试")
                }
                val savedStore = repo.updateStore(
                    StorePatch(
                        shopName = s.shopName.trim(),
                        shopAddress = s.shopAddress.trim(),
                        businessHours = s.businessHours.trim(),
                        shopDescription = s.shopDescription.trim(),
                        deliveryDescription = s.deliveryDescription.trim(),
                        homeBannerTitle = s.homeBannerTitle.trim(),
                        homeBannerSubtitle = s.homeBannerSubtitle.trim(),
                        shopSlogan = s.shopSlogan.trim(),
                        searchHotWords = parseSearchHotWords(s.searchHotWordsText),
                        homeSectionTitle = s.homeSectionTitle.trim(),
                        homeFeaturedProductIds = s.homeFeaturedProductIds.distinct().take(MAX_FEATURED_PRODUCTS),
                        notificationPopupTitle = s.notificationPopupTitle.trim(),
                        notificationPopupImageUrl = uploadedNotificationPopupImage
                            ?: if (s.clearNotificationPopupImage) "" else null,
                        shopLogoUrl = uploadedLogo ?: if (s.clearShopLogo) "" else null,
                        notice = s.notice.trim(),
                        phone = s.phone.trim(),
                        deliveryFeeFen = yuan2fen(s.deliveryFeeYuan),
                        minLeadMinutes = s.minLeadMinutes.trim().toIntOrNull(),
                        paymentQrUrl = uploadedQr,
                        isOpen = s.isOpen,
                    ),
                )
                stagedPaymentQr?.delete()
                stagedPaymentQr = null
                stagedShopLogo?.delete()
                stagedShopLogo = null
                stagedNotificationPopupImage?.delete()
                stagedNotificationPopupImage = null
                persistedStore = savedStore
                stateFor(savedStore).also { saved ->
                    persistedState = saved
                    _state.value = saved.copy(message = "设置已保存")
                }
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(saving = false, error = e.message)
            } catch (e: Exception) {
                _state.value = _state.value.copy(saving = false, error = e.message ?: "保存失败")
            }
        }
    }

    fun consumeMessage() {
        _state.value = _state.value.copy(message = null)
    }

    fun discardPendingChanges() {
        stagedPaymentQr?.delete()
        stagedPaymentQr = null
        stagedShopLogo?.delete()
        stagedShopLogo = null
        stagedNotificationPopupImage?.delete()
        stagedNotificationPopupImage = null
        persistedStore?.let {
            stateFor(it).also { restored ->
                persistedState = restored
                _state.value = restored
            }
        }
    }

    fun hasPendingChanges(): Boolean {
        val saved = persistedState ?: return false
        val current = _state.value.copy(loading = false, saving = false, error = null, message = null)
        return current != saved
    }

    private fun stateFor(store: StoreSetting) = UiState(
        loading = false,
        shopName = store.shopName,
        shopAddress = store.shopAddress,
        businessHours = store.businessHours,
        shopDescription = store.shopDescription,
        deliveryDescription = store.deliveryDescription,
        homeBannerTitle = store.homeBannerTitle,
        homeBannerSubtitle = store.homeBannerSubtitle,
        shopSlogan = store.shopSlogan,
        searchHotWordsText = store.searchHotWords.joinToString("，"),
        homeSectionTitle = store.homeSectionTitle,
        homeFeaturedProductIds = store.homeFeaturedProductIds.distinct().take(MAX_FEATURED_PRODUCTS),
        notificationPopupTitle = store.notificationPopupTitle,
        notificationPopupImageUrl = store.notificationPopupImageUrl,
        notice = store.notice,
        phone = store.phone,
        deliveryFeeYuan = fen2yuan(store.deliveryFeeFen),
        minLeadMinutes = store.minLeadMinutes.toString(),
        isOpen = store.isOpen,
        shopLogoUrl = store.shopLogoUrl,
        paymentQrUrl = store.paymentQrUrl,
    )
}


internal const val MAX_FEATURED_PRODUCTS = 10

internal fun toggleFeaturedProductSelection(selectedIds: List<String>, productId: String): List<String>? {
    if (productId.isBlank()) return selectedIds
    if (productId in selectedIds) return selectedIds.filterNot { it == productId }
    if (selectedIds.size >= MAX_FEATURED_PRODUCTS) return null
    return (selectedIds + productId).distinct()
}

internal fun moveFeaturedProductSelection(selectedIds: List<String>, productId: String, direction: Int): List<String> {
    if (direction !in -1..1 || direction == 0) return selectedIds
    val index = selectedIds.indexOf(productId)
    val target = index + direction
    if (index < 0 || target !in selectedIds.indices) return selectedIds
    return selectedIds.toMutableList().also { ids ->
        val moved = ids.removeAt(index)
        ids.add(target, moved)
    }
}


private fun parseSearchHotWords(value: String): List<String> = value
    .split(',', '，', '、', '\n')
    .map(String::trim)
    .filter(String::isNotEmpty)
    .distinct()

@Composable
fun SettingsScreen(onBack: (() -> Unit)? = null) {
    SettingsScreenContent(onBack)
}
