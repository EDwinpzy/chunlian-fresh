<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;
use InvalidArgumentException;

/**
 * 幂等处理。
 *
 * 规则（docs/API.md §1.4）：
 * - 所有写操作必须携带 `Idempotency-Key`（UUID v4）。
 * - 作用域：`actor_id + endpoint + key`，有效期 24h。
 * - 重复请求：返回首次执行结果，不重复执行副作用。
 * - 相同 key 但请求体不同：拒绝 `IDEMPOTENCY_CONFLICT`。
 *
 * 存储由调用方注入（数据库/Redis 均可），本类只负责校验与指纹比对。
 */
final class IdempotencyService
{
    /** 幂等键有效期（秒）。 */
    public const TTL = 86400;

    public function __construct(
        private readonly IdempotencyStore $store,
    ) {
    }

    public static function isValidKey(string $key): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            trim($key)
        );
    }

    /**
     * 执行一次幂等写操作。
     *
     * @param callable():array $operation 首次执行时调用的业务逻辑，返回结果数组
     * @return array{code:string,data:array,replayed:bool}
     */
    public function run(
        string $actorId,
        string $endpoint,
        string $idempotencyKey,
        array $requestPayload,
        callable $operation,
    ): array {
        if (!self::isValidKey($idempotencyKey)) {
            throw new InvalidArgumentException('Idempotency-Key 必须是 UUID v4');
        }

        $fingerprint = $this->fingerprint($requestPayload);

        // 先尝试占用
        $existing = $this->store->find($actorId, $endpoint, $idempotencyKey);

        if ($existing !== null) {
            if ($existing['fingerprint'] !== $fingerprint) {
                return [
                    'code' => RejectionCode::IDEMPOTENCY_CONFLICT->value,
                    'data' => [
                        'message' => RejectionCode::IDEMPOTENCY_CONFLICT->defaultMessage(),
                    ],
                    'replayed' => true,
                ];
            }

            return [
                'code' => 'OK',
                'data' => $existing['response'],
                'replayed' => true,
            ];
        }

        $this->store->reserve($actorId, $endpoint, $idempotencyKey, $fingerprint, self::TTL);

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            // 失败不占用幂等键，允许重试
            $this->store->release($actorId, $endpoint, $idempotencyKey);
            throw $e;
        }

        $this->store->commit($actorId, $endpoint, $idempotencyKey, $result);

        return [
            'code' => 'OK',
            'data' => $result,
            'replayed' => false,
        ];
    }

    /** 请求体指纹：规范化后 SHA-256，用于检测同 key 不同体。 */
    public function fingerprint(array $payload): string
    {
        return hash('sha256', $this->canonicalize($payload));
    }

    /** 递归按键排序，保证同一语义的数组产生相同指纹。 */
    private function canonicalize(array $payload): string
    {
        $normalize = static function (mixed $value) use (&$normalize): mixed {
            if (is_array($value)) {
                if (array_is_list($value)) {
                    return array_map($normalize, $value);
                }
                ksort($value);

                return array_map($normalize, $value);
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
