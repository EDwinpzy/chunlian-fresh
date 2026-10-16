<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 拒绝原因码。
 *
 * 与 docs/API.md 的错误码表一一对应；`httpStatus()` 用于映射响应码。
 */
enum RejectionCode: string
{
    case ORDER_NOT_EDITABLE = 'ORDER_NOT_EDITABLE';
    case ORDER_VERSION_CONFLICT = 'ORDER_VERSION_CONFLICT';
    case OUT_OF_STOCK = 'OUT_OF_STOCK';
    case TOPUP_REQUIRED = 'TOPUP_REQUIRED';
    case PAYMENT_CONFIRMING = 'PAYMENT_CONFIRMING';
    case REFUND_PROCESSING = 'REFUND_PROCESSING';
    case COD_ALREADY_COLLECTED = 'COD_ALREADY_COLLECTED';
    case INVALID_EXPECTED_TIME = 'INVALID_EXPECTED_TIME';
    case SHOP_CLOSED = 'SHOP_CLOSED';
    case IDEMPOTENCY_CONFLICT = 'IDEMPOTENCY_CONFLICT';
    case IDEMPOTENCY_IN_PROGRESS = 'IDEMPOTENCY_IN_PROGRESS';
    case VALIDATION_FAILED = 'VALIDATION_FAILED';
    case NOT_FOUND = 'NOT_FOUND';
    case FORBIDDEN = 'FORBIDDEN';

    public function httpStatus(): int
    {
        return match ($this) {
            self::ORDER_NOT_EDITABLE,
            self::ORDER_VERSION_CONFLICT,
            self::OUT_OF_STOCK,
            self::TOPUP_REQUIRED,
            self::PAYMENT_CONFIRMING,
            self::REFUND_PROCESSING,
            self::COD_ALREADY_COLLECTED,
            self::SHOP_CLOSED,
            self::IDEMPOTENCY_CONFLICT,
            self::IDEMPOTENCY_IN_PROGRESS => 409,
            self::INVALID_EXPECTED_TIME,
            self::VALIDATION_FAILED => 422,
            self::NOT_FOUND => 404,
            self::FORBIDDEN => 403,
        };
    }

    public function defaultMessage(): string
    {
        return match ($this) {
            self::ORDER_NOT_EDITABLE => '商家已开始配送，不能再修改或取消',
            self::ORDER_VERSION_CONFLICT => '订单发生变化，请刷新后重试',
            self::OUT_OF_STOCK => '部分商品库存不足，请调整数量',
            self::TOPUP_REQUIRED => '该订单还需补款，暂不能配送',
            self::PAYMENT_CONFIRMING => '支付确认中，请稍后刷新',
            self::REFUND_PROCESSING => '退款正在处理，请稍后查询',
            self::COD_ALREADY_COLLECTED => '此单已登记收款，请勿重复操作',
            self::INVALID_EXPECTED_TIME => '预约时间必须晚于当前时间',
            self::SHOP_CLOSED => '商家暂停营业，暂不能新下单',
            self::IDEMPOTENCY_CONFLICT => '相同请求已处理，结果不同',
            self::IDEMPOTENCY_IN_PROGRESS => '相同请求正在处理中，请稍后刷新',
            self::VALIDATION_FAILED => '提交内容有误，请检查后重试',
            self::NOT_FOUND => '资源不存在',
            self::FORBIDDEN => '无权访问该资源',
        };
    }
}
