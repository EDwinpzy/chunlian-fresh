package com.chunlian.customer.update

import com.chunlian.androidupdate.AndroidReleaseManifest
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertThrows
import org.junit.Test

class AndroidReleaseManifestTest {
    @Test
    fun selectsNewerPackageForApplicationId() {
        val result = AndroidReleaseManifest.findUpdate(
            manifest(
                """
                {"application_id":"com.chunlian.merchant","file":"merchant-release.apk","version_name":"1.0.4","version_code":5,"sha256":"$HASH","bytes":20},
                {"application_id":"com.chunlian.customer","file":"customer-release.apk","version_name":"1.0.3","version_code":4,"sha256":"$HASH","bytes":10,"release_notes":"在线更新"}
                """.trimIndent(),
            ),
            applicationId = "com.chunlian.customer",
            currentVersionCode = 3,
        )

        assertEquals(4L, result?.versionCode)
        assertEquals("customer-release.apk", result?.file)
        assertEquals("在线更新", result?.releaseNotes)
    }

    @Test
    fun supportsExistingManifestWithoutApplicationId() {
        val result = AndroidReleaseManifest.findUpdate(
            manifest("""{"file":"chunlian-customer-1.0.3.apk","version_name":"1.0.3","version_code":4,"sha256":"$HASH","bytes":10}"""),
            applicationId = "com.chunlian.customer",
            currentVersionCode = 3,
        )

        assertEquals("com.chunlian.customer", result?.applicationId)
    }

    @Test
    fun infersApplicationIdFromFixedReleaseName() {
        val result = AndroidReleaseManifest.findUpdate(
            manifest("""{"file":"merchant-release.apk","version_name":"1.0.4","version_code":5,"sha256":"$HASH","bytes":20}"""),
            applicationId = "com.chunlian.merchant",
            currentVersionCode = 4,
        )

        assertEquals("com.chunlian.merchant", result?.applicationId)
    }

    @Test
    fun returnsNoUpdateWhenVersionIsNotNewer() {
        val result = AndroidReleaseManifest.findUpdate(
            manifest("""{"application_id":"com.chunlian.customer","file":"chunlian-customer-1.0.3.apk","version_name":"1.0.3","version_code":4,"sha256":"$HASH","bytes":10}"""),
            applicationId = "com.chunlian.customer",
            currentVersionCode = 4,
        )

        assertNull(result)
    }

    @Test
    fun rejectsUnsafeFileNamesAndInvalidHashes() {
        val unsafeFile = manifest("""{"application_id":"com.chunlian.customer","file":"../payload.apk","version_name":"1.0.3","version_code":4,"sha256":"$HASH","bytes":10}""")
        val invalidHash = manifest("""{"application_id":"com.chunlian.customer","file":"customer.apk","version_name":"1.0.3","version_code":4,"sha256":"abc","bytes":10}""")

        assertThrows(IllegalArgumentException::class.java) {
            AndroidReleaseManifest.findUpdate(unsafeFile, "com.chunlian.customer", 3)
        }
        assertThrows(IllegalArgumentException::class.java) {
            AndroidReleaseManifest.findUpdate(invalidHash, "com.chunlian.customer", 3)
        }
    }

    private fun manifest(packages: String) = """{"release":"qa","packages":[$packages]}"""

    private companion object {
        const val HASH = "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
    }
}
