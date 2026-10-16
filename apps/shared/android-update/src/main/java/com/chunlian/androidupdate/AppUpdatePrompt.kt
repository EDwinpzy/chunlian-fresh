package com.chunlian.androidupdate

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.height
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.FileProvider
import java.io.File

/** App-wide optional update prompt. Installation is always confirmed by Android. */
@Composable
fun AppUpdatePrompt(updater: AndroidAppUpdater) {
    val context = LocalContext.current
    val state by updater.state.collectAsState()
    var attemptedFile by remember { mutableStateOf<String?>(null) }

    val installPermissionLauncher = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) {
        val file = updater.state.value.downloadedFile
        val allowed = Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()
        if (allowed && file != null) {
            openSystemInstaller(context, updater, file)
        } else if (!allowed) {
            updater.installPermissionDenied()
        }
    }

    fun install(file: File) {
        val allowed = Build.VERSION.SDK_INT < Build.VERSION_CODES.O || context.packageManager.canRequestPackageInstalls()
        if (!allowed) {
            val settingsIntent = Intent(
                Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                Uri.parse("package:${context.packageName}"),
            )
            installPermissionLauncher.launch(settingsIntent)
            return
        }
        openSystemInstaller(context, updater, file)
    }

    LaunchedEffect(updater) {
        updater.checkForUpdate(showResult = false)
    }

    LaunchedEffect(state.downloadedFile?.absolutePath) {
        val file = state.downloadedFile ?: return@LaunchedEffect
        if (attemptedFile != file.absolutePath) {
            attemptedFile = file.absolutePath
            install(file)
        }
    }

    if (!state.dialogVisible) return
    val release = state.release ?: return
    val progressPercent = state.progressPercent
    AlertDialog(
        onDismissRequest = { if (!state.downloading) updater.dismiss() },
        title = { Text("发现新版本 ${release.versionName}") },
        text = {
            androidx.compose.foundation.layout.Column {
                if (release.releaseNotes.isNotBlank()) Text(release.releaseNotes)
                else Text("下载安装包后，将由 Android 系统提示确认安装。")
                if (state.downloading) {
                    androidx.compose.foundation.layout.Spacer(androidx.compose.ui.Modifier.height(12.dp))
                    if (progressPercent != null) {
                        LinearProgressIndicator(progress = { progressPercent / 100f })
                    } else {
                        LinearProgressIndicator()
                    }
                    Text(progressPercent?.let { "正在下载 $it%" } ?: "正在下载…")
                }
                state.errorMessage?.let { Text(it) }
                if (state.downloadedFile != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.O &&
                    !context.packageManager.canRequestPackageInstalls()
                ) {
                    Text("请在系统设置中允许此来源安装应用，然后点“继续安装”。")
                }
            }
        },
        confirmButton = {
            TextButton(
                enabled = !state.downloading,
                onClick = {
                    val downloaded = state.downloadedFile
                    if (downloaded != null) install(downloaded) else updater.download()
                },
            ) {
                Text(if (state.downloadedFile != null) "继续安装" else if (state.downloading) "下载中…" else "下载并更新")
            }
        },
        dismissButton = {
            if (!state.downloading) TextButton(onClick = updater::dismiss) { Text("稍后") }
        },
    )
}

private fun openSystemInstaller(context: android.content.Context, updater: AndroidAppUpdater, file: File) {
    try {
        val uri = FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
        val installIntent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, "application/vnd.android.package-archive")
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        context.startActivity(installIntent)
        updater.installerLaunched()
    } catch (_: ActivityNotFoundException) {
        updater.installerFailed("系统未找到可用安装器，请从下载页手动安装")
    } catch (_: SecurityException) {
        updater.installerFailed("系统拒绝启动安装器，请检查设备安装权限后重试")
    } catch (_: IllegalArgumentException) {
        updater.installerFailed("更新包无法打开，请重新下载后重试")
    }
}
