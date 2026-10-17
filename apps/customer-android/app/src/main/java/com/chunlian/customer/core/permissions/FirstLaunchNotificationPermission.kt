package com.chunlian.customer.core.permissions

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.platform.LocalContext
import androidx.core.content.ContextCompat

@Composable
fun RequestNotificationPermissionOnFirstLaunch() {
    val appContext = LocalContext.current.applicationContext
    val preferences = remember(appContext) {
        appContext.getSharedPreferences(PREFERENCES_NAME, Context.MODE_PRIVATE)
    }
    val permissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { }

    LaunchedEffect(Unit) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return@LaunchedEffect

        val promptHandled = preferences.getBoolean(PROMPT_HANDLED_KEY, false)
        val permissionGranted = ContextCompat.checkSelfPermission(
            appContext,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED
        if (!promptHandled) {
            preferences.edit().putBoolean(PROMPT_HANDLED_KEY, true).apply()
        }
        if (!shouldRequestNotificationPermission(Build.VERSION.SDK_INT, promptHandled, permissionGranted)) {
            return@LaunchedEffect
        }

        permissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }
}

internal fun shouldRequestNotificationPermission(
    apiLevel: Int,
    promptHandled: Boolean,
    permissionGranted: Boolean,
): Boolean = apiLevel >= Build.VERSION_CODES.TIRAMISU && !promptHandled && !permissionGranted

private const val PREFERENCES_NAME = "runtime_permission_prompts"
private const val PROMPT_HANDLED_KEY = "notification_permission_prompt_handled"
