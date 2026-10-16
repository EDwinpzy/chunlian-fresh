package com.chunlian.customer.ui

import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.chunlian.customer.R
import com.chunlian.customer.ui.theme.CustomerColors
import com.chunlian.customer.ui.theme.E
import com.chunlian.customer.ui.theme.Motion
import com.chunlian.customer.ui.theme.T
import com.chunlian.customer.ui.theme.TABULAR_FIGURES
import com.chunlian.customer.ui.theme.brandShadow
import com.chunlian.customer.ui.theme.dim

/**
 * 春连鲜选 · 顾客端共用组件库。
 *
 * 来源：docs/design/DESIGN-SYSTEM.md v1.0 §7。
 * 与店主端 `apps/merchant-android/.../ui/components/Common.kt` 一一对应 ——
 * 两端同名组件的尺寸、圆角、色值完全一致，改一处就要改另一处。
 *
 * 这个文件只放**新增**组件；历史上已存在并大量复用的那几个
 * （ScreenFrame / SelectionChip / EmptyState / InfoBanner / ProductImage /
 * QuantityControl）保留在 CustomerScreens.kt 原地升级，避免无意义的搬迁。
 */

/** 顾客端统一搜索框：48dp 高度、左侧搜索图标和可选提交按钮。 */
@Composable
fun CustomerSearchField(
    value: String,
    onValueChange: (String) -> Unit,
    placeholder: String,
    modifier: Modifier = Modifier,
    showSearchButton: Boolean = true,
    imeAction: ImeAction = ImeAction.Search,
    onSearch: () -> Unit = {},
) {
    var focused by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(dim.radiusMd)

    Row(
        modifier = modifier
            .fillMaxWidth()
            .height(dim.touchTarget)
            .background(CustomerColors.Surface, shape)
            .border(1.dp, if (focused) CustomerColors.Brand600 else CustomerColors.Neutral200, shape)
            .padding(start = 12.dp, end = if (showSearchButton) 0.dp else 12.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        CustomerAssetIcon(R.drawable.cust_search, contentDescription = null, size = 20.dp)
        Spacer(Modifier.width(8.dp))
        BasicTextField(
            value = value,
            onValueChange = onValueChange,
            modifier = Modifier
                .weight(1f)
                .onFocusChanged { focused = it.isFocused }
                .semantics { contentDescription = placeholder },
            singleLine = true,
            textStyle = TextStyle(color = CustomerColors.TextPrimary, fontSize = T.body),
            cursorBrush = SolidColor(CustomerColors.Brand600),
            keyboardOptions = KeyboardOptions(imeAction = imeAction),
            keyboardActions = KeyboardActions(onSearch = { if (imeAction == ImeAction.Search) onSearch() }),
            decorationBox = { innerTextField ->
                Box(contentAlignment = Alignment.CenterStart) {
                    if (value.isEmpty()) {
                        Text(placeholder, fontSize = T.body, color = CustomerColors.Neutral400, maxLines = 1)
                    }
                    innerTextField()
                }
            },
        )
        if (showSearchButton) {
            TextButton(
                onClick = onSearch,
                modifier = Modifier.padding(end = 6.dp).height(36.dp),
                contentPadding = PaddingValues(horizontal = 10.dp),
            ) {
                Text("搜索", color = CustomerColors.Brand700, fontSize = T.caption, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

// ============================================================
// 金额（规范 §3.3）
// ============================================================

/**
 * 整数分 → 「3.50」。
 *
 * 【为什么不用 `fen / 100.0`】
 * 浮点除法在金额上会产生 0.1 + 0.2 = 0.30000000000000004 这类误差，
 * 累加几十笔订单后就会出现「合计对不上」的投诉。
 * 这里全程整数运算，只在最后拼字符串。
 *
 * 【为什么单独处理负数】
 * `-50 / 100` 得 0、`-50 % 100` 得 -50，直接拼接会输出「¥0.-50」。
 * 退款与差额冲减都会走到负分，所以必须先取绝对值再补符号。
 */
fun fen2yuan(fen: Long): String {
    val negative = fen < 0
    val abs = if (negative) -fen else fen
    val text = "${abs / 100}.${(abs % 100).toString().padStart(2, '0')}"

    return if (negative) "-$text" else text
}

/** 差额：「+¥9.40」/「-¥3.00」/「¥0.00」 */
fun deltaMoney(fen: Long): String = when {
    fen > 0 -> "+¥${fen2yuan(fen)}"
    fen < 0 -> "-¥${fen2yuan(-fen)}"
    else -> "¥0.00"
}

/** 用户输入的「元」文本 → 整数分（四舍五入到分，避免 3.5 → 349） */
fun yuan2fen(input: String): Long {
    val cleaned = input.trim().replace(",", "")
    if (cleaned.isEmpty()) return 0

    return Math.round((cleaned.toDoubleOrNull() ?: return 0) * 100)
}

/** 等宽数字样式：金额、数量、日期专用 */
fun tabularStyle(
    size: TextUnit,
    weight: FontWeight = FontWeight.Normal,
    color: Color = CustomerColors.TextPrimary,
): TextStyle = TextStyle(
    fontSize = size,
    fontWeight = weight,
    color = color,
    fontFeatureSettings = TABULAR_FIGURES,
)

// ============================================================
// 状态标签（规范 §7.2）
// ============================================================

enum class ChipTone { Success, Warning, Danger, Info, Muted }

fun chipColors(tone: ChipTone): Pair<Color, Color> = when (tone) {
    ChipTone.Success -> CustomerColors.Success to CustomerColors.SuccessBg
    ChipTone.Warning -> CustomerColors.Warning to CustomerColors.WarningBg
    ChipTone.Danger -> CustomerColors.Danger to CustomerColors.DangerBg
    ChipTone.Info -> CustomerColors.Brand600 to CustomerColors.InfoBg
    ChipTone.Muted -> CustomerColors.TextSecondary to CustomerColors.Canvas
}

/** 状态标签。**必须带文字**，不能只靠颜色（规范 §1 原则 4） */
@Composable
fun StatusChip(text: String, tone: ChipTone, modifier: Modifier = Modifier) {
    val (fg, bg) = chipColors(tone)

    Box(
        modifier = modifier
            .background(bg, RoundedCornerShape(dim.radiusPill))
            .padding(horizontal = 8.dp, vertical = 2.dp),
    ) {
        Text(text = text, color = fg, fontSize = T.mini, maxLines = 1)
    }
}

// ============================================================
// 价格（规范 §7.7）
// ============================================================

/**
 * 价格文本。
 *
 * 价格用 [CustomerColors.Price] 而不是 [CustomerColors.Danger] ——
 * 前者更暖，读起来是「标价」而不是「报错」。
 */
@Composable
fun PriceText(
    fen: Long,
    modifier: Modifier = Modifier,
    size: TextUnit = T.headline,
    unit: String? = null,
    color: Color = CustomerColors.Price,
) {
    Row(modifier = modifier, verticalAlignment = Alignment.Bottom) {
        Text(
            "¥",
            color = color,
            fontSize = T.mini,
            fontWeight = FontWeight.SemiBold,
            modifier = Modifier.padding(bottom = 2.dp),
        )
        Text(fen2yuan(fen), color = color, style = tabularStyle(size, FontWeight.Bold, color))
        if (unit != null) {
            Text(unit, color = CustomerColors.TextMuted, fontSize = T.mini, modifier = Modifier.padding(start = 2.dp, bottom = 2.dp))
        }
    }
}

/** 划线原价 */
@Composable
fun StrikethroughPrice(fen: Long, modifier: Modifier = Modifier) {
    Text(
        "¥${fen2yuan(fen)}",
        modifier = modifier,
        color = CustomerColors.TextMuted,
        fontSize = T.mini,
        style = TextStyle(textDecoration = TextDecoration.LineThrough, fontSize = T.mini, color = CustomerColors.TextMuted),
    )
}

// ============================================================
// 按钮（规范 §7.1）
// ============================================================

/**
 * 按压反馈：scale(0.985) + alpha，150ms。
 * 规范 §4.4 —— 不做弹跳、不做旋转。
 */
@Composable
private fun pressScale(interaction: MutableInteractionSource, active: Boolean): Float {
    val pressed by interaction.collectIsPressedAsState()
    val scale by animateFloatAsState(
        targetValue = if (pressed && active) 0.985f else 1f,
        animationSpec = tween(Motion.FAST),
        label = "pressScale",
    )

    return scale
}

@Composable
fun PrimaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    loading: Boolean = false,
) {
    val interaction = remember { MutableInteractionSource() }
    val active = enabled && !loading
    val scale = pressScale(interaction, active)

    Box(
        modifier = modifier
            .height(dim.touchTarget)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
            .alpha(if (active) 1f else 0.45f)
            .background(CustomerColors.Brand700, RoundedCornerShape(dim.radiusMd))
            .clickable(
                enabled = active,
                interactionSource = interaction,
                indication = null,
                onClick = onClick,
            ),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) {
            CircularProgressIndicator(color = CustomerColors.OnBrand, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
        } else {
            Text(text, color = CustomerColors.OnBrand, fontSize = T.product, fontWeight = FontWeight.SemiBold)
        }
    }
}

@Composable
fun SecondaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
) {
    val interaction = remember { MutableInteractionSource() }
    val scale = pressScale(interaction, enabled)

    Box(
        modifier = modifier
            .height(dim.touchTarget)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
            .alpha(if (enabled) 1f else 0.45f)
            .background(CustomerColors.Brand100, RoundedCornerShape(dim.radiusMd))
            .clickable(enabled = enabled, interactionSource = interaction, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = CustomerColors.Brand700, fontSize = T.product, fontWeight = FontWeight.SemiBold)
    }
}

@Composable
fun GhostButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    danger: Boolean = false,
) {
    val interaction = remember { MutableInteractionSource() }
    val scale = pressScale(interaction, enabled)
    val fg = if (danger) CustomerColors.Danger else CustomerColors.TextPrimary
    val stroke = if (danger) CustomerColors.Danger else CustomerColors.Divider

    Box(
        modifier = modifier
            .height(dim.touchTarget)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
            .alpha(if (enabled) 1f else 0.45f)
            .background(CustomerColors.Surface, RoundedCornerShape(dim.radiusMd))
            .border(1.dp, stroke, RoundedCornerShape(dim.radiusMd))
            .clickable(enabled = enabled, interactionSource = interaction, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Text(text, color = fg, fontSize = T.product, fontWeight = FontWeight.Medium)
    }
}

// ============================================================
// 容器（规范 §7.3）
// ============================================================

/**
 * 标准卡片：白底 + 1px 浅边框 + radius-lg。
 *
 * 规范 §4.3 —— 默认卡片**不用阴影**，靠边框分层。
 * 需要浮起来的场景传 `elevation = E.level1`。
 */
@Composable
fun CardBox(
    modifier: Modifier = Modifier,
    background: Color = CustomerColors.Surface,
    elevation: Dp? = null,
    bordered: Boolean = true,
    content: @Composable () -> Unit,
) {
    val shape = RoundedCornerShape(dim.radiusLg)
    val base = if (elevation != null) {
        modifier.fillMaxWidth().brandShadow(elevation, shape)
    } else {
        modifier.fillMaxWidth()
    }

    Box(
        modifier = base
            .background(background, shape)
            .then(if (bordered) Modifier.border(1.dp, CustomerColors.Divider, shape) else Modifier),
    ) {
        content()
    }
}

/** 深绿主推卡（规范 §7.3 card--featured） */
@Composable
fun FeaturedCard(
    modifier: Modifier = Modifier,
    minHeight: Dp = 126.dp,
    content: @Composable () -> Unit,
) {
    Box(
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = minHeight)
            .background(CustomerColors.Brand700, RoundedCornerShape(dim.radiusXl)),
    ) {
        content()
    }
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier, trailing: (@Composable () -> Unit)? = null) {
    Row(
        modifier = modifier.fillMaxWidth().padding(bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(text, fontSize = T.section, fontWeight = FontWeight.SemiBold, color = CustomerColors.TextPrimary)
        trailing?.invoke()
    }
}

/** 提示条（规范 §7.5） */
@Composable
fun NoticeBar(text: String, tone: ChipTone = ChipTone.Warning, modifier: Modifier = Modifier) {
    val (fg, bg) = chipColors(tone)

    Box(
        modifier = modifier
            .fillMaxWidth()
            .background(bg, RoundedCornerShape(dim.radiusSm))
            .padding(horizontal = 12.dp, vertical = 8.dp),
    ) {
        Text(text, color = fg, fontSize = T.caption, lineHeight = 18.sp)
    }
}

@Composable
fun DividerLine(modifier: Modifier = Modifier) {
    Box(modifier.fillMaxWidth().height(1.dp).background(CustomerColors.Divider))
}

@Composable
fun ScreenTitle(text: String, modifier: Modifier = Modifier) {
    Text(
        text,
        modifier = modifier,
        fontSize = T.title,
        fontWeight = FontWeight.Bold,
        color = CustomerColors.Brand700,
    )
}

@Composable
fun EllipsisText(
    text: String,
    modifier: Modifier = Modifier,
    fontSize: TextUnit = T.body,
    color: Color = CustomerColors.TextPrimary,
    weight: FontWeight = FontWeight.Normal,
) {
    Text(
        text,
        modifier = modifier,
        fontSize = fontSize,
        color = color,
        fontWeight = weight,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
    )
}

/** 键值行：左侧标签、右侧数值（金额右对齐、等宽） */
@Composable
fun KeyValueRow(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    valueColor: Color = CustomerColors.TextPrimary,
    emphasize: Boolean = false,
) {
    Row(
        modifier = modifier.fillMaxWidth().padding(vertical = 3.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.Top,
    ) {
        Text(label, fontSize = T.caption, color = CustomerColors.TextSecondary)
        Spacer(Modifier.width(16.dp))
        Text(
            value,
            textAlign = TextAlign.End,
            style = tabularStyle(
                size = if (emphasize) T.body else T.caption,
                weight = if (emphasize) FontWeight.SemiBold else FontWeight.Normal,
                color = valueColor,
            ),
            modifier = Modifier.weight(1f, fill = false),
        )
    }
}

// ============================================================
// 骨架屏（规范 §1 原则 7）
// ============================================================

/**
 * 骨架块。
 *
 * 【为什么不用居中转圈】
 * 转圈只告诉用户「在等」，骨架还告诉用户「等的是什么」——
 * 商品列表的骨架长得像商品卡，加载完不会跳版，视觉上也没有「空白期」。
 * 生鲜顾客多数在弱网下打开，这个差别很明显。
 *
 * 呼吸式明暗（0.55 → 1.0，900ms 往复）而不是流光扫过：
 * 流光需要渐变，在低端安卓机上会掉帧。
 */
@Composable
fun SkeletonBox(modifier: Modifier = Modifier, shape: Shape? = null) {
    val resolved = shape ?: RoundedCornerShape(dim.radiusSm)
    val transition = rememberInfiniteTransition(label = "skeleton")
    val pulse by transition.animateFloat(
        initialValue = 0.55f,
        targetValue = 1f,
        animationSpec = infiniteRepeatable(
            animation = tween(durationMillis = 900, easing = LinearEasing),
            repeatMode = RepeatMode.Reverse,
        ),
        label = "skeletonPulse",
    )

    Box(modifier.alpha(pulse).background(CustomerColors.Neutral100, resolved))
}

/** 商品双列网格骨架：形状与真商品卡一致，加载完不跳版 */
@Composable
fun GoodsGridSkeleton(rows: Int = 3, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(dim.gap)) {
        repeat(rows) {
            Row(horizontalArrangement = Arrangement.spacedBy(dim.gap)) {
                repeat(2) {
                    GoodsCardSkeleton(Modifier.weight(1f))
                }
            }
        }
    }
}

@Composable
private fun GoodsCardSkeleton(modifier: Modifier = Modifier) {
    Column(
        modifier
            .fillMaxWidth()
            .background(CustomerColors.Surface, RoundedCornerShape(dim.radiusLg))
            .padding(8.dp),
    ) {
        SkeletonBox(Modifier.fillMaxWidth().aspectRatio(1f), RoundedCornerShape(dim.radiusMd))
        Spacer(Modifier.height(8.dp))
        SkeletonBox(Modifier.fillMaxWidth().height(12.dp))
        Spacer(Modifier.height(6.dp))
        SkeletonBox(Modifier.fillMaxWidth(0.6f).height(11.dp))
        Spacer(Modifier.height(10.dp))
        SkeletonBox(Modifier.fillMaxWidth(0.45f).height(16.dp))
    }
}

/** 通用列表骨架：用于订单、地址、流水这类「一行一条」的列表 */
@Composable
fun ListSkeleton(rows: Int = 4, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(dim.gap)) {
        repeat(rows) {
            Column(
                Modifier
                    .fillMaxWidth()
                    .background(CustomerColors.Surface, RoundedCornerShape(dim.radiusLg))
                    .border(1.dp, CustomerColors.Divider, RoundedCornerShape(dim.radiusLg))
                    .padding(dim.cardPadding),
            ) {
                SkeletonBox(Modifier.fillMaxWidth(0.4f).height(11.dp))
                Spacer(Modifier.height(10.dp))
                SkeletonBox(Modifier.fillMaxWidth().height(14.dp))
                Spacer(Modifier.height(6.dp))
                SkeletonBox(Modifier.fillMaxWidth(0.7f).height(11.dp))
                Spacer(Modifier.height(12.dp))
                SkeletonBox(Modifier.fillMaxWidth(0.35f).height(18.dp))
            }
        }
    }
}
