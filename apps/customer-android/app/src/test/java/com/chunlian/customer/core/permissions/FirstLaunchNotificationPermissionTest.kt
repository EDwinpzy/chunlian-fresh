package com.chunlian.customer.core.permissions

import android.os.Build
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class FirstLaunchNotificationPermissionTest {

    @Test
    fun requestsOnAndroid13AndLaterWhenNotHandledOrGranted() {
        assertTrue(
            shouldRequestNotificationPermission(
                apiLevel = Build.VERSION_CODES.TIRAMISU,
                promptHandled = false,
                permissionGranted = false,
            ),
        )
        assertTrue(
            shouldRequestNotificationPermission(
                apiLevel = Build.VERSION_CODES.TIRAMISU + 1,
                promptHandled = false,
                permissionGranted = false,
            ),
        )
    }

    @Test
    fun doesNotRequestBeforeAndroid13() {
        assertFalse(
            shouldRequestNotificationPermission(
                apiLevel = Build.VERSION_CODES.TIRAMISU - 1,
                promptHandled = false,
                permissionGranted = false,
            ),
        )
    }

    @Test
    fun doesNotRequestAgainAfterPromptWasHandled() {
        assertFalse(
            shouldRequestNotificationPermission(
                apiLevel = Build.VERSION_CODES.TIRAMISU,
                promptHandled = true,
                permissionGranted = false,
            ),
        )
    }

    @Test
    fun doesNotRequestWhenPermissionIsAlreadyGranted() {
        assertFalse(
            shouldRequestNotificationPermission(
                apiLevel = Build.VERSION_CODES.TIRAMISU,
                promptHandled = false,
                permissionGranted = true,
            ),
        )
    }
}
