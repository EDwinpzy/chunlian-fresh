package com.chunlian.customer

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.WindowInsets
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.navigationBars
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.ScaffoldDefaults
import androidx.compose.material3.SnackbarHost
import androidx.compose.material3.SnackbarHostState
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.chunlian.androidupdate.AppUpdatePrompt
import com.chunlian.customer.core.permissions.RequestNotificationPermissionOnFirstLaunch
import com.chunlian.customer.data.CustomerTab
import com.chunlian.customer.data.CustomerViewModel
import com.chunlian.customer.ui.AddressesScreen
import com.chunlian.customer.ui.CartScreen
import com.chunlian.customer.ui.CategoryScreen
import com.chunlian.customer.ui.CustomerAssetIcon
import com.chunlian.customer.ui.CustomerImage
import com.chunlian.customer.ui.CheckoutScreen
import com.chunlian.customer.ui.DividerLine
import com.chunlian.customer.ui.HomeScreen
import com.chunlian.customer.ui.LoginScreen
import com.chunlian.customer.ui.OrderDetailScreen
import com.chunlian.customer.ui.OrderInfoScreen
import com.chunlian.customer.ui.OrderItemsScreen
import com.chunlian.customer.ui.OrdersScreen
import com.chunlian.customer.ui.ProductScreen
import com.chunlian.customer.ui.ProfileScreen
import com.chunlian.customer.ui.theme.CustomerColors
import com.chunlian.customer.ui.theme.T

private enum class CustomerScreen { MAIN, PRODUCT, LOGIN, CHECKOUT, ADDRESSES, ORDER_DETAIL, ORDER_INFO, ORDER_ITEMS }
private data class CustomerNavigationItem(
    val tab: CustomerTab,
    val asset: Int,
    val label: String,
)

@Composable
fun CustomerApp(vm: CustomerViewModel = viewModel()) {
    RequestNotificationPermissionOnFirstLaunch()

    val state by vm.state.collectAsState()
    val updateState by vm.appUpdater.state.collectAsState()
    var screen by remember { mutableStateOf(CustomerScreen.MAIN) }
    var selectedTab by remember { mutableStateOf(CustomerTab.HOME) }
    val snackbars = remember { SnackbarHostState() }
    LaunchedEffect(state.notice, state.error) {
        val message = state.error ?: state.notice
        if (!message.isNullOrBlank()) {
            snackbars.showSnackbar(message)
            vm.clearNotice()
        }
    }

    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) {
        vm.refreshStorefront()
    }

    fun back() {
        screen = when (screen) {
            CustomerScreen.PRODUCT -> CustomerScreen.MAIN
            CustomerScreen.LOGIN -> CustomerScreen.MAIN
            CustomerScreen.CHECKOUT -> CustomerScreen.MAIN
            CustomerScreen.ADDRESSES -> CustomerScreen.MAIN
            CustomerScreen.ORDER_DETAIL -> CustomerScreen.MAIN
            CustomerScreen.ORDER_INFO, CustomerScreen.ORDER_ITEMS -> CustomerScreen.ORDER_DETAIL
            CustomerScreen.MAIN -> CustomerScreen.MAIN
        }
    }
    BackHandler(enabled = screen != CustomerScreen.MAIN) { back() }

    Box(Modifier.fillMaxSize()) {
        Scaffold(
            contentWindowInsets = if (screen == CustomerScreen.PRODUCT) {
                // Let the product hero draw behind the status bar; its overlay controls add their own safe inset.
                WindowInsets.navigationBars
            } else {
                ScaffoldDefaults.contentWindowInsets
            },
            snackbarHost = { SnackbarHost(snackbars) },
            bottomBar = {
            if (screen == CustomerScreen.MAIN) {
                // 顶部分隔线：白底 Tab 栏和奶油白页面之间只差 3% 明度，
                // 不加线时滚动内容会像是「渗」进 Tab 栏。
                Column {
                    DividerLine()
                    NavigationBar(containerColor = CustomerColors.Surface, tonalElevation = 0.dp) {
                        val items = listOf(
                            CustomerNavigationItem(CustomerTab.HOME, R.drawable.cust_tab_home_art, "首页"),
                            CustomerNavigationItem(CustomerTab.CATEGORY, R.drawable.cust_category, "分类"),
                            CustomerNavigationItem(CustomerTab.CART, R.drawable.cust_cart, "购物车"),
                            CustomerNavigationItem(CustomerTab.ORDERS, R.drawable.cust_orders, "订单"),
                            CustomerNavigationItem(CustomerTab.PROFILE, R.drawable.cust_tab_profile_art, "我的"),
                        )
                        items.forEach { item ->
                            val selected = selectedTab == item.tab
                            NavigationBarItem(
                                selected = selected,
                                onClick = {
                                    selectedTab = item.tab
                                    vm.selectTab(item.tab)
                                },
                                icon = {
                                    CustomerAssetIcon(item.asset, contentDescription = item.label, size = 24.dp)
                                },
                                // 选中态同时加粗文字，不只靠颜色区分（规范 §5.1）
                                label = {
                                    Text(
                                        item.label,
                                        fontSize = T.mini,
                                        fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                                    )
                                },
                                colors = NavigationBarItemDefaults.colors(
                                    selectedIconColor = CustomerColors.Brand700,
                                    selectedTextColor = CustomerColors.Brand700,
                                    indicatorColor = CustomerColors.Brand100,
                                    unselectedIconColor = CustomerColors.TextMuted,
                                    unselectedTextColor = CustomerColors.TextMuted,
                                ),
                            )
                        }
                    }
                }
            }
            },
        ) { padding ->
            Box(Modifier.fillMaxSize().padding(padding)) {
            when (screen) {
                CustomerScreen.MAIN -> when (selectedTab) {
                    CustomerTab.HOME -> HomeScreen(
                        state,
                        vm,
                        onCategory = { categoryId ->
                            selectedTab = CustomerTab.CATEGORY
                            vm.setCategory(categoryId)
                        },
                        onHomeCategory = vm::selectHomeCategory,
                        onSearch = { keyword ->
                            selectedTab = CustomerTab.CATEGORY
                            vm.searchCatalog(keyword)
                        },
                        onProduct = { screen = CustomerScreen.PRODUCT },
                    )
                    CustomerTab.CATEGORY -> CategoryScreen(state, vm, onProduct = { screen = CustomerScreen.PRODUCT })
                    CustomerTab.CART -> CartScreen(state, vm, onCheckout = {
                        if (state.user == null) screen = CustomerScreen.LOGIN else screen = CustomerScreen.CHECKOUT
                    })
                    CustomerTab.ORDERS -> OrdersScreen(
                        state,
                        onOrder = { id -> vm.openOrder(id); screen = CustomerScreen.ORDER_DETAIL },
                        onLogin = { screen = CustomerScreen.LOGIN },
                        onKeywordChange = vm::setOrdersKeyword,
                    )
                    CustomerTab.PROFILE -> ProfileScreen(state, vm, updateState, onCheckForUpdate = {
                        vm.appUpdater.checkForUpdate(showResult = true)
                    }, onLogin = { screen = CustomerScreen.LOGIN }, onAddresses = {
                        if (state.user == null) screen = CustomerScreen.LOGIN else { vm.loadAddresses(); screen = CustomerScreen.ADDRESSES }
                    }, onOrders = { selectedTab = CustomerTab.ORDERS; vm.selectTab(CustomerTab.ORDERS) })
                }
                CustomerScreen.PRODUCT -> ProductScreen(
                    state,
                    vm,
                    onBack = ::back,
                    onCart = {
                        selectedTab = CustomerTab.CART
                        vm.selectTab(CustomerTab.CART)
                        screen = CustomerScreen.MAIN
                    },
                )
                CustomerScreen.LOGIN -> LoginScreen(state, vm, onBack = ::back, onSuccess = {
                    screen = CustomerScreen.MAIN
                    selectedTab = if (state.tab == CustomerTab.CART) CustomerTab.CART else state.tab
                    vm.selectTab(selectedTab)
                    if (selectedTab == CustomerTab.CART) screen = CustomerScreen.CHECKOUT
                })
                CustomerScreen.CHECKOUT -> CheckoutScreen(state, vm, onBack = ::back, onComplete = {
                    selectedTab = CustomerTab.ORDERS
                    vm.selectTab(CustomerTab.ORDERS)
                    screen = CustomerScreen.MAIN
                })
                CustomerScreen.ADDRESSES -> AddressesScreen(state, vm, onBack = ::back)
                CustomerScreen.ORDER_DETAIL -> OrderDetailScreen(state, vm, onBack = ::back, onEditInfo = { screen = CustomerScreen.ORDER_INFO }, onEditItems = { screen = CustomerScreen.ORDER_ITEMS })
                CustomerScreen.ORDER_INFO -> OrderInfoScreen(state, vm, onBack = ::back)
                CustomerScreen.ORDER_ITEMS -> OrderItemsScreen(state, vm, onBack = ::back)
            }
            }
        }
        AppUpdatePrompt(updater = vm.appUpdater)
        if (state.showNotificationPopup) {
            AlertDialog(
                onDismissRequest = vm::dismissNotificationPopup,
                title = { Text(state.shop.notificationPopupTitle) },
                text = {
                    CustomerImage(
                        url = state.shop.notificationPopupImageUrl,
                        contentDescription = state.shop.notificationPopupTitle,
                        modifier = Modifier.fillMaxWidth().aspectRatio(1f),
                        contentScale = ContentScale.Fit,
                    )
                },
                confirmButton = {
                    TextButton(onClick = vm::dismissNotificationPopup) { Text("关闭") }
                },
            )
        }
    }
}
