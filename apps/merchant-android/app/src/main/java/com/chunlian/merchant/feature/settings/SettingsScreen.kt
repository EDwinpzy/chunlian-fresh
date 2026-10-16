package com.chunlian.merchant.feature.settings

import androidx.compose.runtime.Composable
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.chunlian.merchant.core.network.NetworkException
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

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var persistedStore: StoreSetting? = null
    private var persistedState: UiState? = null
    private var loadStarted = false
    private var stagedPaymentQr: File? = null
    private var stagedShopLogo: File? = null

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
        if (s.preparingPaymentQr || s.preparingShopLogo) {
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
        notice = store.notice,
        phone = store.phone,
        deliveryFeeYuan = fen2yuan(store.deliveryFeeFen),
        minLeadMinutes = store.minLeadMinutes.toString(),
        isOpen = store.isOpen,
        shopLogoUrl = store.shopLogoUrl,
        paymentQrUrl = store.paymentQrUrl,
    )
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
