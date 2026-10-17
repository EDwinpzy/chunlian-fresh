package com.chunlian.merchant.feature.products

import android.net.Uri
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
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
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGesturesAfterLongPress
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Add
import androidx.compose.material.icons.outlined.Category
import androidx.compose.material.icons.outlined.DeleteOutline
import androidx.compose.material.icons.outlined.DragHandle
import androidx.compose.material.icons.outlined.Edit
import androidx.compose.material.icons.outlined.MoreVert
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TextFieldDefaults
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.zIndex
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import coil.compose.AsyncImage
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.core.media.stageBundledImage
import com.chunlian.merchant.core.media.stagePickedImage
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.data.model.Category
import com.chunlian.merchant.data.model.CategoryCreateRequest
import com.chunlian.merchant.data.model.CategoryPatch
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.NoticeBar
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.contentOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonPrimitive
import java.io.File

class ProductCategoryViewModel(private val repo: MerchantRepository) : ViewModel() {
    private data class PendingCreate(
        val name: String,
        val parentId: String?,
        val sort: Int,
        val icon: String,
        val iconActive: String,
        val idempotencyKey: String = ApiClient.idempotencyKey(),
    )

    data class UiState(
        val loading: Boolean = true,
        val busy: Boolean = false,
        val error: String? = null,
        val message: String? = null,
        val categories: List<Category> = emptyList(),
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var pendingCreate: PendingCreate? = null
    private var loadSequence = 0

    fun load() {
        val request = ++loadSequence
        _state.value = _state.value.copy(loading = _state.value.categories.isEmpty(), error = null)
        viewModelScope.launch {
            try {
                val categories = sortCategoryTree(repo.categories())
                if (request != loadSequence) return@launch
                _state.value = _state.value.copy(loading = false, categories = categories, error = null)
            } catch (e: Exception) {
                if (request != loadSequence) return@launch
                _state.value = _state.value.copy(loading = false, error = e.message ?: "分类加载失败")
            }
        }
    }

    suspend fun createCategory(
        name: String,
        parentId: String? = null,
        sort: Int = categoryCreationSort(categorySiblings(_state.value.categories, parentId)),
        icon: String = "",
        iconActive: String = "",
        refreshAfterSuccess: Boolean = true,
    ): String? {
        val cleanName = name.trim()
        if (cleanName.isBlank()) {
            return "分类名称不能为空"
        }
        val attempt = pendingCreate?.takeIf {
            it.name == cleanName && it.parentId == parentId && it.sort == sort &&
                it.icon == icon && it.iconActive == iconActive
        } ?: PendingCreate(cleanName, parentId, sort, icon, iconActive).also { pendingCreate = it }
        return executeCategoryAction(
            successMessage = "分类已添加",
            afterSuccess = { pendingCreate = null },
            refreshAfterSuccess = refreshAfterSuccess,
        ) {
            repo.createCategory(
                CategoryCreateRequest(
                    name = attempt.name,
                    parentId = attempt.parentId,
                    sort = attempt.sort,
                    icon = attempt.icon,
                    iconActive = attempt.iconActive,
                ),
                attempt.idempotencyKey,
            )
        }
    }

    suspend fun updateCategoryImages(
        category: Category,
        name: String,
        icon: String,
        iconActive: String,
    ): String? {
        val cleanName = name.trim()
        if (cleanName.isBlank()) {
            return "分类名称不能为空"
        }
        return executeCategoryAction("分类已保存") {
            repo.updateCategory(
                category.id,
                CategoryPatch(name = cleanName, icon = icon, iconActive = iconActive),
            )
        }
    }

    fun setCategoryEnabled(category: Category, enabled: Boolean) {
        runCategoryAction(if (enabled) "分类已启用" else "分类已下架") {
            repo.updateCategory(category.id, CategoryPatch(isEnabled = enabled))
        }
    }

    fun deleteCategory(category: Category) {
        runCategoryAction("分类已删除") { repo.deleteCategory(category.id) }
    }

    fun moveCategory(category: Category, delta: Int) {
        if (_state.value.busy) return
        val siblings = categorySiblings(_state.value.categories, category.parentId)
        val from = siblings.indexOfFirst { it.id == category.id }
        if (from < 0) return
        val to = (from + delta).coerceIn(siblings.indices)
        if (to == from) return
        val reordered = siblings.toMutableList().apply { add(to, removeAt(from)) }
        _state.value = _state.value.copy(busy = true, error = null, message = null)
        viewModelScope.launch {
            try {
                reordered.forEachIndexed { index, item ->
                    repo.updateCategory(item.id, CategoryPatch(sort = (reordered.size - index) * 10))
                }
                refreshCategories()
                _state.value = _state.value.copy(busy = false, message = "分类顺序已更新")
            } catch (e: Exception) {
                runCatching { refreshCategories() }
                _state.value = _state.value.copy(busy = false, error = e.message ?: "分类排序失败")
            }
        }
    }

    fun consumeMessage() {
        _state.value = _state.value.copy(message = null)
    }

    private fun runCategoryAction(
        successMessage: String,
        afterSuccess: () -> Unit = {},
        action: suspend () -> Any,
    ) {
        viewModelScope.launch { executeCategoryAction(successMessage, afterSuccess, action = action) }
    }

    private suspend fun executeCategoryAction(
        successMessage: String,
        afterSuccess: () -> Unit = {},
        refreshAfterSuccess: Boolean = true,
        action: suspend () -> Any,
    ): String? {
        if (_state.value.busy) return "分类操作正在进行，请稍后重试"
        _state.value = _state.value.copy(busy = true, error = null, message = null)
        return try {
            action()
            if (refreshAfterSuccess) refreshCategories()
            afterSuccess()
            _state.value = _state.value.copy(busy = false, message = successMessage)
            null
        } catch (e: Exception) {
            val message = e.message ?: "分类操作失败"
            _state.value = _state.value.copy(busy = false, error = message)
            message
        }
    }

    private suspend fun refreshCategories() {
        _state.value = _state.value.copy(categories = sortCategoryTree(repo.categories()))
    }
}

internal fun categoryCreationSort(siblings: List<Category>): Int =
    ((siblings.minOfOrNull { it.sort } ?: 10) - 10).coerceAtLeast(0)

private fun sortCategoryTree(categories: List<Category>): List<Category> = categories
    .sortedWith(compareByDescending<Category> { it.sort }.thenBy { it.name })
    .map { category -> category.copy(children = sortCategoryTree(category.children)) }

private fun categorySiblings(categories: List<Category>, parentId: String?): List<Category> =
    if (parentId.isNullOrBlank() || parentId == "0") {
        categories.filter { it.parentId.isNullOrBlank() || it.parentId == "0" }
    } else {
        findCategory(categories, parentId)?.children.orEmpty()
    }

private fun findCategory(categories: List<Category>, id: String): Category? = categories.firstNotNullOfOrNull { category ->
    if (category.id == id) category else findCategory(category.children, id)
}

@Composable
fun ProductCategoryManagementScreen(
    modifier: Modifier = Modifier,
    previewAssets: Map<String, String?> = emptyMap(),
) {
    val repo = MerchantApp.instance.repository
    val vm = androidx.lifecycle.viewmodel.compose.viewModel { ProductCategoryViewModel(repo) }
    val state by vm.state.collectAsState()
    val context = LocalContext.current
    val scope = rememberCoroutineScope()

    var showEditor by remember { mutableStateOf(false) }
    var editingCategory by remember { mutableStateOf<Category?>(null) }
    var deleteCategory by remember { mutableStateOf<Category?>(null) }
    var selectedFilter by remember { mutableStateOf(CategoryFilter.All) }
    var presetCategories by remember { mutableStateOf<List<PresetCategoryTemplate>>(emptyList()) }
    var presetLoadError by remember { mutableStateOf<String?>(null) }
    var importingPresets by remember { mutableStateOf(false) }
    var importSummary by remember { mutableStateOf<String?>(null) }
    var importHadFailures by remember { mutableStateOf(false) }
    val rootCategories = remember(state.categories) {
        state.categories.filter { it.parentId.isNullOrBlank() || it.parentId == "0" }
    }
    val visibleCategories = remember(rootCategories, selectedFilter) {
        when (selectedFilter) {
            CategoryFilter.All -> rootCategories
            CategoryFilter.Enabled -> rootCategories.filter { it.isEnabled }
            CategoryFilter.Disabled -> rootCategories.filterNot { it.isEnabled }
        }
    }
    val listState = rememberLazyListState()
    var draggedCategoryId by remember { mutableStateOf<String?>(null) }
    var dragOffsetY by remember { mutableFloatStateOf(0f) }

    LaunchedEffect(Unit) {
        vm.load()
        runCatching {
            context.assets.open("preset-categories.json").bufferedReader().use { parsePresetCategories(it.readText()) }
        }.onSuccess {
            presetCategories = it
            presetLoadError = if (it.isEmpty()) "预设分类文件中没有可用分类" else null
        }.onFailure {
            presetLoadError = "预设分类暂不可用"
        }
    }

    val openCreateEditor = {
        editingCategory = null
        showEditor = true
    }

    Column(
        modifier = modifier.fillMaxSize().background(C.Canvas).padding(horizontal = dim.pagePadding),
    ) {
        Spacer(Modifier.height(10.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            CategoryFilter.entries.forEach { filter ->
                val count = when (filter) {
                    CategoryFilter.All -> rootCategories.size
                    CategoryFilter.Enabled -> rootCategories.count { it.isEnabled }
                    CategoryFilter.Disabled -> rootCategories.count { !it.isEnabled }
                }
                CategoryFilterChip(
                    label = filter.label,
                    count = count.takeIf { filter == CategoryFilter.All },
                    selected = selectedFilter == filter,
                    modifier = Modifier.weight(1f),
                    onClick = { selectedFilter = filter },
                )
            }
        }

        Spacer(Modifier.height(12.dp))
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            OutlinedButton(
                onClick = openCreateEditor,
                enabled = !state.busy && !importingPresets,
                modifier = Modifier.weight(1f).heightIn(min = 48.dp),
                shape = RoundedCornerShape(dim.radiusMd),
                border = androidx.compose.foundation.BorderStroke(1.dp, C.Brand500),
                colors = ButtonDefaults.outlinedButtonColors(
                    containerColor = C.Surface,
                    contentColor = C.Brand700,
                    disabledContentColor = C.TextMuted,
                ),
            ) {
                Icon(Icons.Outlined.Add, contentDescription = null, modifier = Modifier.size(18.dp))
                Spacer(Modifier.width(8.dp))
                Text("新增分类", fontSize = T.product, fontWeight = FontWeight.SemiBold)
            }
            OutlinedButton(
                onClick = {
                    scope.launch {
                        importingPresets = true
                        importSummary = null
                        val current = runCatching { repo.categories() }.getOrElse { error ->
                            importHadFailures = true
                            importSummary = "读取现有分类失败：${error.message ?: "请重试"}"
                            importingPresets = false
                            return@launch
                        }
                        val existingNames = current
                            .filter { it.parentId.isNullOrBlank() || it.parentId == "0" }
                            .mapTo(mutableSetOf()) { it.name.trim().lowercase() }
                        var added = 0
                        var skipped = 0
                        var failed = 0
                        val failureSamples = mutableListOf<String>()

                        presetCategories.forEach { template ->
                            val key = template.name.trim().lowercase()
                            if (key.isBlank() || key in existingNames) {
                                skipped += 1
                                return@forEach
                            }
                            val assetPath = template.imageAsset
                            if (assetPath.isNullOrBlank()) {
                                failed += 1
                                failureSamples += "${template.name}：图片路径无效"
                                return@forEach
                            }

                            var stagedImage: File? = null
                            try {
                                stagedImage = stageBundledImage(context, assetPath, "merchant-category")
                                val imagePath = repo.uploadImage(stagedImage)
                                val error = vm.createCategory(
                                    name = template.name,
                                    sort = template.sort,
                                    icon = imagePath,
                                    refreshAfterSuccess = false,
                                )
                                if (error == null) {
                                    added += 1
                                    existingNames += key
                                } else {
                                    failed += 1
                                    failureSamples += "${template.name}：$error"
                                }
                            } catch (error: Exception) {
                                failed += 1
                                failureSamples += "${template.name}：${error.message ?: "导入失败"}"
                            } finally {
                                stagedImage?.delete()
                            }
                        }
                        vm.load()
                        importHadFailures = failed > 0
                        importSummary = buildString {
                            append("导入完成：新增 $added 个，跳过 $skipped 个，失败 $failed 个")
                            if (failureSamples.isNotEmpty()) {
                                append("。")
                                append(failureSamples.take(2).joinToString("；"))
                                if (failureSamples.size > 2) append("；另有 ${failureSamples.size - 2} 项失败")
                            }
                        }
                        importingPresets = false
                    }
                },
                enabled = !state.busy && !importingPresets && presetCategories.isNotEmpty(),
                modifier = Modifier.weight(1f).heightIn(min = 48.dp),
                shape = RoundedCornerShape(dim.radiusMd),
                border = androidx.compose.foundation.BorderStroke(1.dp, C.Brand500),
                colors = ButtonDefaults.outlinedButtonColors(
                    containerColor = C.Surface,
                    contentColor = C.Brand700,
                    disabledContentColor = C.TextMuted,
                ),
            ) {
                Text(if (importingPresets) "正在导入…" else "导入农产品分类", fontSize = T.product, fontWeight = FontWeight.SemiBold)
            }
        }
        if (presetLoadError != null) {
            Text(presetLoadError!!, fontSize = T.mini, color = C.TextMuted, modifier = Modifier.padding(top = 5.dp))
        } else if (presetCategories.isNotEmpty()) {
            Text("分类名称和图片可在导入后编辑", fontSize = T.mini, color = C.TextMuted, modifier = Modifier.padding(top = 5.dp))
        }
        if (importSummary != null) {
            NoticeBar(
                importSummary!!,
                if (importHadFailures) ChipTone.Danger else ChipTone.Success,
                Modifier.padding(top = 8.dp),
            )
        }

        Row(
            modifier = Modifier.fillMaxWidth().padding(top = 14.dp, bottom = 4.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Text("商品分类", fontSize = T.caption, color = C.TextSecondary, modifier = Modifier.weight(1f))
            Text("长按拖动排序", fontSize = T.mini, color = C.TextMuted)
        }

        if (state.error != null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                NoticeBar(state.error!!, ChipTone.Danger, Modifier.weight(1f))
                TextButton(onClick = vm::load, enabled = !state.loading && !state.busy) { Text("重试") }
            }
        }
        if (state.message != null) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(4.dp)) {
                NoticeBar(state.message!!, ChipTone.Success, Modifier.weight(1f))
                TextButton(onClick = vm::consumeMessage) { Text("知道了") }
            }
        }

        when {
            state.loading -> LoadingBox(modifier = Modifier.weight(1f))
            rootCategories.isEmpty() -> Box(Modifier.weight(1f), contentAlignment = Alignment.TopCenter) {
                EmptyBox(
                    title = "还没有分类",
                    description = "先添加分类，再将商品归入对应分类。",
                    actionText = "新增分类",
                    actionModifier = Modifier.widthIn(min = 140.dp).padding(horizontal = 12.dp),
                    onAction = openCreateEditor,
                )
            }
            visibleCategories.isEmpty() -> Box(Modifier.weight(1f), contentAlignment = Alignment.TopCenter) {
                EmptyBox(
                    title = "没有符合条件的分类",
                    description = "切换分类状态筛选，或新增分类。",
                    actionText = "新增分类",
                    actionModifier = Modifier.widthIn(min = 140.dp).padding(horizontal = 12.dp),
                    onAction = openCreateEditor,
                )
            }
            else -> LazyColumn(
                modifier = Modifier.weight(1f),
                state = listState,
                contentPadding = PaddingValues(bottom = 24.dp),
            ) {
                items(visibleCategories, key = { it.id }) { category ->
                    CategoryRow(
                        category = category,
                        allCategories = rootCategories,
                        listState = listState,
                        busy = state.busy || importingPresets,
                        previewAsset = categoryPreviewAsset(category.name, previewAssets),
                        iconUrl = category.icon.takeIf(String::isNotBlank)?.let(repo::absoluteUrl).orEmpty(),
                        dragging = draggedCategoryId == category.id,
                        dragOffsetY = dragOffsetY,
                        onDragStart = { id ->
                            draggedCategoryId = id
                            dragOffsetY = 0f
                        },
                        onDrag = { _, offset -> dragOffsetY = offset },
                        onDragFinished = {
                            draggedCategoryId = null
                            dragOffsetY = 0f
                        },
                        onEdit = {
                            editingCategory = it
                            showEditor = true
                        },
                        onDelete = { deleteCategory = it },
                        onToggle = vm::setCategoryEnabled,
                        onMove = vm::moveCategory,
                    )
                }
            }
        }
    }

    if (showEditor) {
        CategoryEditorDialog(
            category = editingCategory,
            repository = repo,
            busy = state.busy || importingPresets,
            onDismiss = {
                showEditor = false
                editingCategory = null
            },
            onSave = { name, icon, iconActive ->
                val category = editingCategory
                if (category == null) {
                    vm.createCategory(name = name, icon = icon, iconActive = iconActive)
                } else {
                    vm.updateCategoryImages(category, name, icon, iconActive)
                }
            },
        )
    }

    deleteCategory?.let { category ->
        AlertDialog(
            onDismissRequest = { if (!state.busy) deleteCategory = null },
            title = { Text("删除分类") },
            text = {
                Text("确定删除“${category.name}”吗？有商品关联或仍有子分类时，系统会阻止删除。")
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        vm.deleteCategory(category)
                        deleteCategory = null
                    },
                    enabled = !state.busy,
                ) { Text(if (state.busy) "删除中…" else "删除", color = C.Danger) }
            },
            dismissButton = {
                TextButton(onClick = { deleteCategory = null }, enabled = !state.busy) { Text("取消") }
            },
        )
    }
}

internal data class PresetCategoryTemplate(
    val id: String,
    val name: String,
    val imageAsset: String?,
    val sort: Int,
)

internal fun parsePresetCategories(jsonText: String): List<PresetCategoryTemplate> {
    val entries = Json.parseToJsonElement(jsonText) as? JsonArray
        ?: throw IllegalArgumentException("预设分类格式不正确")
    return entries.mapNotNull { element ->
        val item = element as? JsonObject ?: return@mapNotNull null
        val name = item.stringField("name").trim()
        if (name.isBlank()) return@mapNotNull null
        val image = item.stringField("imageAsset").takeIf(::isSafePresetCategoryImageAssetPath)
        PresetCategoryTemplate(
            id = item.stringField("id").ifBlank { name },
            name = name,
            imageAsset = image,
            sort = runCatching { item["sort"]?.jsonPrimitive?.intOrNull }.getOrNull() ?: 0,
        )
    }
}

private fun isSafePresetCategoryImageAssetPath(path: String): Boolean =
    path.matches(Regex("[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*")) &&
        path.split('/').none { it == "." || it == ".." }

private fun JsonObject.stringField(key: String): String =
    this[key]?.let { runCatching { it.jsonPrimitive.contentOrNull }.getOrNull() }.orEmpty()

@Composable
private fun CategoryEditorDialog(
    category: Category?,
    repository: MerchantRepository,
    busy: Boolean,
    onDismiss: () -> Unit,
    onSave: suspend (name: String, icon: String, iconActive: String) -> String?,
) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    val key = category?.id ?: "new"
    var name by remember(key) { mutableStateOf(category?.name.orEmpty()) }
    var iconPath by remember(key) { mutableStateOf(category?.icon.orEmpty()) }
    var iconActivePath by remember(key) { mutableStateOf(category?.iconActive.orEmpty()) }
    var iconFile by remember(key) { mutableStateOf<File?>(null) }
    var iconActiveFile by remember(key) { mutableStateOf<File?>(null) }
    var preparingImage by remember(key) { mutableStateOf(false) }
    var saving by remember(key) { mutableStateOf(false) }
    var error by remember(key) { mutableStateOf<String?>(null) }

    val iconPicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            scope.launch {
                preparingImage = true
                runCatching { stagePickedImage(context, uri, "merchant-category-icon") }
                    .onSuccess { selected ->
                        iconFile?.delete()
                        iconFile = selected
                        error = null
                    }
                    .onFailure { error = it.message ?: "读取图片失败" }
                preparingImage = false
            }
        }
    }
    val iconActivePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetContent()) { uri ->
        if (uri != null) {
            scope.launch {
                preparingImage = true
                runCatching { stagePickedImage(context, uri, "merchant-category-active-icon") }
                    .onSuccess { selected ->
                        iconActiveFile?.delete()
                        iconActiveFile = selected
                        error = null
                    }
                    .onFailure { error = it.message ?: "读取图片失败" }
                preparingImage = false
            }
        }
    }

    DisposableEffect(key) {
        onDispose {
            iconFile?.delete()
            iconActiveFile?.delete()
        }
    }

    AlertDialog(
        onDismissRequest = { if (!busy && !saving && !preparingImage) onDismiss() },
        title = { Text(if (category == null) "新增分类" else "编辑分类") },
        text = {
            Column(
                modifier = Modifier.fillMaxWidth().heightIn(max = 500.dp).verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                OutlinedTextField(
                    value = name,
                    onValueChange = { name = it },
                    label = { Text("分类名称") },
                    singleLine = true,
                    shape = RoundedCornerShape(dim.radiusMd),
                    colors = TextFieldDefaults.colors(
                        focusedContainerColor = C.Surface,
                        unfocusedContainerColor = C.Surface,
                    ),
                    enabled = !busy && !saving,
                )
                CategoryImageField(
                    title = "分类图片",
                    imageModel = iconFile?.let(Uri::fromFile)
                        ?: iconPath.takeIf(String::isNotBlank)?.let(repository::absoluteUrl),
                    enabled = !busy && !saving && !preparingImage,
                    onPick = { iconPicker.launch("image/*") },
                    onClear = {
                        iconFile?.delete()
                        iconFile = null
                        iconPath = ""
                    },
                )
                CategoryImageField(
                    title = "选中态图片（可选）",
                    imageModel = iconActiveFile?.let(Uri::fromFile)
                        ?: iconActivePath.takeIf(String::isNotBlank)?.let(repository::absoluteUrl),
                    enabled = !busy && !saving && !preparingImage,
                    onPick = { iconActivePicker.launch("image/*") },
                    onClear = {
                        iconActiveFile?.delete()
                        iconActiveFile = null
                        iconActivePath = ""
                    },
                )
                if (preparingImage) Text("正在处理图片…", fontSize = T.caption, color = C.TextSecondary)
                error?.let { NoticeBar(it, ChipTone.Danger) }
            }
        },
        confirmButton = {
            TextButton(
                onClick = {
                    scope.launch {
                        saving = true
                        error = null
                        try {
                            val uploadedIcon = iconFile?.let { repository.uploadImage(it) } ?: iconPath
                            iconPath = uploadedIcon
                            iconFile?.delete()
                            iconFile = null
                            val uploadedActiveIcon = iconActiveFile?.let { repository.uploadImage(it) } ?: iconActivePath
                            iconActivePath = uploadedActiveIcon
                            iconActiveFile?.delete()
                            iconActiveFile = null
                            val saveError = onSave(name.trim(), uploadedIcon, uploadedActiveIcon)
                            if (saveError == null) onDismiss() else error = saveError
                        } catch (e: Exception) {
                            error = e.message ?: "保存分类失败"
                        } finally {
                            saving = false
                        }
                    }
                },
                enabled = !busy && !saving && !preparingImage && name.isNotBlank(),
            ) { Text(if (saving || busy) "保存中…" else "保存", color = C.Brand700) }
        },
        dismissButton = {
            TextButton(onClick = onDismiss, enabled = !busy && !saving && !preparingImage) { Text("取消") }
        },
    )
}

@Composable
private fun CategoryImageField(
    title: String,
    imageModel: Any?,
    enabled: Boolean,
    onPick: () -> Unit,
    onClear: () -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Box(
            modifier = Modifier.size(68.dp).clip(RoundedCornerShape(14.dp)).background(C.Brand100),
            contentAlignment = Alignment.Center,
        ) {
            if (imageModel != null) {
                AsyncImage(
                    model = imageModel,
                    contentDescription = title,
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize(),
                )
            } else {
                Icon(Icons.Outlined.Category, contentDescription = null, tint = C.Brand700)
            }
        }
        Column(modifier = Modifier.weight(1f)) {
            Text(title, fontSize = T.caption, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
            Row {
                TextButton(onClick = onPick, enabled = enabled) { Text(if (imageModel == null) "选择图片" else "更换") }
                if (imageModel != null) TextButton(onClick = onClear, enabled = enabled) { Text("移除") }
            }
        }
    }
}

private enum class CategoryFilter(val label: String) {
    All("全部"),
    Enabled("已启用"),
    Disabled("已下架"),
}

private fun categoryPreviewAsset(name: String, previewAssets: Map<String, String?>): String? {
    val trimmedName = name.trim()
    previewAssets[trimmedName]?.let { return it }
    val categoryStem = trimmedName.removeSuffix("类")
    return previewAssets.entries.firstOrNull { (presetCategory, asset) ->
        val presetStem = presetCategory.removeSuffix("类")
        asset != null && (
            (presetStem.length >= 2 && trimmedName.contains(presetStem)) ||
                (categoryStem.length >= 2 && presetCategory.contains(categoryStem))
            )
    }?.value
}

@Composable
private fun CategoryFilterChip(
    label: String,
    count: Int?,
    selected: Boolean,
    modifier: Modifier = Modifier,
    onClick: () -> Unit,
) {
    Row(
        modifier = modifier
            .height(42.dp)
            .clip(RoundedCornerShape(dim.radiusMd))
            .background(if (selected) C.Brand100 else C.Surface)
            .clickable(onClick = onClick)
            .padding(horizontal = 8.dp),
        horizontalArrangement = Arrangement.Center,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(
            label,
            fontSize = T.caption,
            fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Medium,
            color = if (selected) C.Brand700 else C.TextSecondary,
        )
        if (count != null) {
            Spacer(Modifier.width(4.dp))
            Text(count.toString(), fontSize = T.mini, color = if (selected) C.Brand500 else C.TextMuted)
        }
    }
}

@Composable
private fun CategoryRow(
    category: Category,
    allCategories: List<Category>,
    listState: LazyListState,
    busy: Boolean,
    previewAsset: String?,
    iconUrl: String,
    dragging: Boolean,
    dragOffsetY: Float,
    onDragStart: (String) -> Unit,
    onDrag: (String, Float) -> Unit,
    onDragFinished: () -> Unit,
    onEdit: (Category) -> Unit,
    onDelete: (Category) -> Unit,
    onToggle: (Category, Boolean) -> Unit,
    onMove: (Category, Int) -> Unit,
) {
    var menuExpanded by remember(category.id) { mutableStateOf(false) }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .zIndex(if (dragging) 1f else 0f)
            .graphicsLayer {
                translationY = if (dragging) dragOffsetY else 0f
                shadowElevation = if (dragging) 8.dp.toPx() else 0f
            }
            .background(C.Surface),
    ) {
        Row(
            modifier = Modifier.fillMaxWidth().heightIn(min = 88.dp).padding(vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            Box(
                modifier = Modifier
                    .size(34.dp)
                    .pointerInput(category.id, busy, allCategories, listState) {
                        if (!busy) {
                            var accumulatedDragY = 0f
                            detectDragGesturesAfterLongPress(
                                onDragStart = {
                                    accumulatedDragY = 0f
                                    onDragStart(category.id)
                                },
                                onDragEnd = {
                                    val sourceIndex = allCategories.indexOfFirst { it.id == category.id }
                                    val draggedItem = listState.layoutInfo.visibleItemsInfo
                                        .firstOrNull { it.key == category.id }
                                    val targetCenter = draggedItem?.let {
                                        it.offset + it.size / 2f + accumulatedDragY
                                    }
                                    val targetItem = targetCenter?.let { center ->
                                        listState.layoutInfo.visibleItemsInfo.minByOrNull { item ->
                                            kotlin.math.abs(item.offset + item.size / 2f - center)
                                        }
                                    }
                                    val targetId = targetItem?.key as? String
                                    val targetIndex = allCategories.indexOfFirst { it.id == targetId }
                                    if (sourceIndex >= 0 && targetIndex >= 0 && sourceIndex != targetIndex) {
                                        onMove(category, targetIndex - sourceIndex)
                                    }
                                    onDragFinished()
                                },
                                onDragCancel = onDragFinished,
                            ) { change, amount ->
                                change.consume()
                                accumulatedDragY += amount.y
                                onDrag(category.id, accumulatedDragY)
                            }
                        }
                    },
                contentAlignment = Alignment.Center,
            ) {
                Icon(
                    Icons.Outlined.DragHandle,
                    contentDescription = "长按拖动排序",
                    tint = if (busy) C.Divider else C.TextMuted,
                    modifier = Modifier.size(24.dp),
                )
            }

            Box(
                modifier = Modifier
                    .size(58.dp)
                    .clip(RoundedCornerShape(14.dp))
                    .background(C.Brand100),
                contentAlignment = Alignment.Center,
            ) {
                val imageModel = iconUrl.takeIf(String::isNotBlank)
                    ?: previewAsset?.let { "file:///android_asset/$it" }
                if (imageModel != null) {
                    AsyncImage(
                        model = imageModel,
                        contentDescription = category.name,
                        contentScale = ContentScale.Crop,
                        modifier = Modifier.fillMaxSize().graphicsLayer { alpha = if (category.isEnabled) 1f else 0.55f },
                    )
                } else {
                    Icon(
                        Icons.Outlined.Category,
                        contentDescription = null,
                        tint = if (category.isEnabled) C.Brand700 else C.TextMuted,
                        modifier = Modifier.size(27.dp),
                    )
                }
            }

            Column(
                modifier = Modifier.weight(1f),
                verticalArrangement = Arrangement.spacedBy(3.dp),
            ) {
                Text(
                    category.name,
                    fontSize = T.body,
                    fontWeight = FontWeight.SemiBold,
                    color = if (category.isEnabled) C.TextPrimary else C.TextMuted,
                    maxLines = 1,
                )
                Text(
                    "商品 ${category.productCount.coerceAtLeast(0)} 个",
                    fontSize = T.caption,
                    color = C.TextSecondary,
                    maxLines = 1,
                )
            }

            Switch(
                checked = category.isEnabled,
                onCheckedChange = { onToggle(category, it) },
                enabled = !busy,
                colors = SwitchDefaults.colors(
                    checkedThumbColor = C.Surface,
                    checkedTrackColor = C.Brand500,
                    checkedBorderColor = C.Brand500,
                    uncheckedThumbColor = C.Surface,
                    uncheckedTrackColor = C.Divider,
                    uncheckedBorderColor = C.Divider,
                ),
            )

            Box {
                IconButton(onClick = { menuExpanded = true }, enabled = !busy, modifier = Modifier.size(38.dp)) {
                    Icon(Icons.Outlined.MoreVert, contentDescription = "更多分类操作", tint = C.TextSecondary)
                }
                DropdownMenu(expanded = menuExpanded, onDismissRequest = { menuExpanded = false }) {
                    DropdownMenuItem(
                        text = { Text("编辑分类和图片") },
                        leadingIcon = { Icon(Icons.Outlined.Edit, contentDescription = null) },
                        onClick = {
                            menuExpanded = false
                            onEdit(category)
                        },
                    )
                    HorizontalDivider(color = C.Divider)
                    DropdownMenuItem(
                        text = { Text("删除分类", color = C.Danger) },
                        leadingIcon = { Icon(Icons.Outlined.DeleteOutline, contentDescription = null, tint = C.Danger) },
                        onClick = {
                            menuExpanded = false
                            onDelete(category)
                        },
                    )
                }
            }
        }
        HorizontalDivider(color = C.Divider)
    }
}
