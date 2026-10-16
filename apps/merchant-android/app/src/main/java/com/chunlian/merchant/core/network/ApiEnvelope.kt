package com.chunlian.merchant.core.network

import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement

/**
 * 服务端统一信封。
 *
 * 【为什么要单独定义错误类型而不是让 Retrofit 抛 HttpException】
 * 本项目所有业务错误（库存不足、版本冲突、待补款不能配送……）
 * 都返回 **HTTP 200 + code != "OK"**，因为它们是「确定的业务结果」
 * 而不是传输层失败。若按 HTTP 状态码判断，店主永远只能看到
 * 「请求失败」，而看不到「顾客有待支付的订单变更，暂不能配送」这种
 * 真正能指导他下一步动作的信息。
 */
@Serializable
data class ApiEnvelope<T>(
    val code: String = "OK",
    val message: String? = null,
    val data: T? = null,
    val request_id: String? = null,
)

/**
 * 业务异常。
 *
 * [code] 与后端 `RejectionCode` 一一对应，UI 可以据此决定
 * 是弹提示、跳登录，还是刷新订单。
 */
class ApiException(
    val code: String,
    override val message: String,
    val payload: JsonElement? = null,
) : Exception(message) {

    /** 登录失效：需要清会话并回到登录页 */
    val isUnauthorized: Boolean get() = code == "FORBIDDEN" || code == "UNAUTHORIZED"

    /** 订单版本冲突：提示刷新，而不是当成错误 */
    val isVersionConflict: Boolean get() = code == "ORDER_VERSION_CONFLICT"

    /** 存在未结补款：不能开始配送 */
    val isTopupRequired: Boolean get() = code == "TOPUP_REQUIRED"
}

/** 网络层异常（连不上、超时、解析失败），与业务异常分开。 */
class NetworkException(override val message: String, cause: Throwable? = null) : Exception(message, cause)

object ApiCodes {
    const val OK = "OK"
    const val FORBIDDEN = "FORBIDDEN"
    const val UNAUTHORIZED = "UNAUTHORIZED"
    const val NOT_FOUND = "NOT_FOUND"
    const val VALIDATION_FAILED = "VALIDATION_FAILED"
    const val ORDER_NOT_EDITABLE = "ORDER_NOT_EDITABLE"
    const val ORDER_VERSION_CONFLICT = "ORDER_VERSION_CONFLICT"
    const val OUT_OF_STOCK = "OUT_OF_STOCK"
    const val TOPUP_REQUIRED = "TOPUP_REQUIRED"
    const val COD_ALREADY_COLLECTED = "COD_ALREADY_COLLECTED"
    const val SHOP_CLOSED = "SHOP_CLOSED"
}

/** 统一取出 data；code != OK 时抛 [ApiException]。 */
fun <T> ApiEnvelope<T>.unwrap(): T {
    if (code != ApiCodes.OK) {
        throw ApiException(code, message ?: "操作失败，请重试")
    }

    @Suppress("UNCHECKED_CAST")
    return (data ?: Unit) as T
}
