package com.chunlian.merchant.feature.products

import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.core.content.FileProvider
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.media.stageBundledImage
import com.chunlian.merchant.core.media.stagePickedImage
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.core.network.NetworkException
import com.chunlian.merchant.data.model.Category
import com.chunlian.merchant.data.model.Goods
import com.chunlian.merchant.data.model.GoodsCreateRequest
import com.chunlian.merchant.data.model.GoodsUpdateRequest
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.GhostButton
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.PrimaryButton
import com.chunlian.merchant.ui.components.SectionTitle
import com.chunlian.merchant.ui.components.fen2yuan
import com.chunlian.merchant.ui.components.money
import com.chunlian.merchant.ui.components.yuan2fen
import coil.compose.AsyncImage
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import java.io.File

/**
 * 商品编辑 / 新增（方案 §6.3 UI-A06 / UI-A07）。
 *
 * 【为什么价格输入用「元」而存储用「分」】
 * 店主脑子里想的是「小青菜 3.5 元」，让他输入 350 分是反人性的。
 * 输入框收「元」，`yuan2fen()` 转成整数分再发给服务端；
 * 服务端内部一律整数分。转换点只有这一处，不会两边各转一次。
 *
 * 【为什么保存前要重新读一次 SKU 列表】
 * 商品可能有多个 SKU（历史数据），而本页只编辑**默认 SKU**。
 * 若直接覆盖，会把其它 SKU 的价格/库存悄悄改掉。
 * 因此这里显式只改第一个 SKU，并在界面上说明。
 */
class ProductEditViewModel(
    private val repo: MerchantRepository,
    private val goodsId: String?,
) : ViewModel() {

    private data class CreateFingerprint(
        val title: String,
        val priceYuan: String,
        val inventory: String,
        val unit: String,
        val specText: String,
        val simpleDesc: String,
        val categoryId: String,
        val isShelves: Boolean,
        val imageRevision: Long,
    )

    private data class CreateAttempt(
        val fingerprint: CreateFingerprint,
        val idempotencyKey: String = ApiClient.idempotencyKey(),
        var uploadedImageUrl: String? = null,
    )

    data class UiState(
        val loading: Boolean = true,
        val saving: Boolean = false,
        val error: String? = null,
        val message: String? = null,
        val goods: Goods? = null,
        val categories: List<Category> = emptyList(),
        // 表单
        val title: String = "",
        val priceYuan: String = "",
        val inventory: String = "",
        val unit: String = "份",
        val specText: String = "",
        val simpleDesc: String = "",
        val categoryId: String = "",
        val isShelves: Boolean = false,
        val saved: Boolean = false,
        val imagePreview: String? = null,
        val preparingImage: Boolean = false,
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var stagedImageFile: File? = null
    private var imageRevision = 0L
    private var imageSelectionGeneration = 0L
    private var createAttempt: CreateAttempt? = null

    val isCreate: Boolean get() = goodsId == null

    fun load() {
        viewModelScope.launch {
            try {
                val categories = runCatching { repo.categories() }.getOrDefault(emptyList())
                val categoryChoices = activeProductCategories(categories)

                if (goodsId == null) {
                    _state.value = _state.value.copy(
                        loading = false,
                        categories = categoryChoices,
                        unit = "份",
                        categoryId = categoryChoices.firstOrNull { it.parentId.isNullOrBlank() || it.parentId == "0" }?.id
                            ?: categoryChoices.firstOrNull()?.id.orEmpty(),
                        isShelves = false,
                    )

                    return@launch
                }

                val goods = repo.goodsDetail(goodsId)
                val sku = goods.skus.firstOrNull()

                _state.value = _state.value.copy(
                    loading = false,
                    goods = goods,
                    categories = categoryChoices,
                    title = goods.title,
                    priceYuan = sku?.let { fen2yuan(it.priceFen) } ?: fen2yuan(goods.minPriceFen),
                    inventory = (sku?.inventory ?: goods.inventory).toString(),
                    unit = goods.inventoryUnit.ifBlank { "份" },
                    specText = goods.specDesc,
                    simpleDesc = goods.simpleDesc,
                    categoryId = goods.categoryId,
                    isShelves = goods.isShelves,
                )
            } catch (e: Exception) {
                _state.value = _state.value.copy(loading = false, error = e.message ?: "加载失败")
            }
        }
    }

    fun update(block: UiState.() -> UiState) {
        val current = _state.value
        // Keep the captured create payload stable while its upload/request is in flight.
        if (current.saving) return
        val updated = current.block().copy(error = null)
        if (isCreate && createFingerprint(current) != createFingerprint(updated)) {
            createAttempt = null
        }
        _state.value = updated
    }

    fun applyPreset(preset: PresetGood): Long? {
        update {
            copy(
                title = preset.name,
                specText = preset.specText,
                unit = preset.unit,
                categoryId = matchingPresetCategoryId(preset, categories).orEmpty(),
            )
        }
        val imageAsset = preset.imageAsset
        return if (imageAsset == null) {
            clearImageSelection()
            null
        } else {
            imageSelectionStarted("file:///android_asset/$imageAsset")
        }
    }

    fun imageSelectionStarted(preview: String): Long {
        imageSelectionGeneration += 1
        _state.value = _state.value.copy(imagePreview = preview, preparingImage = true, error = null)
        return imageSelectionGeneration
    }

    fun imageSelected(file: File, selectionGeneration: Long) {
        if (selectionGeneration != imageSelectionGeneration) {
            file.delete()
            return
        }
        if (_state.value.saving) {
            file.delete()
            return
        }
        stagedImageFile?.takeIf { it != file }?.delete()
        stagedImageFile = file
        imageRevision += 1
        createAttempt = null
        _state.value = _state.value.copy(
            imagePreview = file.toURI().toString(),
            preparingImage = false,
            error = null,
        )
    }

    fun imageSelectionFailed(message: String, selectionGeneration: Long) {
        if (selectionGeneration != imageSelectionGeneration) return
        if (_state.value.saving) return
        stagedImageFile?.delete()
        stagedImageFile = null
        imageSelectionGeneration += 1
        imageRevision += 1
        createAttempt = null
        _state.value = _state.value.copy(imagePreview = null, preparingImage = false, error = message)
    }

    fun imageCaptureFailed(message: String) {
        if (_state.value.saving) return
        _state.value = _state.value.copy(error = message)
    }

    private fun clearImageSelection() {
        imageSelectionGeneration += 1
        stagedImageFile?.delete()
        stagedImageFile = null
        imageRevision += 1
        createAttempt = null
        _state.value = _state.value.copy(imagePreview = null, preparingImage = false, error = null)
    }

    fun archive() {
        if (_state.value.saving || goodsId == null) return
        _state.value = _state.value.copy(saving = true, error = null)
        viewModelScope.launch {
            try {
                repo.archiveGoods(goodsId)
                _state.value = _state.value.copy(saving = false, saved = true, message = "商品已删除")
            } catch (e: Exception) {
                _state.value = _state.value.copy(saving = false, error = e.message ?: "删除失败")
            }
        }
    }

    fun save() {
        val s = _state.value
        if (s.saving) return
        if (s.preparingImage) {
            _state.value = s.copy(error = "图片仍在准备中，请稍候")
            return
        }

        if (s.title.isBlank()) {
            _state.value = s.copy(error = "请填写商品名称")

            return
        }
        if (s.categoryId.isBlank()) {
            _state.value = s.copy(error = "请选择商品分类")

            return
        }
        if (s.categories.none { it.id == s.categoryId }) {
            _state.value = s.copy(error = "当前分类已下架，请选择一个启用的分类")
            return
        }
        val priceFen = yuan2fen(s.priceYuan)
        if (priceFen <= 0) {
            _state.value = s.copy(error = "请填写正确的售价")

            return
        }
        val inventory = s.inventory.trim().toIntOrNull()
        if (inventory == null || inventory < 0) {
            _state.value = s.copy(error = "库存请填写 0 或正整数")

            return
        }

        _state.value = s.copy(saving = true, error = null)

        viewModelScope.launch {
            try {
                if (isCreate) {
                    val fingerprint = createFingerprint(s)
                    val attempt = createAttempt?.takeIf { it.fingerprint == fingerprint }
                        ?: CreateAttempt(fingerprint).also { createAttempt = it }
                    val uploadedImage = attempt.uploadedImageUrl ?: stagedImageFile?.let { image ->
                        repo.uploadImage(image).also { attempt.uploadedImageUrl = it }
                    }
                    if (stagedImageFile != null && uploadedImage.isNullOrBlank()) {
                        throw NetworkException("图片上传未返回有效地址，请重试")
                    }
                    repo.createGoods(
                        GoodsCreateRequest(
                            title = s.title.trim(),
                            priceFen = priceFen,
                            inventory = inventory,
                            inventoryUnit = s.unit.ifBlank { "份" },
                            specText = s.specText.trim(),
                            simpleDesc = s.simpleDesc.trim(),
                            categoryId = s.categoryId.takeIf { it.isNotBlank() },
                            image = uploadedImage,
                            isShelves = s.isShelves,
                        ),
                        attempt.idempotencyKey,
                    )
                } else {
                    val uploadedImage = stagedImageFile?.let { repo.uploadImage(it) }
                    if (stagedImageFile != null && uploadedImage.isNullOrBlank()) {
                        throw NetworkException("图片上传未返回有效地址，请重试")
                    }
                    // 1) 基础信息
                    repo.updateGoods(
                        goodsId!!,
                        GoodsUpdateRequest(
                            title = s.title.trim(),
                            simpleDesc = s.simpleDesc.trim(),
                            specDesc = s.specText.trim(),
                            categoryId = s.categoryId.takeIf { it.isNotBlank() },
                            image = uploadedImage,
                            inventoryUnit = s.unit.ifBlank { "份" },
                        ),
                    )

                    // 2) 价格与库存（服务端会连带重算商品聚合字段）
                    val skuId = s.goods?.skus?.firstOrNull()?.skuId
                    if (skuId != null) {
                        repo.updateSku(goodsId, skuId, priceFen, inventory)
                    }

                    // 3) 上下架
                    if (s.goods != null && s.goods.isShelves != s.isShelves) {
                        repo.setShelves(goodsId, s.isShelves)
                    }
                }

                stagedImageFile?.delete()
                stagedImageFile = null
                createAttempt = null
                imageRevision += 1

                _state.value = _state.value.copy(
                    saving = false,
                    message = if (isCreate) "商品已创建" else "已保存",
                    saved = true,
                )
            } catch (e: NetworkException) {
                _state.value = _state.value.copy(saving = false, error = e.message)
            } catch (e: Exception) {
                _state.value = _state.value.copy(saving = false, error = e.message ?: "保存失败")
            }
        }
    }

    private fun createFingerprint(s: UiState) = CreateFingerprint(
        title = s.title.trim(),
        priceYuan = s.priceYuan,
        inventory = s.inventory.trim(),
        unit = s.unit.ifBlank { "份" },
        specText = s.specText.trim(),
        simpleDesc = s.simpleDesc.trim(),
        categoryId = s.categoryId,
        isShelves = s.isShelves,
        imageRevision = imageRevision,
    )
}

@Composable
fun ProductEditScreen(goodsId: String?, onBack: () -> Unit) {
    val context = LocalContext.current
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val presetGoods = remember(context) {
        runCatching {
            context.assets.open("preset-goods.json").bufferedReader().use { reader ->
                parsePresetGoods(reader.readText())
            }
        }.getOrDefault(emptyList())
    }
    val vm = androidx.lifecycle.viewmodel.compose.viewModel {
        ProductEditViewModel(MerchantApp.instance.repository, goodsId)
    }
    val state by vm.state.collectAsState()
    var confirmArchive by remember { mutableStateOf(false) }
    var showPresetPicker by remember { mutableStateOf(false) }
    var presetQuery by remember { mutableStateOf("") }
    var pendingCameraFilePath by rememberSaveable { mutableStateOf<String?>(null) }
    val imagePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            val selectionGeneration = vm.imageSelectionStarted(uri.toString())
            scope.launch {
                try {
                    val stagedFile = stagePickedImage(context, uri, "merchant-product")
                    vm.imageSelected(stagedFile, selectionGeneration)
                } catch (e: Exception) {
                    vm.imageSelectionFailed(e.message ?: "读取图片失败，请重新选择", selectionGeneration)
                }
            }
        }
    }
    val cameraPicker = rememberLauncherForActivityResult(ActivityResultContracts.TakePicture()) { success ->
        val cameraFile = pendingCameraFilePath?.let(::File)
        pendingCameraFilePath = null
        if (success && cameraFile != null && cameraFile.isFile && cameraFile.length() > 0L) {
            val cameraUri = runCatching {
                FileProvider.getUriForFile(
                    context,
                    "${context.packageName}.fileprovider",
                    cameraFile,
                )
            }.getOrElse { error ->
                cameraFile.delete()
                vm.imageCaptureFailed(error.message ?: "读取拍摄图片失败，请重试")
                null
            }
            if (cameraUri != null) {
                val selectionGeneration = vm.imageSelectionStarted(cameraUri.toString())
                scope.launch {
                    try {
                        val stagedFile = stagePickedImage(context, cameraUri, "merchant-product")
                        vm.imageSelected(stagedFile, selectionGeneration)
                    } catch (e: Exception) {
                        vm.imageSelectionFailed(e.message ?: "读取拍摄图片失败，请重试", selectionGeneration)
                    } finally {
                        cameraFile.delete()
                    }
                }
            }
        } else {
            cameraFile?.delete()
            if (success) vm.imageCaptureFailed("没有获取到照片，请重试")
        }
    }

    fun launchCameraCapture() {
        try {
            val cameraDirectory = File(context.cacheDir, "merchant-product-camera")
            if (!cameraDirectory.exists() && !cameraDirectory.mkdirs()) {
                throw IllegalStateException("无法创建拍照缓存目录")
            }
            val cameraFile = File.createTempFile("product-", ".jpg", cameraDirectory)
            pendingCameraFilePath = cameraFile.absolutePath
            val cameraUri = FileProvider.getUriForFile(
                context,
                "${context.packageName}.fileprovider",
                cameraFile,
            )
            cameraPicker.launch(cameraUri)
        } catch (e: Exception) {
            pendingCameraFilePath?.let { File(it).delete() }
            pendingCameraFilePath = null
            vm.imageCaptureFailed(e.message ?: "无法打开相机，请重试")
        }
    }

    LaunchedEffect(goodsId) { vm.load() }

    LaunchedEffect(state.saved) {
        if (state.saved) onBack()
    }

    Box(Modifier.fillMaxSize().background(C.Canvas).safeDrawingPadding()) {
        Column(Modifier.fillMaxSize()) {
            MerchantTopBar(Modifier.padding(horizontal = 8.dp)) {
                IconButton(onClick = onBack) {
                    MerchantBackIcon()
                }
                MerchantAssetIcon(
                    if (vm.isCreate) R.drawable.merchant_publish else R.drawable.merchant_edit,
                    contentDescription = null,
                    size = 22.dp,
                )
                Spacer(Modifier.width(8.dp))
                Text(
                    if (vm.isCreate) "新增农产品" else "编辑商品",
                    fontSize = T.section,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier.weight(1f),
                )
            }

            when {
                state.loading -> LoadingBox()

                !vm.isCreate && state.goods == null -> EmptyBox(
                    title = "商品不存在",
                    actionText = "返回",
                    onAction = onBack,
                )

                else -> Column(
                    modifier = Modifier
                        .weight(1f)
                        .verticalScroll(rememberScrollState())
                        .padding(horizontal = dim.pagePadding)
                        .padding(bottom = dim.touchTarget + 40.dp),
                ) {
                    if (state.error != null) {
                        NoticeBar(state.error!!, ChipTone.Danger)
                        Spacer(Modifier.height(12.dp))
                    }

                    if (!vm.isCreate && (state.goods?.skus?.size ?: 0) > 1) {
                        NoticeBar(
                            "该商品有 ${state.goods?.skus?.size} 个规格，本页只修改第一个规格的价格与库存。",
                            ChipTone.Warning,
                        )
                        Spacer(Modifier.height(12.dp))
                    }

                    if (vm.isCreate) {
                        CardBox {
                            Column(Modifier.fillMaxWidth().padding(8.dp)) {
                                TextButton(
                                    onClick = {
                                        presetQuery = ""
                                        showPresetPicker = true
                                    },
                                    enabled = !state.saving,
                                    modifier = Modifier.fillMaxWidth(),
                                ) {
                                    Text("搜索并选择农产品", color = C.Brand700)
                                }
                                Text(
                                    "预设名称、规格、分类和图片都可修改。新商品默认下架；确认资料、售价和库存后，再开启“立即上架”。",
                                    fontSize = T.mini,
                                    color = C.TextMuted,
                                    modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp),
                                )
                            }
                        }
                        Spacer(Modifier.height(16.dp))
                    }

                    Spacer(Modifier.height(4.dp))
                    SectionTitle("商品图片")

                    CardBox {
                        Column(
                            Modifier.fillMaxWidth().padding(14.dp),
                            verticalArrangement = Arrangement.spacedBy(10.dp),
                        ) {
                            val imageModel = state.imagePreview
                                ?: state.goods?.image?.takeIf { it.isNotBlank() }?.let {
                                    MerchantApp.instance.repository.absoluteUrl(it)
                                }
                            if (imageModel != null) {
                                AsyncImage(
                                    model = imageModel,
                                    contentDescription = "商品图片预览",
                                    contentScale = ContentScale.Fit,
                                    modifier = Modifier.fillMaxWidth().height(180.dp),
                                )
                            } else {
                                Box(
                                    Modifier.fillMaxWidth().height(120.dp).background(C.Canvas, RoundedCornerShape(dim.radiusSm)),
                                    contentAlignment = Alignment.Center,
                                ) {
                                    Text("尚未选择商品图片", fontSize = T.caption, color = C.TextMuted)
                                }
                            }
                            Row(verticalAlignment = Alignment.CenterVertically) {
                                com.chunlian.merchant.ui.components.SecondaryButton(
                                    text = if (state.preparingImage) "读取中…" else "从相册选择",
                                    onClick = { imagePicker.launch("image/*") },
                                    enabled = !state.preparingImage && !state.saving,
                                    modifier = Modifier.weight(1f).padding(end = 4.dp),
                                    leadingIconRes = R.drawable.merchant_upload,
                                )
                                com.chunlian.merchant.ui.components.SecondaryButton(
                                    text = "拍照",
                                    onClick = { launchCameraCapture() },
                                    enabled = !state.preparingImage && !state.saving,
                                    modifier = Modifier.weight(1f).padding(start = 4.dp),
                                    leadingIconRes = R.drawable.merchant_camera,
                                )
                                if (state.preparingImage) {
                                    androidx.compose.material3.CircularProgressIndicator(
                                        modifier = Modifier.padding(start = 12.dp).size(22.dp),
                                        color = C.Brand700,
                                        strokeWidth = 2.dp,
                                    )
                                }
                            }
                            Text("支持 JPG、PNG、GIF、WebP，图片不超过 5 MB。", fontSize = T.mini, color = C.TextMuted)
                        }
                    }

                    Spacer(Modifier.height(20.dp))
                    SectionTitle("基本信息")

                    CardBox {
                        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            FormField(
                                label = "商品名称",
                                value = state.title,
                                placeholder = "例如：小青菜",
                                onValueChange = { v -> vm.update { copy(title = v) } },
                            )
                            FormField(
                                label = "规格说明",
                                value = state.specText,
                                placeholder = "例如：约500g/份",
                                onValueChange = { v -> vm.update { copy(specText = v) } },
                            )
                            FormField(
                                label = "简介",
                                value = state.simpleDesc,
                                placeholder = "例如：按实际情况填写商品简介",
                                onValueChange = { v -> vm.update { copy(simpleDesc = v) } },
                            )
                        }
                    }

                    Spacer(Modifier.height(20.dp))
                    SectionTitle("价格与库存")

                    CardBox {
                        Column(Modifier.padding(14.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
                            FormField(
                                label = "售价（元）",
                                value = state.priceYuan,
                                placeholder = "例如：3.50",
                                keyboardType = KeyboardType.Decimal,
                                onValueChange = { v -> vm.update { copy(priceYuan = v) } },
                            )
                            FormField(
                                label = "库存",
                                value = state.inventory,
                                placeholder = "例如：50",
                                keyboardType = KeyboardType.Number,
                                onValueChange = { v -> vm.update { copy(inventory = v) } },
                            )
                            FormField(
                                label = "单位",
                                value = state.unit,
                                placeholder = "份 / 把 / 袋 / 盒",
                                onValueChange = { v -> vm.update { copy(unit = v) } },
                            )

                            if (state.priceYuan.isNotBlank()) {
                                Text(
                                    "顾客端将显示 ${money(yuan2fen(state.priceYuan))}",
                                    fontSize = T.mini,
                                    color = C.TextMuted,
                                )
                            }
                        }
                    }

                    Spacer(Modifier.height(20.dp))
                    SectionTitle("分类与状态")

                    CardBox {
                        Column(Modifier.padding(14.dp)) {
                            Text("所属分类", fontSize = T.caption, color = C.TextSecondary)
                            Spacer(Modifier.height(8.dp))

                            Row(
                                modifier = Modifier.fillMaxWidth().horizontalScroll(rememberScrollState()),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                state.categories.forEach { cat ->
                                    CategoryChip(
                                        label = cat.name,
                                        active = state.categoryId == cat.id,
                                        onClick = { vm.update { copy(categoryId = cat.id) } },
                                    )
                                }
                            }

                            if (state.categoryId.isNotBlank() && state.categories.none { it.id == state.categoryId }) {
                                Spacer(Modifier.height(6.dp))
                                Text(
                                    "当前分类已下架，请选择一个启用的分类后保存。",
                                    fontSize = T.mini,
                                    color = C.Warning,
                                )
                            }

                            Spacer(Modifier.height(16.dp))

                            Row(
                                modifier = Modifier.fillMaxWidth(),
                                horizontalArrangement = Arrangement.SpaceBetween,
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(Modifier.weight(1f)) {
                                    Text("立即上架", fontSize = T.body, fontWeight = FontWeight.Medium)
                                    Text(
                                        "关闭后顾客端看不到这个商品",
                                        fontSize = T.mini,
                                        color = C.TextMuted,
                                    )
                                }
                                Switch(
                                    checked = state.isShelves,
                                    onCheckedChange = { v -> vm.update { copy(isShelves = v) } },
                                    colors = SwitchDefaults.colors(
                                        checkedThumbColor = Color.White,
                                        checkedTrackColor = C.Brand700,
                                    ),
                                )
                            }
                        }
                    }

                    Spacer(Modifier.height(24.dp))

                    if (!vm.isCreate) {
                        Text(
                            "提示：有历史订单的商品只能下架，不能物理删除 —— " +
                                "否则那些订单的商品链接会全部失效。",
                            fontSize = T.mini,
                            color = C.TextMuted,
                            lineHeight = 16.sp,
                        )
                        Spacer(Modifier.height(24.dp))
                        GhostButton(
                            text = "删除商品（保留历史订单）",
                            onClick = { confirmArchive = true },
                            danger = true,
                            enabled = !state.saving,
                            modifier = Modifier.fillMaxWidth(),
                            leadingIconRes = R.drawable.merchant_delete,
                        )
                        Spacer(Modifier.height(24.dp))
                    }
                }
            }
        }

        // 底部保存
        if (!state.loading) {
            Box(
                modifier = Modifier
                    .align(Alignment.BottomCenter)
                    .fillMaxWidth()
                    .background(C.Surface)
                    .navigationBarsPadding()
                    .padding(horizontal = dim.pagePadding, vertical = 10.dp),
            ) {
                Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                    GhostButton(text = "取消", onClick = onBack, modifier = Modifier.weight(1f))
                    Box(Modifier.weight(1.6f)) {
                        PrimaryButton(
                            text = if (state.saving) "保存中…" else "保存",
                            onClick = { vm.save() },
                            loading = state.saving,
                            modifier = Modifier.fillMaxWidth(),
                        )
                    }
                }
            }
        }
    }

    if (confirmArchive) {
        AlertDialog(
            onDismissRequest = { confirmArchive = false },
            title = { Text("删除商品") },
            text = { Text("确认删除「${state.goods?.title.orEmpty()}」？商品会从顾客端和管理列表隐藏，历史订单快照会保留。") },
            confirmButton = {
                TextButton(onClick = {
                    confirmArchive = false
                    vm.archive()
                }) { Text("确认删除", color = C.Danger) }
            },
            dismissButton = {
                TextButton(onClick = { confirmArchive = false }) { Text("取消") }
            },
        )
    }

    if (showPresetPicker && vm.isCreate) {
        AlertDialog(
            onDismissRequest = { showPresetPicker = false },
            title = { Text("选择农产品") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    OutlinedTextField(
                        value = presetQuery,
                        onValueChange = { presetQuery = it },
                        placeholder = { Text("搜索农产品名称或别名") },
                        singleLine = true,
                        modifier = Modifier.fillMaxWidth(),
                    )
                    val matches = searchPresetGoods(presetGoods, presetQuery)
                    if (presetGoods.isEmpty()) {
                        Text("农产品预设暂不可用，可直接填写商品信息。", color = C.TextMuted)
                    } else if (matches.isEmpty()) {
                        Text("没有找到匹配商品", color = C.TextMuted)
                    } else {
                        Column(
                            modifier = Modifier.fillMaxWidth().heightIn(max = 360.dp).verticalScroll(rememberScrollState()),
                            verticalArrangement = Arrangement.spacedBy(2.dp),
                        ) {
                            matches.forEach { preset ->
                                TextButton(
                                    onClick = {
                                        val selectionGeneration = vm.applyPreset(preset)
                                        val imageAsset = preset.imageAsset
                                        if (selectionGeneration != null && imageAsset != null) {
                                            scope.launch {
                                                try {
                                                    val stagedFile = stageBundledImage(
                                                        context,
                                                        imageAsset,
                                                        "merchant-product",
                                                    )
                                                    vm.imageSelected(stagedFile, selectionGeneration)
                                                } catch (e: Exception) {
                                                    vm.imageSelectionFailed(
                                                        e.message ?: "预设图片加载失败，请重新选择",
                                                        selectionGeneration,
                                                    )
                                                }
                                            }
                                        }
                                        showPresetPicker = false
                                    },
                                    modifier = Modifier.fillMaxWidth(),
                                ) {
                                    Row(
                                        modifier = Modifier.fillMaxWidth(),
                                        verticalAlignment = Alignment.CenterVertically,
                                    ) {
                                        val imageAsset = preset.imageAsset
                                        if (imageAsset != null) {
                                            AsyncImage(
                                                model = "file:///android_asset/$imageAsset",
                                                contentDescription = "${preset.name}图片",
                                                contentScale = ContentScale.Crop,
                                                modifier = Modifier
                                                    .size(48.dp)
                                                    .clip(RoundedCornerShape(8.dp))
                                                    .background(Color.White),
                                            )
                                        } else {
                                            Box(
                                                modifier = Modifier
                                                    .size(48.dp)
                                                    .background(C.Canvas, RoundedCornerShape(8.dp)),
                                                contentAlignment = Alignment.Center,
                                            ) {
                                                Text("商品", fontSize = T.mini, color = C.TextMuted)
                                            }
                                        }
                                        Spacer(Modifier.width(12.dp))
                                        Column(Modifier.weight(1f)) {
                                            Text(preset.name, color = C.TextPrimary)
                                            val detail = listOf(preset.specText, preset.unit)
                                                .filter { it.isNotBlank() }.joinToString(" · ")
                                            if (detail.isNotBlank()) {
                                                Text(detail, fontSize = T.mini, color = C.TextMuted)
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            },
            confirmButton = {
                TextButton(onClick = { showPresetPicker = false }) { Text("取消") }
            },
        )
    }
}

@Composable
private fun FormField(
    label: String,
    value: String,
    placeholder: String,
    onValueChange: (String) -> Unit,
    keyboardType: KeyboardType = KeyboardType.Text,
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
private fun CategoryChip(label: String, active: Boolean, onClick: () -> Unit) {
    Box(
        modifier = Modifier
            .background(if (active) C.Brand100 else C.Canvas, RoundedCornerShape(dim.radiusPill))
            .clickable { onClick() }
            .padding(horizontal = 14.dp, vertical = 8.dp),
    ) {
        Text(
            label,
            fontSize = T.caption,
            color = if (active) C.Brand700 else C.TextSecondary,
            fontWeight = if (active) FontWeight.SemiBold else FontWeight.Normal,
        )
    }
}

private fun activeProductCategories(categories: List<Category>): List<Category> {
    val result = mutableListOf<Category>()

    fun addEnabled(category: Category, parentEnabled: Boolean, path: String) {
        val enabled = parentEnabled && category.isEnabled
        if (enabled) result += category.copy(name = path, children = emptyList())
        category.children.forEach { child ->
            addEnabled(child, enabled, "$path / ${child.name}")
        }
    }

    categories.forEach { root -> addEnabled(root, parentEnabled = true, path = root.name) }
    return result
}
