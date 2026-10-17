package com.chunlian.merchant

import android.app.Application
import android.app.ActivityManager
import android.os.Build
import android.os.Process
import com.chunlian.androidupdate.AndroidAppUpdater
import com.chunlian.merchant.core.network.ApiClient
import com.chunlian.merchant.core.network.SessionStore
import com.chunlian.merchant.core.push.GetuiPushSdkInitializer
import com.chunlian.merchant.data.repository.MerchantRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob

/**
 * 应用入口 + 依赖容器。
 *
 * 【为什么不用 Hilt】
 * 方案 §7A.1 允许 Hilt 或 Koin 二选一。本项目选择了**手写容器**，
 * 原因是 Hilt 需要 kapt/KSP 注解处理，会显著拉长构建时间并引入
 * 一类难排查的编译错误（注解处理器与 Kotlin/AGP 版本不匹配），
 * 而本 App 的依赖图只有三层（Session → Repository → ViewModel），
 * 一个 `object` 就能说清楚。依赖变复杂时再换 Hilt 也不影响上层代码。
 */
class MerchantApp : Application() {

    lateinit var session: SessionStore
        private set

    lateinit var repository: MerchantRepository
        private set

    lateinit var appUpdater: AndroidAppUpdater
        private set

    lateinit var getuiPushSdkInitializer: GetuiPushSdkInitializer
        private set

    val applicationScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    override fun onCreate() {
        super.onCreate()
        instance = this

        session = SessionStore(this)
        repository = MerchantRepository(session)
        getuiPushSdkInitializer = GetuiPushSdkInitializer(session)
        appUpdater = AndroidAppUpdater(
            context = this,
            applicationId = BuildConfig.APPLICATION_ID,
            currentVersionCode = BuildConfig.VERSION_CODE.toLong(),
            baseUrl = BuildConfig.API_BASE_URL,
        )

        // 把 token 提供者接到网络层：这样网络层不需要持有 Context
        ApiClient.tokenProvider = { session.token }

        // The SDK's custom PushService runs in :pushservice; initialize only in the
        // main app process to avoid starting the SDK recursively from its own process.
        if (isMainProcess()) getuiPushSdkInitializer.initializeIfAllowed(this)
    }

    private fun isMainProcess(): Boolean {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            return Application.getProcessName() == packageName
        }
        val pid = Process.myPid()
        val processes = getSystemService(ACTIVITY_SERVICE) as? ActivityManager
        return processes?.runningAppProcesses?.firstOrNull { it.pid == pid }?.processName == packageName
    }

    companion object {
        lateinit var instance: MerchantApp
            private set
    }
}
