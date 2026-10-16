package com.chunlian.androidupdate

import android.content.Context
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import okhttp3.CacheControl
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrlOrNull
import java.io.BufferedInputStream
import java.io.BufferedOutputStream
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.IOException
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

data class AppUpdateState(
    val checking: Boolean = false,
    val downloading: Boolean = false,
    val progressPercent: Int? = null,
    val release: AppUpdateRelease? = null,
    val dialogVisible: Boolean = false,
    val statusMessage: String? = null,
    val downloadedFile: File? = null,
    val errorMessage: String? = null,
)

/** Shared, optional APK updater used by the customer and merchant apps. */
class AndroidAppUpdater(
    context: Context,
    private val applicationId: String,
    private val currentVersionCode: Long,
    baseUrl: String,
) {
    private val appContext = context.applicationContext
    private val serverBaseUrl = baseUrl.toHttpUrlOrNull()
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main.immediate)
    private val client = OkHttpClient.Builder()
        .connectTimeout(12, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .followRedirects(false)
        .followSslRedirects(false)
        .build()

    private val mutableState = MutableStateFlow(AppUpdateState())
    val state: StateFlow<AppUpdateState> = mutableState.asStateFlow()

    fun checkForUpdate(showResult: Boolean = false) {
        if (mutableState.value.checking || mutableState.value.downloading) return
        scope.launch {
            mutableState.update {
                it.copy(
                    checking = true,
                    statusMessage = if (showResult) "正在检查更新…" else it.statusMessage,
                    errorMessage = null,
                )
            }
            val result = withContext(Dispatchers.IO) {
                runCatching { fetchUpdate() }
            }
            mutableState.update { previous ->
                val update = result.getOrNull()
                previous.copy(
                    checking = false,
                    release = update,
                    dialogVisible = update != null,
                    statusMessage = when {
                        update != null -> "发现新版本 ${update.versionName}"
                        !showResult -> null
                        result.isFailure -> "检查更新失败，请检查网络后重试"
                        else -> "当前已是最新版本"
                    },
                    errorMessage = result.exceptionOrNull()?.message,
                    downloadedFile = null,
                    progressPercent = null,
                )
            }
        }
    }

    fun download() {
        val release = mutableState.value.release ?: return
        if (mutableState.value.downloading) return
        scope.launch {
            mutableState.update { it.copy(downloading = true, progressPercent = 0, errorMessage = null) }
            val result = withContext(Dispatchers.IO) { runCatching { downloadUpdate(release) } }
            mutableState.update { previous ->
                val file = result.getOrNull()
                previous.copy(
                    downloading = false,
                    progressPercent = if (file != null) 100 else null,
                    downloadedFile = file,
                    dialogVisible = previous.dialogVisible || file == null,
                    statusMessage = if (file != null) "更新包已下载，正在打开系统安装器" else previous.statusMessage,
                    errorMessage = result.exceptionOrNull()?.message,
                )
            }
        }
    }

    fun dismiss() {
        mutableState.update { it.copy(dialogVisible = false) }
    }

    fun clearStatus() {
        mutableState.update { it.copy(statusMessage = null, errorMessage = null) }
    }

    fun installPermissionDenied() {
        mutableState.update {
            it.copy(
                dialogVisible = true,
                statusMessage = "请允许本应用安装未知应用，然后点“继续安装”",
                errorMessage = "Android 系统要求先允许此来源安装应用",
            )
        }
    }

    fun installerFailed(message: String) {
        mutableState.update {
            it.copy(dialogVisible = true, statusMessage = message, errorMessage = message)
        }
    }

    fun installerLaunched() {
        mutableState.update {
            it.copy(
                dialogVisible = false,
                downloadedFile = null,
                statusMessage = "已打开系统安装器，请按提示完成更新",
                errorMessage = null,
            )
        }
    }

    private fun fetchUpdate(): AppUpdateRelease? {
        val base = serverBaseUrl ?: throw IOException("更新服务器地址无效")
        if (base.isHttps.not()) throw IOException("在线更新仅支持 HTTPS")
        val manifestUrl = base.newBuilder()
            .encodedPath("/downloads/android-release.json")
            .query(null)
            .fragment(null)
            .build()
        val request = Request.Builder()
            .url(manifestUrl)
            .get()
            .cacheControl(CacheControl.FORCE_NETWORK)
            .header("Accept", "application/json")
            .build()
        client.newCall(request).execute().use { response ->
            if (!response.isSuccessful) throw IOException("版本清单请求失败（${response.code}）")
            val body = response.body ?: throw IOException("版本清单为空")
            if (body.contentLength() > MAX_MANIFEST_BYTES) throw IOException("版本清单大小无效")
            val text = body.byteStream().use { input ->
                val output = ByteArrayOutputStream()
                val buffer = ByteArray(8 * 1024)
                var total = 0
                while (true) {
                    val count = input.read(buffer)
                    if (count < 0) break
                    total += count
                    if (total > MAX_MANIFEST_BYTES) throw IOException("版本清单大小无效")
                    output.write(buffer, 0, count)
                }
                output.toString(Charsets.UTF_8.name())
            }
            return AndroidReleaseManifest.findUpdate(text, applicationId, currentVersionCode)
        }
    }

    private fun downloadUpdate(release: AppUpdateRelease): File {
        val base = serverBaseUrl ?: throw IOException("更新服务器地址无效")
        if (!base.isHttps) throw IOException("在线更新仅支持 HTTPS")
        if (release.applicationId != applicationId) throw IOException("更新包不属于当前应用")
        val url = base.newBuilder()
            .encodedPath("/downloads/${release.file}")
            .query(null)
            .fragment(null)
            .build()
        val request = Request.Builder()
            .url(url)
            .get()
            .cacheControl(CacheControl.FORCE_NETWORK)
            .header("Accept", "application/vnd.android.package-archive")
            .build()
        val directory = File(appContext.cacheDir, "updates")
        if (!directory.exists() && !directory.mkdirs()) throw IOException("无法创建更新缓存目录")
        val temporary = File(directory, "${release.file}.part")
        val destination = File(directory, release.file)
        temporary.delete()
        try {
            client.newCall(request).execute().use { response ->
                if (!response.isSuccessful) throw IOException("更新包下载失败（${response.code}）")
                if (response.request.url.host != base.host || response.request.url.scheme != "https") {
                    throw IOException("更新包地址与版本服务器不一致")
                }
                val body = response.body ?: throw IOException("更新包内容为空")
                val contentLength = body.contentLength()
                if (contentLength > MAX_APK_BYTES) throw IOException("更新包超过允许大小")
                if (release.bytes > 0 && contentLength >= 0 && contentLength != release.bytes) {
                    throw IOException("更新包大小与版本清单不一致")
                }
                val digest = MessageDigest.getInstance("SHA-256")
                var downloaded = 0L
                var lastReported = 0L
                BufferedInputStream(body.byteStream()).use { input ->
                    BufferedOutputStream(temporary.outputStream()).use { output ->
                        val buffer = ByteArray(64 * 1024)
                        while (true) {
                            val count = input.read(buffer)
                            if (count < 0) break
                            downloaded += count
                            if (downloaded > MAX_APK_BYTES || (release.bytes > 0 && downloaded > release.bytes)) {
                                throw IOException("更新包大小超过版本清单")
                            }
                            digest.update(buffer, 0, count)
                            output.write(buffer, 0, count)
                            if (downloaded - lastReported >= PROGRESS_INTERVAL_BYTES) {
                                lastReported = downloaded
                                val total = release.bytes.takeIf { it > 0 } ?: contentLength
                                val percent = if (total > 0) ((downloaded * 100) / total).toInt().coerceIn(0, 99) else null
                                mutableState.update { it.copy(progressPercent = percent) }
                            }
                        }
                    }
                }
                if (release.bytes > 0 && downloaded != release.bytes) throw IOException("更新包未完整下载")
                val actualHash = digest.digest().joinToString("") { "%02x".format(it) }
                if (!actualHash.equals(release.sha256, ignoreCase = true)) throw IOException("更新包 SHA-256 校验失败")
            }
            if (destination.exists() && !destination.delete()) throw IOException("无法替换旧更新包")
            if (!temporary.renameTo(destination)) throw IOException("无法保存更新包")
            return destination
        } catch (error: Exception) {
            temporary.delete()
            throw error
        }
    }

    companion object {
        const val INSTALL_PERMISSION_MESSAGE = "Android 系统要求先允许此来源安装应用"
        private const val MAX_MANIFEST_BYTES = 1024L * 1024L
        private const val MAX_APK_BYTES = 200L * 1024L * 1024L
        private const val PROGRESS_INTERVAL_BYTES = 512L * 1024L
    }
}
