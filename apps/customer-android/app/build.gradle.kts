import java.util.Properties
import java.net.URI

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    id("org.jetbrains.kotlin.plugin.serialization")
}

val customerApiBaseUrlFromGradle = providers.gradleProperty("API_BASE_URL").orNull
    ?.trim()?.takeIf { it.isNotEmpty() }
val localCustomerApiBaseUrl: String = run {
    val props = Properties()
    val file = rootProject.file("local.properties")
    if (file.exists()) file.inputStream().use(props::load)
    props.getProperty("API_BASE_URL")?.trim().orEmpty()
}
val productionApiBaseUrl = "https://103.236.97.137:8096"
// Local properties are useful for debug workstations. Release builds ignore them so an
// old HTTP development URL cannot silently become the shipped API endpoint.
val customerDebugApiBaseUrl = customerApiBaseUrlFromGradle
    ?: localCustomerApiBaseUrl.takeIf { it.isNotBlank() }
    ?: productionApiBaseUrl
val customerReleaseApiBaseUrl = customerApiBaseUrlFromGradle ?: productionApiBaseUrl
fun buildConfigString(value: String): String =
    "\"${value.replace("\\", "\\\\").replace("\"", "\\\"")}\""
val allowInsecureLoopbackAuth: Boolean =
    providers.gradleProperty("ALLOW_INSECURE_LOOPBACK_AUTH").orNull == "true" &&
        runCatching { URI(customerDebugApiBaseUrl).host in setOf("127.0.0.1", "localhost", "::1") }.getOrDefault(false)
val signingPropertiesPath = providers.gradleProperty("SIGNING_PROPERTIES").orNull?.trim()?.takeIf { it.isNotEmpty() }
val signingProperties = Properties().apply {
    signingPropertiesPath?.let { path ->
        rootProject.file(path).takeIf { it.isFile }?.inputStream()?.use(::load)
    }
}
val releaseStoreFile = signingProperties.getProperty("storeFile")?.let(rootProject::file)
val hasReleaseSigning = releaseStoreFile?.isFile == true &&
    listOf("storePassword", "keyAlias", "keyPassword").all { !signingProperties.getProperty(it).isNullOrBlank() }

android {
    namespace = "com.chunlian.customer"
    compileSdk = 34
    buildToolsVersion = "34.0.0"

    defaultConfig {
        applicationId = "com.chunlian.customer"
        minSdk = 24
        targetSdk = 34
        versionCode = 6
        versionName = "1.0.5"
        buildConfigField("String", "API_BASE_URL", buildConfigString(customerDebugApiBaseUrl))
        buildConfigField("boolean", "ALLOW_INSECURE_LOOPBACK_AUTH", allowInsecureLoopbackAuth.toString())
    }

    signingConfigs {
        if (hasReleaseSigning) {
            create("projectRelease") {
                storeFile = releaseStoreFile
                storePassword = signingProperties.getProperty("storePassword")
                keyAlias = signingProperties.getProperty("keyAlias")
                keyPassword = signingProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        debug { isMinifyEnabled = false }
        release {
            isMinifyEnabled = false
            buildConfigField("String", "API_BASE_URL", buildConfigString(customerReleaseApiBaseUrl))
            buildConfigField("boolean", "ALLOW_INSECURE_LOOPBACK_AUTH", "false")
            if (hasReleaseSigning) signingConfig = signingConfigs.getByName("projectRelease")
        }
    }

    compileOptions {
        isCoreLibraryDesugaringEnabled = true
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
    kotlinOptions { jvmTarget = "17" }
    buildFeatures {
        compose = true
        buildConfig = true
    }
    composeOptions { kotlinCompilerExtensionVersion = "1.5.14" }
    sourceSets["main"].java.srcDir("../../shared/android-update/src/main/java")
}

// Release always targets HTTPS and must use the project signing key.
tasks.matching { it.name == "preReleaseBuild" }.configureEach {
    doFirst {
        check(customerReleaseApiBaseUrl.startsWith("https://", ignoreCase = true)) { "Release 构建要求 HTTPS，请配置 API_BASE_URL=https://..." }
        check(hasReleaseSigning) { "Release 构建要求 SIGNING_PROPERTIES 指向包含 storeFile、storePassword、keyAlias、keyPassword 的本地 Properties 文件。" }
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.0.4")
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-compose:1.8.2")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.7.0")
    implementation("androidx.lifecycle:lifecycle-runtime-compose:2.7.0")
    implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.7.0")

    val composeBom = platform("androidx.compose:compose-bom:2024.02.01")
    implementation(composeBom)
    implementation("androidx.compose.ui:ui")
    implementation("androidx.compose.ui:ui-graphics")
    implementation("androidx.compose.ui:ui-tooling-preview")
    implementation("androidx.compose.material3:material3")
    implementation("androidx.compose.material:material-icons-extended")
    debugImplementation("androidx.compose.ui:ui-tooling")

    implementation("com.squareup.retrofit2:retrofit:2.9.0")
    implementation("com.squareup.okhttp3:okhttp:4.12.0")
    implementation("com.squareup.okhttp3:logging-interceptor:4.12.0")
    implementation("com.jakewharton.retrofit:retrofit2-kotlinx-serialization-converter:1.0.0")
    implementation("org.jetbrains.kotlinx:kotlinx-serialization-json:1.6.3")
    implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.7.3")
    implementation("io.coil-kt:coil-compose:2.6.0")
    implementation("androidx.security:security-crypto:1.1.0-alpha06")

    testImplementation("junit:junit:4.13.2")
}
