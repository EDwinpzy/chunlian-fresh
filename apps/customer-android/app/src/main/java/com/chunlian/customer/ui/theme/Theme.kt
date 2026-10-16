package com.chunlian.customer.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Typography
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * 春连鲜选 · 顾客端设计 Token（Compose 版）。
 *
 * 来源：docs/design/DESIGN-SYSTEM.md v1.0（风格方案 01 有机鲜绿）。
 * 与顾客端网页版（apps/customer-web/src/styles/tokens.css）和店主端
 * （apps/merchant-android/.../core/designsystem/Theme.kt）**同源同值** ——
 * 顾客在浏览器和 App 里看到的是同一个店。
 *
 * 【命名说明】店主端把 Token 对象命名为 `C`，这里保持历史上的
 * `CustomerColors` 不变（已有 120+ 处引用）。两端的**成员名与色值完全一致**，
 * 只有对象名不同。
 */
object CustomerColors {
    // ---------- 品牌绿 ----------
    val Brand50 = Color(0xFFF2F7F4)
    val Brand100 = Color(0xFFE3EEE6)
    val Brand200 = Color(0xFFC4DCD0)
    val Brand300 = Color(0xFF9CC4B2)
    val Brand400 = Color(0xFF6BA48C)
    val Brand500 = Color(0xFF43806A)
    val Brand600 = Color(0xFF245947)
    val Brand700 = Color(0xFF173F34)
    val Brand800 = Color(0xFF0F2C24)
    val Brand900 = Color(0xFF081B16)

    // ---------- 金色点缀 ----------
    // 只做装饰：特价徽标、印章。绝不做按钮文字主体色（规范 §2.2）。
    val Gold50 = Color(0xFFFDF8EF)
    val Gold100 = Color(0xFFF8EEDB)
    val Gold200 = Color(0xFFEFD9B0)
    val Gold300 = Color(0xFFE3C48C)
    val Gold400 = Color(0xFFD7A766)
    val Gold500 = Color(0xFFC08F4B)
    val Gold600 = Color(0xFFA46B22)
    val Gold700 = Color(0xFF7A4E14)

    /** 主金。等价于 [Gold400]。 */
    val Accent = Gold400

    // ---------- 中性（微暖） ----------
    val Neutral0 = Color(0xFFFFFFFF)
    val Neutral50 = Color(0xFFF8F9F5)
    val Neutral100 = Color(0xFFF1F3EE)
    val Neutral150 = Color(0xFFE7EAE4)
    val Neutral200 = Color(0xFFDCE0D9)
    val Neutral300 = Color(0xFFC3C9C2)
    val Neutral400 = Color(0xFFA2AAA4)
    val Neutral500 = Color(0xFF88938B)
    val Neutral600 = Color(0xFF66736B)
    val Neutral700 = Color(0xFF4A554E)
    val Neutral800 = Color(0xFF313B35)
    val Neutral900 = Color(0xFF1E2823)

    // ---------- 语义别名 ----------
    val Canvas = Neutral50
    val Surface = Neutral0
    val Divider = Neutral150
    val TextPrimary = Neutral900
    val TextSecondary = Neutral600
    val TextMuted = Neutral500

    /**
     * 价格色。
     *
     * 比 [Danger] 更暖、更偏橙 —— 读起来是「标价」而不是「报错」。
     * 价格是顾客每次浏览都要看的信息，用错误红会持续制造轻微焦虑。
     */
    val Price = Color(0xFFB94C3F)

    val Success = Color(0xFF277653)
    val Warning = Color(0xFFA46B22)
    val Danger = Color(0xFFB53B39)
    val Info = Brand600

    val SuccessBg = Color(0xFFE9F4EC)
    val WarningBg = Color(0xFFFFF3E4)
    val DangerBg = Color(0xFFFCEDEB)
    val InfoBg = Color(0xFFEEF3F0)

    /** 深绿卡片（主推卡）上的白字 */
    val OnBrand = Color(0xFFFFFFFF)

    /** 深绿卡片上的次要文字 */
    val OnBrandMuted = Color(0xA6FFFFFF)

    /** 深绿卡片上的金色价格 —— 必须用 Gold300（规范 §2.2） */
    val OnBrandAccent = Gold300

    /** 深绿底上的半透明容器（图标底座、状态胶囊） */
    val OnBrandTranslucent = Color(0x2EFFFFFF)

    /** 深绿底上的弱化容器（未选中态） */
    val OnBrandTranslucentMuted = Color(0x1AFFFFFF)
}

/**
 * 间距与圆角。与店主端 [com.chunlian.merchant.core.designsystem.Dimensions] 同值。
 */
data class Dimensions(
    val pagePadding: Dp = 16.dp,
    val cardPadding: Dp = 16.dp,
    val gap: Dp = 12.dp,
    val sectionGap: Dp = 24.dp,

    // 圆角：15/18/22 这套偏大的取值是「有机」气质的主要来源（规范 §4.2）
    val radiusXs: Dp = 6.dp,
    val radiusSm: Dp = 10.dp,
    val radiusMd: Dp = 15.dp,
    val radiusLg: Dp = 18.dp,
    val radiusXl: Dp = 22.dp,
    val radiusSheet: Dp = 24.dp,
    val radiusPill: Dp = 999.dp,

    /** 任何点击目标不小于 48dp（规范 §5） */
    val touchTarget: Dp = 48.dp,

    /** 商品卡图片高度（1:1 网格下的方形边长） */
    val productImageSize: Dp = 96.dp,
)

val LocalDimensions = staticCompositionLocalOf { Dimensions() }

/**
 * 层级阴影。规范 §4.3：默认卡片不用阴影，靠 1px 边框分层。
 * 阴影只留给商品卡、主推卡与悬浮操作条。
 */
object E {
    val level1: Dp = 2.dp
    val level2: Dp = 4.dp
    val level3: Dp = 6.dp
    val sheet: Dp = 12.dp

    val tint: Color = CustomerColors.Brand700
}

/** 品牌阴影：`Modifier.brandShadow(E.level1, shape)` */
fun Modifier.brandShadow(elevation: Dp, shape: Shape): Modifier =
    shadow(elevation = elevation, shape = shape, ambientColor = E.tint, spotColor = E.tint)

/**
 * 动效时长。规范 §4.4：150/200/300ms。
 * 按压反馈统一为 scale(0.985) + opacity 0.9，不做弹跳、不做旋转。
 */
object Motion {
    const val FAST = 150
    const val BASE = 200
    const val SLOW = 300
}

/**
 * 字号。规范 §3.2 的 375pt 基准。
 * 金额一律用等宽数字（[TABULAR_FIGURES]），保证纵向对齐。
 */
object T {
    val display: TextUnit = 28.sp
    val title: TextUnit = 24.sp
    val headline: TextUnit = 20.sp
    val section: TextUnit = 18.sp
    val product: TextUnit = 15.sp
    val body: TextUnit = 14.sp
    val caption: TextUnit = 12.sp
    val mini: TextUnit = 11.sp

    /** 价格大号。等价于 [headline]。 */
    val price: TextUnit = headline
}

/** 等宽数字：金额、数量、日期专用（规范 §3.3） */
const val TABULAR_FIGURES = "tnum"

private val scheme = lightColorScheme(
    primary = CustomerColors.Brand700,
    onPrimary = Color.White,
    primaryContainer = CustomerColors.Brand100,
    onPrimaryContainer = CustomerColors.Brand700,
    secondary = CustomerColors.Brand600,
    onSecondary = Color.White,
    secondaryContainer = CustomerColors.Brand100,
    onSecondaryContainer = CustomerColors.Brand700,
    tertiary = CustomerColors.Gold400,
    onTertiary = CustomerColors.Gold700,
    tertiaryContainer = CustomerColors.Gold100,
    onTertiaryContainer = CustomerColors.Gold700,
    background = CustomerColors.Canvas,
    onBackground = CustomerColors.TextPrimary,
    surface = CustomerColors.Surface,
    surfaceVariant = CustomerColors.Canvas,
    onSurface = CustomerColors.TextPrimary,
    onSurfaceVariant = CustomerColors.TextSecondary,
    outline = CustomerColors.Divider,
    outlineVariant = CustomerColors.Divider,
    error = CustomerColors.Danger,
    onError = Color.White,
    errorContainer = CustomerColors.DangerBg,
    onErrorContainer = CustomerColors.Danger,
)

/**
 * 顾客端品牌主题。
 *
 * 与店主端同样**强制浅色**（规范 §8）：跟随系统切深色会得到一套
 * 没有验证过对比度的配色，顾客看不清价格是真实事故。
 */
@Composable
fun ChunlianCustomerTheme(
    @Suppress("UNUSED_PARAMETER") darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    CompositionLocalProvider(LocalDimensions provides Dimensions()) {
        MaterialTheme(
            colorScheme = scheme,
            // Typography 直接引用 T，避免与页面用的字号各写一份而漂移
            typography = Typography(
                titleLarge = TextStyle(fontWeight = FontWeight.Bold, fontSize = T.title, lineHeight = 31.sp),
                titleMedium = TextStyle(fontWeight = FontWeight.SemiBold, fontSize = T.section, lineHeight = 24.sp),
                bodyLarge = TextStyle(fontSize = T.product, lineHeight = 21.sp),
                bodyMedium = TextStyle(fontSize = T.body, lineHeight = 22.sp),
                labelSmall = TextStyle(fontSize = T.mini, lineHeight = 15.sp),
            ),
            content = content,
        )
    }
}

/** 便捷读取：`dim.pagePadding` */
val dim: Dimensions
    @Composable get() = LocalDimensions.current

/** 便捷形状构造：`rounded(dim.radiusLg)` */
fun rounded(radius: Dp): Shape = RoundedCornerShape(radius)
