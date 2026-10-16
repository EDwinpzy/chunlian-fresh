package com.chunlian.merchant.feature.notifications

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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import androidx.lifecycle.viewmodel.compose.viewModel
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.data.model.NotificationItem
import com.chunlian.merchant.data.model.Notifications
import com.chunlian.merchant.data.repository.MerchantRepository
import com.chunlian.merchant.ui.components.CardBox
import com.chunlian.merchant.ui.components.ChipTone
import com.chunlian.merchant.ui.components.EmptyBox
import com.chunlian.merchant.ui.components.ListSkeleton
import com.chunlian.merchant.ui.components.LoadingBox
import com.chunlian.merchant.ui.components.MerchantAssetIcon
import com.chunlian.merchant.ui.components.MerchantBackIcon
import com.chunlian.merchant.ui.components.MerchantTopBar
import com.chunlian.merchant.ui.components.NoticeBar
import com.chunlian.merchant.ui.components.StatusChip
import com.chunlian.merchant.ui.components.AutoRefreshEffect
import com.chunlian.merchant.ui.components.PullToRefreshLayout
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.launch

class NotificationsViewModel(private val repo: MerchantRepository) : ViewModel() {
    data class UiState(
        val loading: Boolean = true,
        val refreshing: Boolean = false,
        val hasLoaded: Boolean = false,
        val acting: Boolean = false,
        val error: String? = null,
        val notifications: Notifications = Notifications(),
    )

    private val _state = MutableStateFlow(UiState())
    val state: StateFlow<UiState> = _state.asStateFlow()
    private var loadJob: Job? = null

    fun load() {
        val hasLoaded = _state.value.hasLoaded
        loadJob?.cancel()
        _state.value = _state.value.copy(loading = !hasLoaded, refreshing = hasLoaded, error = null)
        loadJob = viewModelScope.launch {
            try {
                val notifications = repo.notifications(limit = 100)
                _state.value = _state.value.copy(
                    loading = false,
                    refreshing = false,
                    hasLoaded = true,
                    notifications = notifications,
                    error = null,
                )
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                _state.value = _state.value.copy(
                    loading = false,
                    refreshing = false,
                    error = e.message ?: "通知加载失败",
                )
            }
        }
    }

    fun markAllRead() {
        if (_state.value.acting || _state.value.notifications.unreadCount == 0) return
        _state.value = _state.value.copy(acting = true, error = null)
        viewModelScope.launch {
            try {
                repo.markNotificationsRead()
                load()
            } catch (e: Exception) {
                _state.value = _state.value.copy(error = e.message ?: "标记已读失败")
            } finally {
                _state.value = _state.value.copy(acting = false)
            }
        }
    }
}

@Composable
fun NotificationsScreen(onBack: () -> Unit, onOpenOrder: (String) -> Unit) {
    val vm = viewModel { NotificationsViewModel(MerchantApp.instance.repository) }
    val state by vm.state.collectAsState()

    LaunchedEffect(Unit) { vm.load() }
    AutoRefreshEffect(onRefresh = { if (!state.loading && !state.refreshing && !state.acting) vm.load() })

    PullToRefreshLayout(
        refreshing = state.refreshing,
        onRefresh = { if (!state.loading && !state.refreshing && !state.acting) vm.load() },
        modifier = Modifier.fillMaxSize().background(C.Canvas),
    ) {
        Column(
            Modifier.fillMaxSize().safeDrawingPadding(),
        ) {
        MerchantTopBar(Modifier.padding(horizontal = 8.dp)) {
            IconButton(onClick = onBack) {
                MerchantBackIcon()
            }
            Column(Modifier.weight(1f)) {
                Text("通知消息", fontSize = T.section, fontWeight = FontWeight.SemiBold)
                Text("未读 ${state.notifications.unreadCount} 条", fontSize = T.mini, color = C.TextSecondary)
            }
            TextButton(
                onClick = { vm.markAllRead() },
                enabled = !state.acting && state.notifications.unreadCount > 0,
            ) {
                Text(if (state.acting) "处理中…" else "全部已读", color = C.Brand700)
            }
        }

        if (state.error != null) {
            Box(Modifier.padding(horizontal = dim.pagePadding)) {
                NoticeBar(state.error!!, ChipTone.Danger)
            }
            Spacer(Modifier.height(8.dp))
        }

        when {
            state.loading -> Box(Modifier.padding(horizontal = dim.pagePadding)) { ListSkeleton(rows = 4) }
            state.notifications.list.isEmpty() -> LazyColumn(Modifier.fillMaxSize()) {
                item {
                    EmptyBox(
                        title = "暂时没有通知",
                        description = "新订单和订单状态提醒会显示在这里。下拉页面查看最新通知。",
                    )
                }
            }
            else -> LazyColumn(
                modifier = Modifier.fillMaxSize(),
                contentPadding = androidx.compose.foundation.layout.PaddingValues(
                    start = dim.pagePadding,
                    end = dim.pagePadding,
                    bottom = 24.dp,
                ),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(state.notifications.list, key = { it.id }) { item ->
                    NotificationCard(item, onClick = {
                        item.orderId?.takeIf { it.isNotBlank() }?.let(onOpenOrder)
                    })
                }
            }
        }
        }
    }
}

@Composable
private fun NotificationCard(item: NotificationItem, onClick: () -> Unit) {
    val clickable = !item.orderId.isNullOrBlank()
    CardBox(
        modifier = if (clickable) Modifier.clickable(onClick = onClick) else Modifier,
    ) {
        Row(
            Modifier.fillMaxWidth().padding(14.dp),
            horizontalArrangement = Arrangement.spacedBy(10.dp),
            verticalAlignment = Alignment.Top,
        ) {
            Box(
                Modifier
                    .background(if (item.isUnread) C.Brand100 else C.Canvas, RoundedCornerShape(dim.radiusPill))
                    .padding(8.dp),
            ) {
                MerchantAssetIcon(
                    R.drawable.merchant_notification,
                    contentDescription = null,
                    size = 24.dp,
                )
            }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(5.dp)) {
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(item.title.ifBlank { "订单提醒" }, fontSize = T.body, fontWeight = FontWeight.SemiBold)
                    if (item.isUnread) StatusChip("未读", ChipTone.Warning)
                }
                if (item.body.isNotBlank()) {
                    Text(item.body, fontSize = T.caption, color = C.TextSecondary)
                }
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    Text(item.createdAt, fontSize = T.mini, color = C.TextMuted)
                    if (clickable) Text("查看订单", fontSize = T.mini, color = C.Brand700)
                }
            }
        }
    }
}
