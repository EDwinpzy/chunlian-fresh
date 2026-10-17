package com.chunlian.merchant.core.media

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.net.Uri
import android.webkit.MimeTypeMap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.IOException

/**
 * 服务端 uploads 接口（getimagesize 校验）只接受这四种格式。
 * 除此之外的相册格式（新安卓机型默认的 HEIC/AVIF 等）必须转码成 JPEG，
 * 否则上传必然被服务端以「仅支持 JPG / PNG / GIF / WebP 格式」拒绝。
 */
private val SERVER_SAFE_MIME_TYPES = setOf("image/jpeg", "image/png", "image/gif", "image/webp")

/** 解码结果的最长边上限，控制内存峰值，也保证输出不会大到压不完。 */
private const val JPEG_MAX_DIMEN_PX = 4096

/** 逐级降质量；最低档仍超限则缩边后回到最高质量重来。 */
private val JPEG_QUALITY_STEPS = intArrayOf(90, 82, 72, 60)

/** 相邻两档质量都压不下去时视为「缩不动」，避免死循环。 */
private const val SCALE_FACTOR_NUMERATOR = 7
private const val SCALE_FACTOR_DENOMINATOR = 10

/**
 * Copies a selected gallery image into app cache before an upload request.
 *
 * 【为什么不是无脑复制原图】2026-10-17 店铺资料保存失败排查结论：
 * 相册在新机型上默认 HEIC/AVIF，原样上传会被服务端拒绝（gzip 前响应
 * 106 字节的「仅支持 JPG/PNG/GIF/WebP」信封）；且部分机型原图轻易超过
 * PHP 的 upload_max_filesize。因此除服务端明确支持的四种格式外一律
 * 解码重编码为 JPEG，解码结果超限时降质量并缩边，保证产出的文件
 * 既被服务端认识、又不超过上传上限。
 */
suspend fun stagePickedImage(
    context: Context,
    uri: Uri,
    filePrefix: String,
    maxBytes: Long = 5L * 1024 * 1024,
): File = withContext(Dispatchers.IO) {
    // 部分文档提供方返回 null MIME；按不安全格式处理走转码路径最稳
    val mime = context.contentResolver.getType(uri)?.lowercase()
    if (mime != null && mime in SERVER_SAFE_MIME_TYPES) {
        val extension = MimeTypeMap.getSingleton().getExtensionFromMimeType(mime)
            ?.filter { it.isLetterOrDigit() }?.takeIf { it.isNotBlank() } ?: "jpg"
        copyOriginal(context, uri, filePrefix, extension, maxBytes)
    } else {
        transcodeToJpeg(context, uri, filePrefix, maxBytes)
    }
}

/** GIF 是动图、PNG 可能带透明通道，服务端认识它们就原样保留，不做有损转码。 */
private suspend fun copyOriginal(
    context: Context,
    uri: Uri,
    filePrefix: String,
    extension: String,
    maxBytes: Long,
): File = withContext(Dispatchers.IO) {
    val target = File(context.cacheDir, "$filePrefix-${System.currentTimeMillis()}.$extension")
    try {
        val input = context.contentResolver.openInputStream(uri)
            ?: throw IOException("无法读取所选图片")
        input.use { source -> target.outputStream().use { output -> source.copyTo(output) } }
        if (target.length() == 0L) throw IOException("所选图片为空")
        if (target.length() > maxBytes) {
            throw IOException("图片不能超过 ${maxBytes / (1024 * 1024)} MB，请压缩后再选")
        }
        target
    } catch (e: Exception) {
        target.delete()
        throw e
    }
}

/** 解码任意格式图片并重编码为服务端可接受的 JPEG；文件大小通过降质量 + 缩边收敛。 */
private suspend fun transcodeToJpeg(
    context: Context,
    uri: Uri,
    filePrefix: String,
    maxBytes: Long,
): File = withContext(Dispatchers.IO) {
    val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
    context.contentResolver.openInputStream(uri)?.use {
        BitmapFactory.decodeStream(it, null, bounds)
    } ?: throw IOException("无法读取所选图片")
    if (bounds.outWidth <= 0 || bounds.outHeight <= 0) {
        // 首图即不可解码：多半是服务端不认识且设备也解不动的坏图
        throw IOException("所选图片不是可识别的格式，请改用 JPG 或 PNG 图片")
    }

    var inSampleSize = 1
    while (bounds.outWidth / (inSampleSize * 2) >= JPEG_MAX_DIMEN_PX &&
        bounds.outHeight / (inSampleSize * 2) >= JPEG_MAX_DIMEN_PX
    ) {
        inSampleSize *= 2
    }

    val decoded = context.contentResolver.openInputStream(uri)?.use {
        BitmapFactory.decodeStream(it, null, BitmapFactory.Options().apply { this.inSampleSize = inSampleSize })
    } ?: throw IOException("无法读取所选图片")

    val target = File(context.cacheDir, "$filePrefix-${System.currentTimeMillis()}.jpg")
    var current: Bitmap = decoded
    try {
        var qualityIndex = 0
        while (true) {
            val bytes = ByteArrayOutputStream().also { buffer ->
                current.compress(Bitmap.CompressFormat.JPEG, JPEG_QUALITY_STEPS[qualityIndex], buffer)
            }.toByteArray()

            if (bytes.size <= maxBytes) {
                target.outputStream().use { it.write(bytes) }
                break
            }
            if (qualityIndex < JPEG_QUALITY_STEPS.lastIndex) {
                qualityIndex++
                continue
            }

            // 最低质量仍超限：缩边后从最高质量重试；缩无可缩则放弃
            val nextWidth = (current.width * SCALE_FACTOR_NUMERATOR / SCALE_FACTOR_DENOMINATOR)
            val nextHeight = (current.height * SCALE_FACTOR_NUMERATOR / SCALE_FACTOR_DENOMINATOR)
            if (nextWidth < 1 || nextHeight < 1 || (nextWidth == current.width && nextHeight == current.height)) {
                throw IOException("图片压缩后仍然过大，请换一张较小的图片")
            }
            val scaled = Bitmap.createScaledBitmap(current, nextWidth, nextHeight, true)
            if (scaled !== current) current.recycle()
            current = scaled
            qualityIndex = 0
        }
    } catch (e: Exception) {
        target.delete()
        throw e
    } finally {
        current.recycle()
    }

    if (target.length() == 0L) {
        target.delete()
        throw IOException("所选图片无法读取，请换一张图片")
    }
    target
}

/** Copies a bundled preset image into cache so it can use the same upload path as a gallery image. */
suspend fun stageBundledImage(
    context: Context,
    assetPath: String,
    filePrefix: String,
    maxBytes: Long = 5L * 1024 * 1024,
): File = withContext(Dispatchers.IO) {
    require(isSafeBundledImageAssetPath(assetPath)) { "预设图片路径无效" }
    val extension = assetPath.substringAfterLast('.', "jpg")
        .filter { it.isLetterOrDigit() }
        .ifBlank { "jpg" }
    val target = File.createTempFile("$filePrefix-", ".$extension", context.cacheDir)
    try {
        context.assets.open(assetPath).use { input ->
            target.outputStream().use { output ->
                val buffer = ByteArray(DEFAULT_BUFFER_SIZE)
                var copied = 0L
                while (true) {
                    val read = input.read(buffer)
                    if (read < 0) break
                    copied += read
                    if (copied > maxBytes) {
                        throw IOException("图片不能超过 ${maxBytes / (1024 * 1024)} MB")
                    }
                    output.write(buffer, 0, read)
                }
            }
        }
        if (target.length() == 0L) throw IOException("预设图片为空")
        target
    } catch (e: Exception) {
        target.delete()
        throw e
    }
}

private fun isSafeBundledImageAssetPath(path: String): Boolean =
    path.matches(Regex("[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*")) &&
        path.split('/').none { it == "." || it == ".." }
