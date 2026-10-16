<?php

declare(strict_types=1);

namespace Chunlian\Repository;

use Chunlian\Service\IdempotencyStore;

/**
 * 幂等键存储的 MySQL 实现（对应 `veg_idempotency` 表）。
 *
 * 【并发安全靠唯一约束，不靠「先查后写」】
 * `veg_idempotency` 上有 `uk_scope_key (actor_id, endpoint, idem_key)`。
 * 两个并发请求同时通过 `find()` 检查（都返回 null）后各自 `reserve()`，
 * 只有先到的那个能插入成功，后者触发唯一键冲突 —— 这正确地阻止了重复执行。
 *
 * 若用「先 SELECT 判断存在与否，再 INSERT」的写法，两个请求会双双通过判断、
 * 双双插入业务数据，幂等就形同虚设。
 */
final class PdoIdempotencyStore implements IdempotencyStore
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function find(string $actorId, string $endpoint, string $key): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT `fingerprint`, `response` FROM `veg_idempotency` '
            . 'WHERE `actor_id` = :actor_id AND `endpoint` = :endpoint AND `idem_key` = :idem_key '
            . 'AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['actor_id' => $actorId, 'endpoint' => $endpoint, 'idem_key' => $key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $response = $row['response'];
        if (!is_string($response) || $response === '') {
            $response = null;
        } else {
            $decoded = json_decode($response, true);
            $response = is_array($decoded) ? $decoded : null;
        }

        return [
            'fingerprint' => (string) $row['fingerprint'],
            'response' => is_array($response) ? $response : null,
        ];
    }

    /**
     * 占位。
     *
     * 并发下唯一键冲突是**预期行为**（说明另一个同键请求正在执行），
     * 因此吞掉 23000 冲突而不是抛出 —— 让后到的请求走到 find() 拿到结果，
     * 或者由上层返回「处理中」。
     */
    public function reserve(string $actorId, string $endpoint, string $key, string $fingerprint, int $ttl): bool
    {
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + $ttl);

        // 过期记录仍受唯一索引约束，先只删已过期的同作用域记录以允许重用 key。
        // 两个并发请求同时删除时，后续唯一索引仍决定唯一的占位赢家。
        $this->pdo->prepare(
            'DELETE FROM `veg_idempotency` WHERE `actor_id` = :actor_id AND `endpoint` = :endpoint '
            . 'AND `idem_key` = :idem_key AND `expires_at` <= NOW()'
        )->execute(['actor_id' => $actorId, 'endpoint' => $endpoint, 'idem_key' => $key]);

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `veg_idempotency` '
                . '(`actor_id`, `endpoint`, `idem_key`, `fingerprint`, `response`, `reserved_at`, `expires_at`, `created_at`) '
                . 'VALUES (:actor_id, :endpoint, :idem_key, :fingerprint, NULL, :reserved_at, :expires_at, :created_at)'
            );
            $stmt->execute([
                'actor_id' => $actorId,
                'endpoint' => $endpoint,
                'idem_key' => $key,
                'fingerprint' => $fingerprint,
                'reserved_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ]);

            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return false;
        }
    }

    public function commit(string $actorId, string $endpoint, string $key, array $response): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `veg_idempotency` SET `response` = :response '
            . 'WHERE `actor_id` = :actor_id AND `endpoint` = :endpoint AND `idem_key` = :idem_key'
        );
        $stmt->execute([
            'response' => json_encode($response, JSON_UNESCAPED_UNICODE),
            'actor_id' => $actorId,
            'endpoint' => $endpoint,
            'idem_key' => $key,
        ]);
    }

    /**
     * 释放占位，允许重试。
     *
     * 只在业务**抛异常**时调用。业务层面的「被拒绝」（如待补款不能配送）
     * 不释放 —— 那是一个确定的结果，重复提交应当拿到同样的拒绝，
     * 而不是重新执行一次。
     */
    public function release(string $actorId, string $endpoint, string $key): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `veg_idempotency` '
            . 'WHERE `actor_id` = :actor_id AND `endpoint` = :endpoint AND `idem_key` = :idem_key '
            . 'AND `response` IS NULL'
        );
        $stmt->execute(['actor_id' => $actorId, 'endpoint' => $endpoint, 'idem_key' => $key]);
    }

    /** 清理过期记录。由 cron 调用，避免表无限增长。 */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM `veg_idempotency` WHERE `expires_at` < NOW()');
        $stmt->execute();

        return $stmt->rowCount();
    }
}
