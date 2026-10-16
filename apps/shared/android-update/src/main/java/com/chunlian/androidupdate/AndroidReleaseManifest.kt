package com.chunlian.androidupdate

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.decodeFromString
import kotlinx.serialization.json.Json

/** One published package entry from /downloads/android-release.json. */
data class AppUpdateRelease(
    val applicationId: String,
    val versionCode: Long,
    val versionName: String,
    val file: String,
    val sha256: String,
    val bytes: Long,
    val releaseNotes: String,
)

/** Parses the existing download manifest and selects a newer APK for one app. */
object AndroidReleaseManifest {
    private const val MAX_APK_BYTES = 200L * 1024L * 1024L
    private val json = Json { ignoreUnknownKeys = true }

    fun findUpdate(manifest: String, applicationId: String, currentVersionCode: Long): AppUpdateRelease? {
        val parsed = try {
            json.decodeFromString<PublishedReleaseManifest>(manifest)
        } catch (error: Exception) {
            throw IllegalArgumentException("版本清单格式无效", error)
        }

        return parsed.packages
            .mapNotNull { entry ->
                val entryApplicationId = entry.applicationId.ifBlank { inferApplicationId(entry.file).orEmpty() }
                if (entryApplicationId != applicationId || entry.versionCode <= currentVersionCode) return@mapNotNull null

                validate(entry)
                AppUpdateRelease(
                    applicationId = entryApplicationId,
                    versionCode = entry.versionCode,
                    versionName = entry.versionName,
                    file = entry.file,
                    sha256 = entry.sha256.lowercase(),
                    bytes = entry.bytes,
                    releaseNotes = entry.releaseNotes.trim(),
                )
            }
            .maxByOrNull(AppUpdateRelease::versionCode)
    }

    private fun validate(entry: PublishedPackage) {
        require(entry.versionCode > 0) { "版本清单中的 version_code 无效" }
        require(entry.versionName.isNotBlank() && entry.versionName.length <= 80) { "版本清单中的 version_name 无效" }
        require(entry.file.matches(Regex("[A-Za-z0-9][A-Za-z0-9._-]{0,159}\\.apk", RegexOption.IGNORE_CASE))) {
            "版本清单中的 APK 文件名无效"
        }
        require(entry.sha256.matches(Regex("[0-9a-fA-F]{64}"))) { "版本清单中的 SHA-256 无效" }
        require(entry.bytes in 0..MAX_APK_BYTES) { "版本清单中的 APK 大小无效" }
    }

    private fun inferApplicationId(file: String): String? = when {
        file.equals("customer-release.apk", ignoreCase = true) -> "com.chunlian.customer"
        file.equals("merchant-release.apk", ignoreCase = true) -> "com.chunlian.merchant"
        file.startsWith("chunlian-customer-", ignoreCase = true) -> "com.chunlian.customer"
        file.startsWith("chunlian-merchant-", ignoreCase = true) -> "com.chunlian.merchant"
        else -> null
    }

    @Serializable
    private data class PublishedReleaseManifest(
        val packages: List<PublishedPackage> = emptyList(),
    )

    @Serializable
    private data class PublishedPackage(
        @SerialName("application_id") val applicationId: String = "",
        val file: String = "",
        @SerialName("version_name") val versionName: String = "",
        @SerialName("version_code") val versionCode: Long = 0,
        val sha256: String = "",
        val bytes: Long = 0,
        @SerialName("release_notes") val releaseNotes: String = "",
    )
}
