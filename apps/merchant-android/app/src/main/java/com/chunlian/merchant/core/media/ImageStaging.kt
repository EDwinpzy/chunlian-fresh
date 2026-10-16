package com.chunlian.merchant.core.media

import android.content.Context
import android.net.Uri
import android.webkit.MimeTypeMap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
import java.io.IOException

/** Copies a selected gallery image into app cache before an upload request. */
suspend fun stagePickedImage(
    context: Context,
    uri: Uri,
    filePrefix: String,
    maxBytes: Long = 5L * 1024 * 1024,
): File = withContext(Dispatchers.IO) {
    val extension = MimeTypeMap.getSingleton()
        .getExtensionFromMimeType(context.contentResolver.getType(uri))
        ?.filter { it.isLetterOrDigit() }
        ?.takeIf { it.isNotBlank() }
        ?: "jpg"
    val target = File(context.cacheDir, "$filePrefix-${System.currentTimeMillis()}.$extension")
    try {
        val input = context.contentResolver.openInputStream(uri)
            ?: throw IOException("无法读取所选图片")
        input.use { source -> target.outputStream().use { output -> source.copyTo(output) } }
        if (target.length() == 0L) throw IOException("所选图片为空")
        if (target.length() > maxBytes) {
            throw IOException("图片不能超过 ${maxBytes / (1024 * 1024)} MB")
        }
        target
    } catch (e: Exception) {
        target.delete()
        throw e
    }
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
