// 根构建脚本：只声明插件版本，不应用到根工程。
//
// 【版本为什么锁死在 8.7.0 / 1.9.24 / compose 编译器 1.5.14】
// 这三个版本在开发机上已验证可离线解析（~/.gradle/caches 里都有），
// 且 compose 编译器 1.5.14 与 Kotlin 1.9.24 是官方配对版本 ——
// 编译器与 Kotlin 版本不匹配会直接报
// "This version of the Compose Compiler requires Kotlin version X"。
plugins {
    id("com.android.application") version "8.7.0" apply false
    id("org.jetbrains.kotlin.android") version "1.9.24" apply false
    id("org.jetbrains.kotlin.plugin.serialization") version "1.9.24" apply false
}
