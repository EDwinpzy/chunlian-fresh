package com.chunlian.merchant.ui.components

import android.content.Context
import android.graphics.BitmapFactory
import androidx.annotation.DrawableRes
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.Image
import androidx.compose.ui.res.painterResource
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsPressedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Shape
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.chunlian.merchant.core.designsystem.C
import com.chunlian.merchant.core.designsystem.E
import com.chunlian.merchant.core.designsystem.Motion
import com.chunlian.merchant.core.designsystem.T
import com.chunlian.merchant.core.designsystem.TABULAR_FIGURES
import com.chunlian.merchant.core.designsystem.brandShadow
import com.chunlian.merchant.core.designsystem.dim
import com.chunlian.merchant.R

private data class VisibleAssetBounds(
    val sourceWidth: Int,
    val sourceHeight: Int,
    val centerXFraction: Float,
    val centerYFraction: Float,
    val maxSideFraction: Float,
)

private data class AlphaComponent(
    val area: Int,
    val minX: Int,
    val minY: Int,
    val maxXExclusive: Int,
    val maxYExclusive: Int,
)

private val visibleAssetBoundsCache = mutableMapOf<Int, VisibleAssetBounds?>()

/** Read the visible alpha bounds once so artboard padding does not make icons look shifted or tiny. */
private fun visibleAssetBounds(context: Context, @DrawableRes resourceId: Int): VisibleAssetBounds? =
    synchronized(visibleAssetBoundsCache) {
        if (visibleAssetBoundsCache.containsKey(resourceId)) {
            return@synchronized visibleAssetBoundsCache[resourceId]
        }

        val options = BitmapFactory.Options().apply { inScaled = false }
        val bitmap = BitmapFactory.decodeResource(context.resources, resourceId, options)
        val bounds = bitmap?.let { image ->
            try {
                val width = image.width
                val height = image.height
                val pixels = IntArray(width * height)
                image.getPixels(pixels, 0, width, 0, 0, width, height)
                val visited = BooleanArray(pixels.size)
                val queue = IntArray(pixels.size)
                val components = mutableListOf<AlphaComponent>()

                pixels.indices.forEach { start ->
                    if (visited[start] || android.graphics.Color.alpha(pixels[start]) <= 16) return@forEach
                    var head = 0
                    var tail = 0
                    var area = 0
                    var minX = width
                    var minY = height
                    var maxXExclusive = 0
                    var maxYExclusive = 0
                    queue[tail++] = start
                    visited[start] = true

                    while (head < tail) {
                        val index = queue[head++]
                        val x = index % width
                        val y = index / width
                        area += 1
                        minX = minOf(minX, x)
                        minY = minOf(minY, y)
                        maxXExclusive = maxOf(maxXExclusive, x + 1)
                        maxYExclusive = maxOf(maxYExclusive, y + 1)

                        for (neighborY in maxOf(0, y - 1)..minOf(height - 1, y + 1)) {
                            for (neighborX in maxOf(0, x - 1)..minOf(width - 1, x + 1)) {
                                val neighbor = neighborY * width + neighborX
                                if (!visited[neighbor] && android.graphics.Color.alpha(pixels[neighbor]) > 16) {
                                    visited[neighbor] = true
                                    queue[tail++] = neighbor
                                }
                            }
                        }
                    }
                    components += AlphaComponent(area, minX, minY, maxXExclusive, maxYExclusive)
                }

                val largest = components.maxByOrNull { it.area }
                if (largest == null) {
                    null
                } else {
                    val noiseLimit = maxOf(15, (largest.area * 0.025f).toInt())
                    fun distanceFromLargest(component: AlphaComponent): Int = maxOf(
                        0,
                        largest.minX - component.maxXExclusive,
                        component.minX - largest.maxXExclusive,
                        largest.minY - component.maxYExclusive,
                        component.minY - largest.maxYExclusive,
                    )
                    val visible = components.filter { component ->
                        component.area > noiseLimit || distanceFromLargest(component) <= 2
                    }
                    val minX = visible.minOf { it.minX }
                    val minY = visible.minOf { it.minY }
                    val maxXExclusive = visible.maxOf { it.maxXExclusive }
                    val maxYExclusive = visible.maxOf { it.maxYExclusive }
                    val visibleWidth = maxXExclusive - minX
                    val visibleHeight = maxYExclusive - minY
                    VisibleAssetBounds(
                        sourceWidth = width,
                        sourceHeight = height,
                        centerXFraction = (minX + maxXExclusive) / 2f / width,
                        centerYFraction = (minY + maxYExclusive) / 2f / height,
                        maxSideFraction = maxOf(
                            visibleWidth / image.width.toFloat(),
                            visibleHeight / image.height.toFloat(),
                        ),
                    )
                }
            } finally {
                image.recycle()
            }
        }
        visibleAssetBoundsCache[resourceId] = bounds
        bounds
    }

// ============================================================
// 金额
// ============================================================

/**
 * 整数分 → 「3.50」。
 *
 * 【为什么不用 `fen / 100.0`】
 * 浮点除法在金额上会产生 0.1 + 0.2 = 0.30000000000000004 这类误差，
 * 累加几十笔订单后就会出现「合计对不上」的投诉。
 * 这里全程整数运算，只在最后拼字符串。
 */
fun fen2yuan(fen: Long): String {
    val negative = fen < 0
    val abs = if (negative) -fen else fen
    val text = "${abs / 100}.${(abs % 100).toString().padStart(2, '0')}"

    return if (negative) "-$text" else text
}

/** 「¥3.50」 */
fun money(fen: Long): String = "¥${fen2yuan(fen)}"

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

    val value = cleaned.toDoubleOrNull() ?: return 0

    return Math.round(value * 100)
}

// ============================================================
// 状态
// ============================================================

enum class ChipTone { Success, Warning, Danger, Info, Muted }

fun chipColors(tone: ChipTone): Pair<Color, Color> = when (tone) {
    ChipTone.Success -> C.Success to C.SuccessBg
    ChipTone.Warning -> C.Warning to C.WarningBg
    ChipTone.Danger -> C.Danger to C.DangerBg
    ChipTone.Info -> C.Brand600 to C.InfoBg
    ChipTone.Muted -> C.TextSecondary to C.Canvas
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

/** 统一订单状态的展示映射 */
fun orderStatusTone(status: String): ChipTone = when (status) {
    "PENDING" -> ChipTone.Info
    "DELIVERING" -> ChipTone.Warning
    "DELIVERED" -> ChipTone.Success
    "FINISHED" -> ChipTone.Muted
    else -> ChipTone.Muted
}

fun orderStatusLabel(status: String, statusText: String = ""): String = statusText.ifBlank {
    when (status) {
        "PENDING" -> "待配送"
        "DELIVERING" -> "配送中"
        "DELIVERED" -> "已送达"
        "FINISHED" -> "已完成"
        "CANCELLED" -> "已取消"
        else -> status
    }
}

fun paymentTone(paymentState: String): ChipTone = when (paymentState) {
    "PAID" -> ChipTone.Success
    "UNPAID" -> ChipTone.Warning
    "PAYING", "REFUNDING" -> ChipTone.Warning
    "REFUNDED", "PARTIAL_REFUNDED" -> ChipTone.Danger
    else -> ChipTone.Muted
}

fun paymentText(paymentState: String): String = when (paymentState) {
    "UNPAID" -> "未付款"
    "PAYING" -> "支付中"
    "PAID" -> "已付款"
    "PARTIAL_REFUNDED" -> "部分退款"
    "REFUNDING" -> "退款处理中"
    "REFUNDED" -> "已退款"
    else -> paymentState
}

fun paymentMethodText(method: String): String = when (method) {
    "COD" -> "未收款"
    "OFFLINE_QR" -> "扫码预付（线下核验）"
    else -> "微信支付"
}

/** 期望送达时间文案（后端给的是 ISO8601） */
fun deliveryText(mode: String, expectedAt: String?): String {
    if (mode != "SCHEDULED" || expectedAt.isNullOrBlank()) return "尽快配送"

    // 只取「MM-dd HH:mm」，不引入日期库 —— 后端格式固定为 ISO8601
    val normalized = expectedAt.replace('T', ' ').take(16)
    return if (normalized.length >= 16) "预约 ${normalized.substring(5)}" else "预约送达"
}

// ============================================================
// 布局零件
// ============================================================

/** 显示 merchant_<name> 品牌图标素材。颜色由素材本身提供，因此不对图标着色。 */
@Composable
fun MerchantAssetIcon(
    @DrawableRes resourceId: Int,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    size: androidx.compose.ui.unit.Dp = 24.dp,
) {
    val context = LocalContext.current
    val bounds = androidx.compose.runtime.remember(resourceId) {
        visibleAssetBounds(context, resourceId)
    }
    val targetVisibleFraction = 0.82f
    val scale = bounds?.let { targetVisibleFraction / it.maxSideFraction } ?: 1f
    Box(modifier = modifier.size(size).clipToBounds()) {
        Image(
            painter = painterResource(resourceId),
            contentDescription = contentDescription,
            modifier = Modifier.fillMaxSize().graphicsLayer {
                if (bounds != null) {
                    scaleX = scale
                    scaleY = scale
                    translationX = (0.5f - bounds.centerXFraction) * this.size.width * scale
                    translationY = (0.5f - bounds.centerYFraction) * this.size.height * scale
                }
            },
        )
    }
}

/** Plain, brand-colored back arrow for screen headers. */
@Composable
fun MerchantBackIcon(size: androidx.compose.ui.unit.Dp = 24.dp) {
    Icon(
        imageVector = Icons.AutoMirrored.Filled.ArrowBack,
        contentDescription = "返回",
        modifier = Modifier.size(size),
        tint = C.Brand700,
    )
}

/** Shared fixed-height title area for merchant screens. */
@Composable
fun MerchantTopBar(
    modifier: Modifier = Modifier,
    horizontalArrangement: Arrangement.Horizontal = Arrangement.Start,
    content: @Composable RowScope.() -> Unit,
) {
    Row(
        modifier = modifier.fillMaxWidth().height(56.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = horizontalArrangement,
        content = content,
    )
}

@Composable
fun SectionTitle(text: String, modifier: Modifier = Modifier, trailing: (@Composable () -> Unit)? = null) {
    Row(
        modifier = modifier.fillMaxWidth().padding(bottom = 12.dp),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.SpaceBetween,
    ) {
        Text(text = text, fontSize = T.section, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
        trailing?.invoke()
    }
}

/**
 * 标准卡片（规范 §7.3）。
 *
 * 默认白底 + 1px 浅边框 + radius-lg，**不用阴影** ——
 * 规范 §4.3 把阴影当稀缺资源，只留给需要浮起来的元素。
 * 需要浮起来时传 `elevation = E.level1`。
 */
@Composable
fun CardBox(
    modifier: Modifier = Modifier,
    background: Color = C.Surface,
    elevation: androidx.compose.ui.unit.Dp? = null,
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
            .then(if (bordered) Modifier.border(1.dp, C.Divider, shape) else Modifier),
    ) {
        content()
    }
}

/** 深绿主推卡（规范 §7.3 card--featured）—— 营收卡、今日重点数字 */
@Composable
fun FeaturedCard(
    modifier: Modifier = Modifier,
    minHeight: androidx.compose.ui.unit.Dp = 128.dp,
    content: @Composable () -> Unit,
) {
    Box(
        modifier = modifier
            .fillMaxWidth()
            .heightIn(min = minHeight)
            .background(C.Brand700, RoundedCornerShape(dim.radiusXl)),
    ) {
        content()
    }
}

/**
 * 快捷入口（规范 §7.3 九宫格）。
 *
 * 图标底座用 brand-100 而不是主色实心 —— 工作台上一屏会出现 4~8 个入口，
 * 全部实心深绿会让视线无处落脚，找不到真正的主行动。
 */
@Composable
fun QuickAction(
    label: String,
    @DrawableRes iconRes: Int,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    badge: Int = 0,
) {
    val interaction = remember { MutableInteractionSource() }
    val scale = pressScale(interaction, true)

    Column(
        modifier = modifier
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
            .background(C.Surface, RoundedCornerShape(dim.radiusLg))
            .border(1.dp, C.Divider, RoundedCornerShape(dim.radiusLg))
            .clickable(interactionSource = interaction, indication = null, onClick = onClick)
            .padding(vertical = 12.dp, horizontal = 4.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Box(contentAlignment = Alignment.TopEnd) {
            Box(
                modifier = Modifier
                    .size(32.dp)
                    .background(C.Brand100, RoundedCornerShape(dim.radiusSm)),
                contentAlignment = Alignment.Center,
            ) {
                MerchantAssetIcon(iconRes, contentDescription = null, size = 18.dp)
            }
            if (badge > 0) {
                Box(
                    modifier = Modifier
                        .size(8.dp)
                        .background(C.Danger, RoundedCornerShape(dim.radiusPill)),
                )
            }
        }
        Text(label, fontSize = T.mini, color = C.TextSecondary, maxLines = 1)
    }
}

// ============================================================
// 价格与等宽数字（规范 §3.3 / §7.7）
// ============================================================

/** 等宽数字样式：金额、数量、日期专用 */
fun tabularStyle(
    size: TextUnit,
    weight: FontWeight = FontWeight.Normal,
    color: Color = C.TextPrimary,
): TextStyle = TextStyle(
    fontSize = size,
    fontWeight = weight,
    color = color,
    fontFeatureSettings = TABULAR_FIGURES,
)

/**
 * 价格文本。
 *
 * 价格用 [C.Price] 而不是 [C.Danger] ——
 * 前者更暖，读起来是「标价」而不是「报错」。
 */
@Composable
fun PriceText(
    fen: Long,
    modifier: Modifier = Modifier,
    size: TextUnit = T.headline,
    unit: String? = null,
    color: Color = C.Price,
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
            Text(unit, color = C.TextMuted, fontSize = T.mini, modifier = Modifier.padding(start = 2.dp, bottom = 2.dp))
        }
    }
}

/** 划线原价 */
@Composable
fun StrikethroughPrice(fen: Long, modifier: Modifier = Modifier) {
    Text(
        "¥${fen2yuan(fen)}",
        modifier = modifier,
        style = TextStyle(
            fontSize = T.mini,
            color = C.TextMuted,
            textDecoration = TextDecoration.LineThrough,
        ),
    )
}

/**
 * 指标卡（方案 §6.4 MMetricCard）。
 *
 * 深绿底色用于「今日实收」这类重点数字；普通指标用白底 + 浅边框。
 * 方案 §6.2 要求营收卡约 128dp、四宫格每卡不低于 92dp。
 */
@Composable
fun MetricCard(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    hint: String? = null,
    highlight: Boolean = false,
    alert: Boolean = false,
    onClick: (() -> Unit)? = null,
) {
    val bg = when {
        highlight -> C.Brand700
        else -> C.Surface
    }
    val labelColor = if (highlight) C.OnBrandMuted else C.TextSecondary
    val valueColor = when {
        highlight -> C.OnBrand
        alert -> C.Warning
        else -> C.Brand700
    }

    val clickable = if (onClick != null) Modifier.clickable { onClick() } else Modifier

    Box(
        modifier = modifier
            .heightIn(min = dim.metricCardMinHeight)
            .background(bg, RoundedCornerShape(dim.radiusLg))
            .then(if (highlight) Modifier else Modifier.border(1.dp, C.Divider, RoundedCornerShape(dim.radiusLg)))
            .then(clickable)
            .padding(16.dp),
    ) {
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(label, color = labelColor, fontSize = T.caption)
            Text(
                value,
                color = valueColor,
                fontSize = if (highlight) T.display else 22.sp,
                fontWeight = FontWeight.Bold,
            )
            if (hint != null) {
                Text(hint, color = labelColor, fontSize = T.mini)
            }
        }
    }
}

/** 键值行 */
@Composable
fun KeyValueRow(
    label: String,
    value: String,
    modifier: Modifier = Modifier,
    valueColor: Color = C.TextPrimary,
    emphasize: Boolean = false,
) {
    Row(
        modifier = modifier.fillMaxWidth().padding(vertical = 3.dp),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.Top,
    ) {
        Text(label, fontSize = T.caption, color = C.TextSecondary)
        Spacer(Modifier.width(16.dp))
        Text(
            value,
            fontSize = if (emphasize) T.body else T.caption,
            color = valueColor,
            fontWeight = if (emphasize) FontWeight.SemiBold else FontWeight.Normal,
            textAlign = TextAlign.End,
            modifier = Modifier.weight(1f, fill = false),
        )
    }
}

/** 空态 / 错误态（方案 §6.4 MEmptyState） */
@Composable
fun EmptyBox(
    title: String,
    description: String? = null,
    actionText: String? = null,
    onAction: (() -> Unit)? = null,
    actionModifier: Modifier = Modifier,
    tone: ChipTone = ChipTone.Info,
) {
    Column(
        modifier = Modifier.fillMaxWidth().padding(vertical = 48.dp, horizontal = 24.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(8.dp),
    ) {
        Text(title, fontSize = T.product, fontWeight = FontWeight.SemiBold, color = C.TextPrimary)
        if (description != null) {
            Text(
                description,
                fontSize = T.caption,
                color = C.TextSecondary,
                textAlign = TextAlign.Center,
            )
        }
        if (actionText != null && onAction != null) {
            Spacer(Modifier.height(8.dp))
            PrimaryButton(text = actionText, onClick = onAction, modifier = actionModifier)
        }
        if (tone == ChipTone.Danger) {
            Text("（错误）", fontSize = T.mini, color = C.Danger)
        }
    }
}

@Composable
fun LoadingBox(modifier: Modifier = Modifier) {
    Box(modifier = modifier.fillMaxWidth().padding(vertical = 48.dp), contentAlignment = Alignment.Center) {
        CircularProgressIndicator(color = C.Brand600, strokeWidth = 2.dp, modifier = Modifier.size(28.dp))
    }
}

/** 主按钮（规范 §7.1 primary） */
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
            .background(C.Brand700, RoundedCornerShape(dim.radiusMd))
            .clickable(
                enabled = active,
                interactionSource = interaction,
                indication = null,
                onClick = onClick,
            ),
        contentAlignment = Alignment.Center,
    ) {
        if (loading) {
            CircularProgressIndicator(color = C.OnBrand, strokeWidth = 2.dp, modifier = Modifier.size(20.dp))
        } else {
            Text(text, color = C.OnBrand, fontSize = T.product, fontWeight = FontWeight.SemiBold)
        }
    }
}

/** 次级按钮（规范 §7.1 secondary） */
@Composable
fun SecondaryButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    @DrawableRes leadingIconRes: Int? = null,
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
            .background(C.Brand100, RoundedCornerShape(dim.radiusMd))
            .clickable(enabled = enabled, interactionSource = interaction, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (leadingIconRes == null) {
            Text(text, color = C.Brand700, fontSize = T.product, fontWeight = FontWeight.SemiBold)
        } else {
            Row(
                modifier = Modifier.padding(horizontal = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                MerchantAssetIcon(leadingIconRes, contentDescription = null, size = 20.dp)
                Text(text, color = C.Brand700, fontSize = T.product, fontWeight = FontWeight.SemiBold)
            }
        }
    }
}

/** 描边按钮（规范 §7.1 ghost / danger） */
@Composable
fun GhostButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    danger: Boolean = false,
    @DrawableRes leadingIconRes: Int? = null,
) {
    val interaction = remember { MutableInteractionSource() }
    val scale = pressScale(interaction, enabled)
    val fg = if (danger) C.Danger else C.TextPrimary
    val stroke = if (danger) C.Danger else C.Divider

    Box(
        modifier = modifier
            .height(dim.touchTarget)
            .graphicsLayer {
                scaleX = scale
                scaleY = scale
            }
            .alpha(if (enabled) 1f else 0.45f)
            .background(C.Surface, RoundedCornerShape(dim.radiusMd))
            .border(1.dp, stroke, RoundedCornerShape(dim.radiusMd))
            .clickable(enabled = enabled, interactionSource = interaction, indication = null, onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        if (leadingIconRes == null) {
            Text(text, color = fg, fontSize = T.product, fontWeight = FontWeight.Medium)
        } else {
            Row(
                modifier = Modifier.padding(horizontal = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(6.dp),
            ) {
                MerchantAssetIcon(
                    leadingIconRes,
                    contentDescription = null,
                    size = 20.dp,
                )
                Text(text, color = fg, fontSize = T.product, fontWeight = FontWeight.Medium)
            }
        }
    }
}

/** 提示条（方案 §6.4 MNoticeBar） */
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
    Box(modifier.fillMaxWidth().height(1.dp).background(C.Divider))
}

/** 长文本单行省略 */
@Composable
fun EllipsisText(text: String, fontSize: androidx.compose.ui.unit.TextUnit = T.body, color: Color = C.TextPrimary, modifier: Modifier = Modifier) {
    Text(text, fontSize = fontSize, color = color, maxLines = 1, overflow = TextOverflow.Ellipsis, modifier = modifier)
}

/** 统一给所有页面用的标题样式 */
@Composable
fun ScreenTitle(text: String, modifier: Modifier = Modifier) {
    Text(
        text,
        modifier = modifier,
        fontSize = MaterialTheme.typography.titleLarge.fontSize,
        fontWeight = FontWeight.Bold,
        color = C.Brand700,
    )
}

// ============================================================
// 骨架屏（规范 §1 原则 7）
// ============================================================

/**
 * 骨架块。
 *
 * 【为什么不用居中转圈】
 * 转圈只告诉店主「在等」，骨架还告诉店主「等的是什么」。
 * 店主在店里用手机看订单，经常是边走边刷，骨架能让他在数据到位前
 * 就看清这一屏大概有几条、每条多高，不会出现「点进去一片空白然后突然跳出来」。
 *
 * 呼吸式明暗（0.55 → 1.0，900ms 往复）而不是流光扫过：
 * 流光需要渐变，低端安卓机上会掉帧。
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

    Box(modifier.alpha(pulse).background(C.Neutral100, resolved))
}

/** 通用列表骨架：形状与 [OrderCard] 一致，加载完不跳版 */
@Composable
fun ListSkeleton(rows: Int = 4, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(dim.gap)) {
        repeat(rows) {
            Column(
                Modifier
                    .fillMaxWidth()
                    .background(C.Surface, RoundedCornerShape(dim.radiusLg))
                    .border(1.dp, C.Divider, RoundedCornerShape(dim.radiusLg))
                    .padding(dim.cardPadding),
            ) {
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    SkeletonBox(Modifier.width(56.dp).height(11.dp))
                    Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        SkeletonBox(Modifier.width(48.dp).height(16.dp))
                        SkeletonBox(Modifier.width(48.dp).height(16.dp))
                    }
                }
                Spacer(Modifier.height(10.dp))
                SkeletonBox(Modifier.fillMaxWidth(0.45f).height(14.dp))
                Spacer(Modifier.height(6.dp))
                SkeletonBox(Modifier.fillMaxWidth(0.8f).height(11.dp))
                Spacer(Modifier.height(12.dp))
                Row(
                    Modifier.fillMaxWidth(),
                    horizontalArrangement = Arrangement.SpaceBetween,
                    verticalAlignment = Alignment.CenterVertically,
                ) {
                    SkeletonBox(Modifier.width(72.dp).height(11.dp))
                    SkeletonBox(Modifier.width(64.dp).height(18.dp))
                }
            }
        }
    }
}

/** 商品列表骨架 */
@Composable
fun ProductListSkeleton(rows: Int = 5, modifier: Modifier = Modifier) {
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(dim.gap)) {
        repeat(rows) {
            Row(
                Modifier
                    .fillMaxWidth()
                    .background(C.Surface, RoundedCornerShape(dim.radiusLg))
                    .border(1.dp, C.Divider, RoundedCornerShape(dim.radiusLg))
                    .padding(dim.cardPadding),
                horizontalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                SkeletonBox(Modifier.size(64.dp), RoundedCornerShape(dim.radiusMd))
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(7.dp)) {
                    SkeletonBox(Modifier.fillMaxWidth(0.7f).height(14.dp))
                    SkeletonBox(Modifier.fillMaxWidth(0.45f).height(11.dp))
                    SkeletonBox(Modifier.fillMaxWidth(0.35f).height(16.dp))
                }
            }
        }
    }
}
