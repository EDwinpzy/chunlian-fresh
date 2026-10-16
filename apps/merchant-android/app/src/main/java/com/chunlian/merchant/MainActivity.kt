package com.chunlian.merchant

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.Surface
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import com.chunlian.merchant.core.designsystem.ChunlianTheme
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.navigation.MerchantApp as AppNav

/**
 * 唯一的 Activity（方案 §7A.1：单 Activity + Navigation Compose）。
 *
 * 四个主 Tab（工作台 / 订单 / 商品 / 我的）是导航图的顶层目的地，
 * 订单详情、商品编辑等是独立目的地。
 */
class MainActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        // 边到边显示：状态栏与手势条区域由 Compose 用 safeDrawingPadding 处理，
        // 不硬编码状态栏高度（方案 §4.4 的要求）
        enableEdgeToEdge()

        setContent {
            ChunlianTheme {
                Surface(
                    modifier = Modifier
                        .fillMaxSize()
                        .background(C.Canvas),
                    color = Color.Transparent,
                ) {
                    AppNav()
                }
            }
        }
    }
}
