package com.chunlian.customer.ui

import android.content.Context
import android.graphics.BitmapFactory
import androidx.annotation.DrawableRes
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

private data class VisibleBounds(
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

private val boundsCache = mutableMapOf<Int, VisibleBounds?>()

private fun visibleBounds(context: Context, @DrawableRes resourceId: Int): VisibleBounds? =
    synchronized(boundsCache) {
        if (boundsCache.containsKey(resourceId)) return@synchronized boundsCache[resourceId]

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
                    VisibleBounds(
                        centerXFraction = (minX + maxXExclusive) / 2f / width,
                        centerYFraction = (minY + maxYExclusive) / 2f / height,
                        maxSideFraction = maxOf(
                            (maxXExclusive - minX) / width.toFloat(),
                            (maxYExclusive - minY) / height.toFloat(),
                        ),
                    )
                }
            } finally {
                image.recycle()
            }
        }
        boundsCache[resourceId] = bounds
        bounds
    }

/** Sizes the visible artwork consistently and centers it inside the requested slot. */
@Composable
fun CustomerAssetIcon(
    @DrawableRes resourceId: Int,
    contentDescription: String?,
    modifier: Modifier = Modifier,
    size: Dp = 24.dp,
    alpha: Float = 1f,
) {
    val context = LocalContext.current
    val bounds = remember(resourceId) { visibleBounds(context, resourceId) }
    val targetVisibleFraction = 0.82f
    val scale = bounds?.let { targetVisibleFraction / it.maxSideFraction } ?: 1f

    Box(modifier = modifier.size(size).clipToBounds()) {
        Image(
            painter = painterResource(resourceId),
            contentDescription = contentDescription,
            contentScale = ContentScale.Fit,
            modifier = Modifier.fillMaxSize().graphicsLayer {
                this.alpha = alpha
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
