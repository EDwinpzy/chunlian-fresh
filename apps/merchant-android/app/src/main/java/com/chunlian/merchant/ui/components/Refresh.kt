package com.chunlian.merchant.ui.components

import androidx.compose.material.ExperimentalMaterialApi
import androidx.compose.material.pullrefresh.PullRefreshIndicator
import androidx.compose.material.pullrefresh.pullRefresh
import androidx.compose.material.pullrefresh.rememberPullRefreshState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxScope
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.repeatOnLifecycle
import com.chunlian.merchant.core.designsystem.C
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive

/** Pull down on a scrollable page to reload its data. */
@OptIn(ExperimentalMaterialApi::class)
@Composable
fun PullToRefreshLayout(
    refreshing: Boolean,
    onRefresh: () -> Unit,
    enabled: Boolean = true,
    modifier: Modifier = Modifier,
    content: @Composable BoxScope.() -> Unit,
) {
    val state = rememberPullRefreshState(refreshing = refreshing, onRefresh = onRefresh)
    Box(modifier.pullRefresh(state, enabled = enabled)) {
        content()
        PullRefreshIndicator(
            refreshing = refreshing,
            state = state,
            modifier = Modifier.align(Alignment.TopCenter),
            backgroundColor = C.Surface,
            contentColor = C.Brand700,
        )
    }
}

/** Periodically reloads while this screen is the active, resumed destination. */
@Composable
fun AutoRefreshEffect(
    onRefresh: () -> Unit,
    enabled: Boolean = true,
    intervalMillis: Long = 30_000L,
) {
    val lifecycleOwner = LocalLifecycleOwner.current
    val latestOnRefresh by rememberUpdatedState(onRefresh)

    LaunchedEffect(lifecycleOwner, enabled, intervalMillis) {
        if (!enabled) return@LaunchedEffect
        lifecycleOwner.lifecycle.repeatOnLifecycle(Lifecycle.State.RESUMED) {
            while (isActive) {
                delay(intervalMillis)
                latestOnRefresh()
            }
        }
    }
}
