package com.chunlian.merchant.navigation

import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import androidx.navigation.navArgument
import android.os.Handler
import android.os.Looper
import com.chunlian.androidupdate.AppUpdatePrompt
import com.chunlian.merchant.MerchantApp
import com.chunlian.merchant.R
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.feature.dashboard.DashboardScreen
import com.chunlian.merchant.feature.orders.OrderDetailScreen
import com.chunlian.merchant.feature.orders.OrderListScreen
import com.chunlian.merchant.feature.notifications.NotificationsScreen
import com.chunlian.merchant.feature.products.ProductEditScreen
import com.chunlian.merchant.feature.products.ProductListScreen
import com.chunlian.merchant.feature.settings.SettingsScreen
import com.chunlian.merchant.feature.stats.StatsScreen
import com.chunlian.merchant.feature.stats.LedgerScreen
import com.chunlian.merchant.ui.components.DividerLine
import com.chunlian.merchant.ui.components.MerchantAssetIcon

/**
 * 应用导航图。
 *
 * 【为什么底部只有 4 个 Tab】
 * 方案 §6.1：工作台 / 订单 / 商品 / 我的。
 * 营业统计放在工作台数字卡与「全部统计」二级页面 ——
 * 统计是低频但重要的动作，给它一个底部位置会挤掉每天都用的入口。
 *
 * 【为什么登录态用 remember 而不是 ViewModel】
 * 登录态的唯一权威是 SessionStore（落盘、加密）。这里只需要一个
 * 触发重组的开关：登录成功 → 置 true；登出 → 置 false。
 * 引入一个 ViewModel 只是把同一个布尔值包了一层。
 */
private data class TabItem(
    val route: String,
    val label: String,
    val selectedIcon: Int,
    val unselectedIcon: Int,
)

private val tabs = listOf(
    TabItem("dashboard", "工作台", R.drawable.merchant_dashboard, R.drawable.merchant_dashboard),
    TabItem("orders", "订单", R.drawable.merchant_orders, R.drawable.merchant_orders),
    TabItem("products", "商品", R.drawable.merchant_product, R.drawable.merchant_product),
    // 素材包没有个人中心的状态图对；保持「我的」语义，文字状态仍由 NavigationBarItem 呈现。
    TabItem("settings", "我的", R.drawable.merchant_user, R.drawable.merchant_user),
)

@Composable
fun MerchantApp() {
    val app = MerchantApp.instance
    AppUpdatePrompt(updater = app.appUpdater)

    // 会话是否有效：初次启动读一次加密存储
    var loggedIn by remember { mutableStateOf(app.session.hasValidSession()) }

    DisposableEffect(app) {
        val mainHandler = Handler(Looper.getMainLooper())
        val unauthorizedHandler: () -> Unit = {
            mainHandler.post { loggedIn = false }
        }
        ApiClient.onUnauthorized = unauthorizedHandler
        onDispose {
            if (ApiClient.onUnauthorized === unauthorizedHandler) ApiClient.onUnauthorized = {}
        }
    }

    if (!loggedIn) {
        AuthenticationGate(onLoggedIn = { loggedIn = true })

        return
    }

    val navController = rememberNavController()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route

    val showBottomBar = tabs.any { it.route == currentRoute }
    val returnToDashboard: () -> Unit = {
        if (!navController.popBackStack("dashboard", inclusive = false)) {
            navController.navigate("dashboard") { launchSingleTop = true }
        }
    }

    Scaffold(
        containerColor = C.Canvas,
        bottomBar = {
            if (showBottomBar) {
                // 顶部分隔线：白底 Tab 栏和奶油白页面之间只差 3% 明度，
                // 不加线时滚动内容会像是「渗」进 Tab 栏。
                Column {
                    DividerLine()
                    NavigationBar(containerColor = C.Surface, tonalElevation = 0.dp) {
                        tabs.forEach { tab ->
                            val selected = currentRoute == tab.route
                            NavigationBarItem(
                                selected = selected,
                                onClick = {
                                    if (currentRoute != tab.route) {
                                        navController.navigate(tab.route) {
                                            // 避免在 Tab 之间来回点把返回栈堆满
                                            popUpTo("dashboard") { saveState = true }
                                            launchSingleTop = true
                                            restoreState = true
                                        }
                                    }
                                },
                                icon = {
                                    MerchantAssetIcon(
                                        resourceId = if (selected) tab.selectedIcon else tab.unselectedIcon,
                                        contentDescription = tab.label,
                                        size = 24.dp,
                                    )
                                },
                                // 选中态同时加粗文字，不只靠颜色区分（规范 §5.1）
                                label = {
                                    Text(
                                        tab.label,
                                        fontSize = T.mini,
                                        fontWeight = if (selected) FontWeight.SemiBold else FontWeight.Normal,
                                    )
                                },
                                colors = NavigationBarItemDefaults.colors(
                                    selectedIconColor = C.Brand700,
                                    selectedTextColor = C.Brand700,
                                    indicatorColor = C.Brand100,
                                    unselectedIconColor = C.TextMuted,
                                    unselectedTextColor = C.TextMuted,
                                ),
                            )
                        }
                    }
                }
            }
        },
    ) { padding ->
        Box(Modifier.fillMaxSize().padding(padding)) {
            NavHost(navController = navController, startDestination = "dashboard") {
                composable("dashboard") {
                    DashboardScreen(
                        onOpenOrder = { id -> navController.navigate("order/$id") },
                        onSeeAllOrders = {
                            navController.navigate("orders") {
                                popUpTo("dashboard") { saveState = true }
                                launchSingleTop = true
                            }
                        },
                        onOpenStats = { navController.navigate("stats") },
                        onOpenNotifications = { navController.navigate("notifications") },
                        onOpenSettings = {
                            navController.navigate("settings") {
                                popUpTo("dashboard") { saveState = true }
                                launchSingleTop = true
                            }
                        },
                    )
                }

                composable("orders") {
                    OrderListScreen(
                        onOpenOrder = { id -> navController.navigate("order/$id") },
                        onBack = returnToDashboard,
                    )
                }

                composable("products") {
                    ProductListScreen(
                        onOpenProduct = { id -> navController.navigate("product/$id") },
                        onCreateProduct = { navController.navigate("product/new") },
                        onBack = returnToDashboard,
                    )
                }

                composable("settings") {
                    SettingsScreen(onBack = returnToDashboard)
                }

                composable("stats") {
                    StatsScreen(
                        onBack = { navController.popBackStack() },
                        onOpenLedger = { navController.navigate("ledger") },
                    )
                }

                composable("ledger") {
                    LedgerScreen(onBack = { navController.popBackStack() })
                }

                composable("notifications") {
                    NotificationsScreen(
                        onBack = { navController.popBackStack() },
                        onOpenOrder = { id -> navController.navigate("order/$id") },
                    )
                }

                composable(
                    route = "order/{id}",
                    arguments = listOf(navArgument("id") { type = NavType.StringType }),
                ) { entry ->
                    OrderDetailScreen(
                        orderId = entry.arguments?.getString("id").orEmpty(),
                        onBack = { navController.popBackStack() },
                    )
                }

                composable(
                    route = "product/{id}",
                    arguments = listOf(navArgument("id") { type = NavType.StringType }),
                ) { entry ->
                    val id = entry.arguments?.getString("id").orEmpty()
                    ProductEditScreen(
                        goodsId = if (id == "new") null else id,
                        onBack = { navController.popBackStack() },
                    )
                }
            }
        }
    }
}
