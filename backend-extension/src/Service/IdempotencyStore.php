<?php

declare(strict_types=1);

namespace Chunlian\Service;

/**
 * 幂等键存储抽象。
 *
 * 生产实现建议使用数据库表（如 `veg_idempotency`）或 ShopXO 已验证的缓存后端；
 * 本接口便于单元测试注入内存实现。
 */
interface IdempotencyStore
{
    /** @return array{fingerprint:string,response:array}|null */
    public function find(string $actorId, string $endpoint, string $key): ?array;

    /** 占位，防止并发重复执行。 */
    public function reserve(string $actorId, string $endpoint, string $key, string $fingerprint, int $ttl): void;

    /** 写入最终结果。 */
    public function commit(string $actorId, string $endpoint, string $key, array $response): void;

    /** 执行失败时释放占位，允许重试。 */
    public function release(string $actorId, string $endpoint, string $key): void;
}
