package com.chunlian.merchant.core.network

import android.content.SharedPreferences
import java.lang.reflect.Proxy

@Suppress("UNCHECKED_CAST")
internal fun memorySharedPreferences(): SharedPreferences {
    val values = mutableMapOf<String, Any?>()
    lateinit var editor: SharedPreferences.Editor
    editor = Proxy.newProxyInstance(
        SharedPreferences.Editor::class.java.classLoader,
        arrayOf(SharedPreferences.Editor::class.java),
    ) { proxy, method, args ->
        when (method.name) {
            "putString", "putBoolean", "putInt", "putLong", "putFloat", "putStringSet" -> {
                values[args!![0] as String] = args[1]
                proxy
            }
            "remove" -> {
                values.remove(args!![0] as String)
                proxy
            }
            "clear" -> {
                values.clear()
                proxy
            }
            "commit" -> true
            "apply" -> null
            else -> null
        }
    } as SharedPreferences.Editor

    return Proxy.newProxyInstance(
        SharedPreferences::class.java.classLoader,
        arrayOf(SharedPreferences::class.java),
    ) { _, method, args ->
        when (method.name) {
            "getString" -> values[args!![0] as String] as? String ?: args[1] as? String
            "getBoolean" -> values[args!![0] as String] as? Boolean ?: args[1] as Boolean
            "getInt" -> values[args!![0] as String] as? Int ?: args[1] as Int
            "getLong" -> values[args!![0] as String] as? Long ?: args[1] as Long
            "getFloat" -> values[args!![0] as String] as? Float ?: args[1] as Float
            "getStringSet" -> values[args!![0] as String] as? Set<String> ?: args[1] as? Set<String>
            "getAll" -> values.toMap()
            "contains" -> values.containsKey(args!![0] as String)
            "edit" -> editor
            else -> null
        }
    } as SharedPreferences
}
