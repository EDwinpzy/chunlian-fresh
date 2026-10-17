import java.util.Properties
import java.net.URI

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.serialization")
}

/**
 * 从 local.properties 读 API 地址，注入 BuildConfig。
 *
 * 【为什么不写死在代码里】
 * 开发、测试、上线用的地址不同。写死后每次换环境都要改代码，
 * 很容易出现「测试包连到线上库」这种事故。
 * 但**只注入地址，不注入任何密钥** —— APK 是可以被反编译的。
 */
val apiBaseUrlFromGradle = providers.gradleProperty("API_BASE_URL").orNull
    ?.trim()?.takeIf { it.isNotEmpty() }
val localApiBaseUrl: String = run {
    val props = Properties()
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        file.inputStream().use { props.load(it) }
    }
    props.getProperty("API_BASE_URL")?.trim().orEmpty()
}
val getuiAppId: String = run {
    val props = Properties()
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        file.inputStream().use { props.load(it) }
    }
    providers.gradleProperty("GETUI_APP_ID").orNull?.trim()?.takeIf { it.isNotEmpty() }
        ?: props.getProperty("GETUI_APP_ID")?.trim().orEmpty()
}
val merchantPrivacyPolicyUrl: String = run {
    val props = Properties()
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        file.inputStream().use { props.load(it) }
    }
    providers.gradleProperty("MERCHANT_PRIVACY_POLICY_URL").orNull?.trim()?.takeIf { it.isNotEmpty() }
        ?: props.getProperty("MERCHANT_PRIVACY_POLICY_URL")?.trim().orEmpty()
}
val productionApiBaseUrl = "https://103.236.97.137:8096"
// 工作站地址只供 Debug 使用。Release 忽略 local.properties，避免旧 HTTP 地址影响发布包。
val debugApiBaseUrl = apiBaseUrlFromGradle
    ?: localApiBaseUrl.takeIf { it.isNotBlank() }
    ?: productionApiBaseUrl
val releaseApiBaseUrl = apiBaseUrlFromGradle ?: productionApiBaseUrl
fun buildConfigString(value: String): String =
    "\"${value.replace("\\", "\\\\").replace("\"", "\\\"")}\""
val hasHttpsApiBaseUrl = runCatching {
    URI(releaseApiBaseUrl).scheme.equals("https", ignoreCase = true) && !URI(releaseApiBaseUrl).host.isNullOrBlank()
}.getOrDefault(false)

val signingPropertiesFile = providers.gradleProperty("SIGNING_PROPERTIES").orNull
    ?.trim()
    ?.takeIf { it.isNotEmpty() }
    ?.let { rootProject.file(it).canonicalFile }
val releaseSigningProperties = signingPropertiesFile?.let { file ->
    if (!file.isFile) {
        throw GradleException("SIGNING_PROPERTIES does not point to a readable properties file")
    }
    Properties().also { props -> file.inputStream().use { input -> props.load(input) } }
}

android {
    namespace = "com.chunlian.merchant"
    // 本机只装了 build-tools 34.0.0，因此 compileSdk 锁 34；
    // 用 35 会触发 AGP 去下载 35.0.0 的 build-tools，在离线/受限网络下会卡住
    compileSdk = 34
    buildToolsVersion = "34.0.0"

    defaultConfig {
        applicationId = "com.chunlian.merchant"
        minSdk = 24
        targetSdk = 34
        versionCode = 10
        versionName = "1.0.9"

        buildConfigField("String", "API_BASE_URL", buildConfigString(debugApiBaseUrl))
        buildConfigField("String", "GETUI_APP_ID", buildConfigString(getuiAppId))
        buildConfigField("String", "MERCHANT_PRIVACY_POLICY_URL", buildConfigString(merchantPrivacyPolicyUrl))
        manifestPlaceholders["GETUI_APPID"] = getuiAppId
    }

    buildTypes {
        debug {
            applicationIdSuffix = ".qa"
            isMinifyEnabled = false
        }
        release {
            // Release 不复用 debug 密钥。通过 -PSIGNING_PROPERTIES=<file> 提供
            // storeFile / storePassword / keyAlias / keyPassword。
            buildConfigField("String", "API_BASE_URL", buildConfigString(releaseApiBaseUrl))
            isMinifyEnabled = false
            signingConfig = releaseSigningProperties?.let { props ->
                val requiredNames = listOf("storeFile", "storePassword", "keyAlias", "keyPassword")
                val missing = requiredNames.filter { props.getProperty(it).isNullOrBlank() }
                if (missing.isNotEmpty()) {
                    throw GradleException("SIGNING_PROPERTIES is missing required fields: ${missing.joinToString()}")
                }
                val keyStoreFile = rootProject.file(props.getProperty("storeFile").trim())
                if (!keyStoreFile.isFile) {
                    throw GradleException("SIGNING_PROPERTIES storeFile does not point to an existing keystore")
                }
                signingConfigs.create("releaseFromProperties") {
                    storeFile = keyStoreFile
                    storePassword = props.getProperty("storePassword")
                    keyAlias = props.getProperty("keyAlias")
                    keyPassword = props.getProperty("keyPassword")
                }
            }
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = "17"
    }

    buildFeatures {
        compose = true
        buildConfig = true
    }

    sourceSets.getByName("main").java.srcDir(rootProject.file("../shared/android-update/src/main/java"))

    composeOptions {
        // 与 Kotlin 1.9.24 官方配对
        kotlinCompilerExtensionVersion = "1.5.14"
    }

    packaging {
        resources {
            excludes += "/META-INF/{AL2.0,LGPL2.1}"
        }
    }
}

tasks.matching { it.name == "preReleaseBuild" }.configureEach {
    doFirst {
        check(hasHttpsApiBaseUrl) { "Release 构建要求有效的 HTTPS API_BASE_URL。" }
        check(releaseSigningProperties != null) {
            "Release 构建要求 SIGNING_PROPERTIES 指向包含 storeFile、storePassword、keyAlias、keyPassword 的本地 Properties 文件。"
        }
    }
}

dependencies {
    testImplementation("junit:junit:4.13.2")
    testImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")

    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-compose:1.8.2")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.7.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.7.0")
    implementation("androidx.navigation:navigation-compose:2.7.7")

    val composeBom = platform("androidx.compose:compose-bom:2024.02.01")
    implementation(composeBom)
    androidTestImplementation(composeBom)

    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material:material")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    debugImplementation("androidx.compose.ui:ui-tooling")

    // 网络：Retrofit + OkHttp + kotlinx.serialization（方案 §7A.1 的选型）
    implementation("com.squareup.retrofit2:retrofit:2.9.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    implementation("com.jakewharton.retrofit:retrofit2-kotlinx-serialization-converter:1.0.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.6.3")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3")

    // 图片：商品缩略图异步加载 + 本地缓存
    implementation("io.coil-kt:coil-compose:2.6.0")

    // 会话持久化（token 存在 DataStore；敏感字段用 EncryptedSharedPreferences 时需 security-crypto）
    implementation("androidx.datastore:datastore-preferences:1.0.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    // 官方个推国内 Android SDK（AppID 是公开标识；任何服务端密钥均不进入 APK）。
    implementation("com.getui:gtsdk:3.3.16.0")
    implementation("com.getui:gtc:3.3.3.0")
}
