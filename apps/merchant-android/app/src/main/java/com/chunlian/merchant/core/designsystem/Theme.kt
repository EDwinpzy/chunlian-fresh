package com.chunlian.merchant.core.designsystem

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
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp

/**
 * 春连鲜选 · 店主端设计 Token（Compose 版）。
 *
 * 来源：docs/design/DESIGN-SYSTEM.md v1.0（风格方案 01 有机鲜绿）。
 * 色值与顾客端网页版（apps/customer-web/src/styles/tokens.css）、
 * 顾客端安卓（apps/customer-android/.../ui/theme/Theme.kt）**逐字一致** ——
 * 同一个店在三个端上必须是同一个颜色，否则店主自己都会怀疑是不是进错了系统。
 *
 * 【为什么不用 Material 的 dynamicColor】
 * 设计规范 §8：关闭随系统变化的动态品牌主色。
 * 店主换个壁纸，收款按钮不该从深绿变成紫色。
 */
object C {
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
    // 只做装饰：徽标底、分隔线。绝不做按钮文字主体色（§2.2）。
    val Gold50 = Color(0xFFFDF8EF)
    val Gold100 = Color(0xFFF8EEDB)
    val Gold200 = Color(0xFFEFD9B0)
    val Gold300 = Color(0xFFE3C48C)
    val Gold400 = Color(0xFFD7A766)
    val Gold500 = Color(0xFFC08F4B)
    val Gold600 = Color(0xFFA46B22)
    val Gold700 = Color(0xFF7A4E14)

    /** 主金。历史命名，等价于 [Gold400]。 */
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

    // ---------- 语义别名（页面直接用的就是这一层） ----------
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
     * 价格是每天要看几十次的高频信息，用错误红会持续制造轻微焦虑。
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

    /** 深绿卡片上的白字 —— 营收卡专用 */
    val OnBrand = Color(0xFFFFFFFF)

    /** 深绿卡片上的次要文字（标签、辅助说明） */
    val OnBrandMuted = Color(0xA6FFFFFF)

    /** 深绿卡片上的金色价格 —— 必须用 Gold300，Gold400 在深绿上对比度偏弱（§2.2） */
    val OnBrandAccent = Gold300

    /**
     * 深绿底上的半透明容器（营业状态胶囊、图标底座）。
     *
     * 【为什么不用 Material 的 surfaceVariant】
     * 那是「浅色表面」，放到深绿卡上会变成一块突兀的灰白。
     * 深绿底上的容器只能靠白色透明度分层。
     */
    val OnBrandTranslucent = Color(0x2EFFFFFF)

    /** 深绿底上的弱化容器（未选中 / 打烊态） */
    val OnBrandTranslucentMuted = Color(0x1AFFFFFF)
}

/**
 * 间距与圆角。
 *
 * 设计规范 §4.1 的 8pt 栅格：4/8/12/16/20/24/32/40。
 * 用 data class + CompositionLocal 而不是散落的常量，
 * 是为了将来做平板/横屏时能整体替换而不改每个页面。
 */
data class Dimensions(
    val pagePadding: Dp = 16.dp,
    val cardPadding: Dp = 16.dp,
    val gap: Dp = 12.dp,
    val sectionGap: Dp = 24.dp,

    // 圆角：15/18/22 这套偏大的取值是「有机」气质的主要来源（§4.2）
    val radiusXs: Dp = 6.dp,
    val radiusSm: Dp = 10.dp,
    val radiusMd: Dp = 15.dp,
    val radiusLg: Dp = 18.dp,
    val radiusXl: Dp = 22.dp,
    val radiusSheet: Dp = 24.dp,
    val radiusPill: Dp = 999.dp,

    /** 任何点击目标不小于 48dp（§5） */
    val touchTarget: Dp = 48.dp,
    val metricCardMinHeight: Dp = 92.dp,
    val revenueCardHeight: Dp = 128.dp,
)

val LocalDimensions = staticCompositionLocalOf { Dimensions() }

/**
 * 层级阴影。
 *
 * 设计规范 §4.3：**默认卡片不用阴影**，靠 1px 边框分层，阴影是稀缺资源。
 * 阴影颜色用深绿而不是纯黑 —— 纯黑阴影在奶油白底上会显脏。
 */
object E {
    val level1: Dp = 2.dp
    val level2: Dp = 4.dp
    val level3: Dp = 6.dp
    val sheet: Dp = 12.dp

    /** 阴影染色，对应规范里的 rgba(23,63,52,…) */
    val tint: Color = C.Brand700
}

/** 品牌阴影：`Modifier.brandShadow(E.level2, shape)` */
fun Modifier.brandShadow(elevation: Dp, shape: Shape): Modifier =
    shadow(elevation = elevation, shape = shape, ambientColor = E.tint, spotColor = E.tint)

/**
 * 动效时长。
 *
 * 设计规范 §4.4：150/200/300ms。不做弹跳、不做旋转，
 * 按压反馈统一为 scale(0.985) + opacity 0.9。
 */
object Motion {
    const val FAST = 150
    const val BASE = 200
    const val SLOW = 300
}

/**
 * 字号。
 *
 * 设计规范 §3.2 的 375pt 基准直接搬到 sp（安卓的 sp 已含系统缩放）。
 * 数字一律用 [num] 系列并开启 tabular figures ——
 * 金额纵向对齐是「一眼看清今天收了多少钱」的前提。
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

    /** 价格大号。历史命名，等价于 [headline]。 */
    val price: TextUnit = headline
}

/** 等宽数字：金额、数量、日期专用（§3.3） */
const val TABULAR_FIGURES = "tnum"

/**
 * Material 的 Typography 直接引用 [T]，不重复写字号。
 *
 * 【为什么不在两处各写一遍 24.sp】
 * 页面代码读的是 `T.xxx`，但 Material 自己的组件（TopAppBar、AlertDialog、
 * Snackbar）读的是 Typography。两处各写一份，改字号时必然漏掉一处 ——
 * 结果就是「自己写的标题变了，弹窗标题还是旧尺寸」。
 */
private val ChunlianTypography = Typography(
    titleLarge = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Bold,
        fontSize = T.title,
        lineHeight = 31.sp,
    ),
    titleMedium = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.SemiBold,
        fontSize = T.section,
        lineHeight = 24.sp,
    ),
    bodyLarge = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Normal,
        fontSize = T.body,
        lineHeight = 22.sp,
    ),
    bodyMedium = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Normal,
        fontSize = T.caption,
        lineHeight = 18.sp,
    ),
    labelSmall = TextStyle(
        fontFamily = FontFamily.Default,
        fontWeight = FontWeight.Normal,
        fontSize = T.mini,
        lineHeight = 15.sp,
    ),
)

private val ChunlianColors = lightColorScheme(
    primary = C.Brand700,
    onPrimary = Color.White,
    primaryContainer = C.Brand100,
    onPrimaryContainer = C.Brand700,
    secondary = C.Brand600,
    onSecondary = Color.White,
    secondaryContainer = C.Brand100,
    onSecondaryContainer = C.Brand700,
    tertiary = C.Gold400,
    onTertiary = C.Gold700,
    tertiaryContainer = C.Gold100,
    onTertiaryContainer = C.Gold700,
    background = C.Canvas,
    onBackground = C.TextPrimary,
    surface = C.Surface,
    onSurface = C.TextPrimary,
    surfaceVariant = C.Canvas,
    onSurfaceVariant = C.TextSecondary,
    outline = C.Divider,
    outlineVariant = C.Divider,
    error = C.Danger,
    onError = Color.White,
    errorContainer = C.DangerBg,
    onErrorContainer = C.Danger,
)

/**
 * 品牌主题。
 *
 * 【为什么强制浅色，不跟随系统深色】
 * 设计规范 §8 的整体气质是「安静、精致、可信的精品生鲜零售」，
 * 且首期只交付了浅色一套 Token。跟着系统切成深色会得到
 * 一套没有验证过对比度的配色 —— 收款金额看不清是真实事故，
 * 因此这里宁可先固定浅色，等深色 Token 定稿再开。
 */
@Composable
fun ChunlianTheme(
    @Suppress("UNUSED_PARAMETER") darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    CompositionLocalProvider(LocalDimensions provides Dimensions()) {
        MaterialTheme(
            colorScheme = ChunlianColors,
            typography = ChunlianTypography,
            content = content,
        )
    }
}

/** 便捷读取：`dim.pagePadding` */
val dim: Dimensions
    @Composable get() = LocalDimensions.current

/** 便捷形状构造：`C.rounded(dim.radiusLg)` */
fun rounded(radius: Dp): Shape = RoundedCornerShape(radius)
