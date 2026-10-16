<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;

/**
 * 状态迁移结果。成功携带载荷，失败携带拒绝原因。
 */
final class TransitionResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?RejectionCode $code,
        public readonly array $data,
        public readonly ?string $message,
    ) {
    }

    public static function ok(array $data = []): self
    {
        return new self(true, null, $data, null);
    }

    public static function reject(RejectionCode $code, array $data = [], ?string $message = null): self
    {
        return new self(false, $code, $data, $message ?? $code->defaultMessage());
    }

    public function httpStatus(): int
    {
        return $this->success ? 200 : (int) $this->code?->httpStatus();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
