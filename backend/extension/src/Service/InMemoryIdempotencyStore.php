<?php

declare(strict_types=1);

namespace Chunlian\Service;

/**
 * 内存幂等存储 —— 仅用于单元测试与本地开发。
 *
 * 生产环境必须替换为持久化实现（数据库或 ShopXO 已验证的缓存后端），
 * 否则进程重启后幂等保护会失效。
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /** @var array<string,array{fingerprint:string,response:?array,reserved_at:int,ttl:int}> */
    private array $items = [];

    public function find(string $actorId, string $endpoint, string $key): ?array
    {
        $id = $this->id($actorId, $endpoint, $key);
        if (!isset($this->items[$id])) {
            return null;
        }

        if ($this->items[$id]['reserved_at'] + $this->items[$id]['ttl'] <= time()) {
            unset($this->items[$id]);

            return null;
        }

        return [
            'fingerprint' => $this->items[$id]['fingerprint'],
            'response' => $this->items[$id]['response'],
        ];
    }

    public function reserve(string $actorId, string $endpoint, string $key, string $fingerprint, int $ttl): bool
    {
        $id = $this->id($actorId, $endpoint, $key);
        if ($this->find($actorId, $endpoint, $key) !== null) {
            return false;
        }

        $this->items[$id] = [
            'fingerprint' => $fingerprint,
            'response' => null,
            'reserved_at' => time(),
            'ttl' => $ttl,
        ];

        return true;
    }

    public function commit(string $actorId, string $endpoint, string $key, array $response): void
    {
        $id = $this->id($actorId, $endpoint, $key);
        if (isset($this->items[$id])) {
            $this->items[$id]['response'] = $response;
        }
    }

    public function release(string $actorId, string $endpoint, string $key): void
    {
        unset($this->items[$this->id($actorId, $endpoint, $key)]);
    }

    private function id(string $actorId, string $endpoint, string $key): string
    {
        return $actorId . '|' . $endpoint . '|' . $key;
    }
}
