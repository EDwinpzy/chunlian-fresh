package com.chunlian.merchant.feature.products

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
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.layout.ContentScale
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

class ProductCategoryViewModel(private val repo: MerchantRepository) : ViewModel() {
    private data class PendingCreate(
        val name: String,
        val parentId: String?,
        val sort: Int,
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

    fun createCategory(name: String, parentId: String? = null) {
        val cleanName = name.trim()
        if (cleanName.isBlank()) {
            _state.value = _state.value.copy(error = "分类名称不能为空")
            return
        }
        if (_state.value.busy) return
        val sort = categoryCreationSort(categorySiblings(_state.value.categories, parentId))
        val attempt = pendingCreate?.takeIf {
            it.name == cleanName && it.parentId == parentId && it.sort == sort
        } ?: PendingCreate(cleanName, parentId, sort).also { pendingCreate = it }
        runCategoryAction("分类已添加", afterSuccess = { pendingCreate = null }) {
            repo.createCategory(
                CategoryCreateRequest(attempt.name, attempt.parentId, attempt.sort),
                attempt.idempotencyKey,
            )
        }
    }

    fun renameCategory(category: Category, name: String) {
        val cleanName = name.trim()
        if (cleanName.isBlank()) {
            _state.value = _state.value.copy(error = "分类名称不能为空")
            return
        }
        runCategoryAction("分类名称已更新") {
            repo.updateCategory(category.id, CategoryPatch(name = cleanName))
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
        if (_state.value.busy) return
        _state.value = _state.value.copy(busy = true, error = null, message = null)
        viewModelScope.launch {
            try {
                action()
                refreshCategories()
                afterSuccess()
                _state.value = _state.value.copy(busy = false, message = successMessage)
            } catch (e: Exception) {
                _state.value = _state.value.copy(busy = false, error = e.message ?: "分类操作失败")
            }
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

    var showCreate by remember { mutableStateOf(false) }
    var categoryName by remember { mutableStateOf("") }
    var renameCategory by remember { mutableStateOf<Category?>(null) }
    var renamedCategory by remember { mutableStateOf("") }
    var deleteCategory by remember { mutableStateOf<Category?>(null) }
    var selectedFilter by remember { mutableStateOf(CategoryFilter.All) }
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

    LaunchedEffect(Unit) { vm.load() }

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
        OutlinedButton(
            onClick = {
                categoryName = ""
                showCreate = true
            },
            enabled = !state.busy,
            modifier = Modifier.fillMaxWidth().heightIn(min = 48.dp),
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
                    onAction = {
                        categoryName = ""
                        showCreate = true
                    },
                )
            }
            visibleCategories.isEmpty() -> Box(Modifier.weight(1f), contentAlignment = Alignment.TopCenter) {
                EmptyBox(
                    title = "没有符合条件的分类",
                    description = "切换分类状态筛选，或新增分类。",
                    actionText = "新增分类",
                    actionModifier = Modifier.widthIn(min = 140.dp).padding(horizontal = 12.dp),
                    onAction = {
                        categoryName = ""
                        showCreate = true
                    },
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
                        busy = state.busy,
                        previewAsset = categoryPreviewAsset(category.name, previewAssets),
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
                        onRename = {
                            renameCategory = it
                            renamedCategory = it.name
                        },
                        onDelete = { deleteCategory = it },
                        onToggle = vm::setCategoryEnabled,
                        onMove = vm::moveCategory,
                    )
                }
            }
        }
    }

    if (showCreate) {
        AlertDialog(
            onDismissRequest = { if (!state.busy) showCreate = false },
            title = { Text("新增分类") },
            text = {
                OutlinedTextField(
                    value = categoryName,
                    onValueChange = { categoryName = it },
                    label = { Text("分类名称") },
                    singleLine = true,
                    shape = RoundedCornerShape(dim.radiusMd),
                    colors = TextFieldDefaults.colors(
                        focusedContainerColor = C.Surface,
                        unfocusedContainerColor = C.Surface,
                    ),
                )
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        vm.createCategory(categoryName)
                        showCreate = false
                    },
                    enabled = !state.busy && categoryName.isNotBlank(),
                ) { Text(if (state.busy) "创建中…" else "创建", color = C.Brand700) }
            },
            dismissButton = {
                TextButton(onClick = { showCreate = false }, enabled = !state.busy) { Text("取消") }
            },
        )
    }

    renameCategory?.let { category ->
        AlertDialog(
            onDismissRequest = { if (!state.busy) renameCategory = null },
            title = { Text("修改分类名称") },
            text = {
                OutlinedTextField(
                    value = renamedCategory,
                    onValueChange = { renamedCategory = it },
                    label = { Text("分类名称") },
                    singleLine = true,
                )
            },
            confirmButton = {
                TextButton(
                    onClick = {
                        vm.renameCategory(category, renamedCategory)
                        renameCategory = null
                    },
                    enabled = !state.busy && renamedCategory.isNotBlank(),
                ) { Text(if (state.busy) "保存中…" else "保存", color = C.Brand700) }
            },
            dismissButton = {
                TextButton(onClick = { renameCategory = null }, enabled = !state.busy) { Text("取消") }
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
    dragging: Boolean,
    dragOffsetY: Float,
    onDragStart: (String) -> Unit,
    onDrag: (String, Float) -> Unit,
    onDragFinished: () -> Unit,
    onRename: (Category) -> Unit,
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
                if (!previewAsset.isNullOrBlank()) {
                    AsyncImage(
                        model = "file:///android_asset/$previewAsset",
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
                        text = { Text("修改名称") },
                        leadingIcon = { Icon(Icons.Outlined.Edit, contentDescription = null) },
                        onClick = {
                            menuExpanded = false
                            onRename(category)
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
