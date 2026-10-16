<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Domain\RejectionCode;
use Chunlian\Service\IdempotencyService;
use Chunlian\Service\InMemoryIdempotencyStore;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * 幂等测试 —— 对应 T12（微信支付回调重复 3 次只记一次）、T27（双击确认送达）。
 */
final class IdempotencyServiceTest extends TestCase
{
    private const KEY = '9f1c2f3e-4a5b-4c6d-8e7f-0a1b2c3d4e5f';
    private const KEY2 = '11111111-2222-4333-8444-555555555555';

    private IdempotencyService $service;
    private int $calls = 0;

    protected function setUp(): void
    {
        $this->service = new IdempotencyService(new InMemoryIdempotencyStore());
        $this->calls = 0;
    }

    private function operation(): callable
    {
        return function (): array {
            $this->calls++;

            return ['order_version' => 5, 'ledger_id' => 3012];
        };
    }

    public function testFirstCallExecutesOperation(): void
    {
        $result = $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, ['a' => 1], $this->operation());

        self::assertSame('OK', $result['code']);
        self::assertFalse($result['replayed']);
        self::assertSame(1, $this->calls);
        self::assertSame(3012, $result['data']['ledger_id']);
    }

    /** T27：重复提交只执行一次副作用。 */
    public function testRepeatedCallReplaysWithoutSideEffect(): void
    {
        $payload = ['order_version' => 4, 'received_money' => true];

        $first = $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, $payload, $this->operation());
        $second = $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, $payload, $this->operation());
        $third = $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, $payload, $this->operation());

        self::assertFalse($first['replayed']);
        self::assertTrue($second['replayed']);
        self::assertTrue($third['replayed']);

        // 关键：只执行一次
        self::assertSame(1, $this->calls);

        // 三次返回结果一致
        self::assertSame($first['data'], $second['data']);
        self::assertSame($first['data'], $third['data']);
    }

    public function testSameKeyDifferentBodyConflicts(): void
    {
        $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, ['amount' => 100], $this->operation());

        $result = $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, ['amount' => 200], $this->operation());

        self::assertSame(RejectionCode::IDEMPOTENCY_CONFLICT->value, $result['code']);
        self::assertSame(1, $this->calls);
    }

    public function testConcurrentReservationDoesNotExecuteDuplicateOperation(): void
    {
        $store = new InMemoryIdempotencyStore();
        $service = new IdempotencyService($store);
        $payload = ['amount_fen' => 980, 'version' => 2];
        $fingerprint = $service->fingerprint($payload);
        self::assertTrue($store->reserve('merchant:1', '/orders/10025/qr-receipt', self::KEY, $fingerprint, IdempotencyService::TTL));

        $result = $service->run(
            'merchant:1',
            '/orders/10025/qr-receipt',
            self::KEY,
            $payload,
            $this->operation()
        );

        self::assertSame(RejectionCode::IDEMPOTENCY_IN_PROGRESS->value, $result['code']);
        self::assertTrue($result['replayed']);
        self::assertSame(0, $this->calls, '占位冲突后不得重复执行资金写操作');
    }

    public function testConcurrentSameKeyWithDifferentPayloadConflictsWithoutExecuting(): void
    {
        $store = new InMemoryIdempotencyStore();
        $service = new IdempotencyService($store);
        $fingerprint = $service->fingerprint(['amount_fen' => 980]);
        self::assertTrue($store->reserve('merchant:1', '/orders/10025/qr-receipt', self::KEY, $fingerprint, IdempotencyService::TTL));

        $result = $service->run(
            'merchant:1',
            '/orders/10025/qr-receipt',
            self::KEY,
            ['amount_fen' => 1080],
            $this->operation()
        );

        self::assertSame(RejectionCode::IDEMPOTENCY_CONFLICT->value, $result['code']);
        self::assertSame(0, $this->calls, '不同请求体不能共享同一个进行中幂等键');
    }

    public function testExpiredReservationCanBeReused(): void
    {
        $store = new InMemoryIdempotencyStore();
        $fingerprint = hash('sha256', 'payload');

        self::assertTrue($store->reserve('merchant:1', '/orders/10025/qr-receipt', self::KEY, $fingerprint, 0));
        self::assertNull($store->find('merchant:1', '/orders/10025/qr-receipt', self::KEY));
        self::assertTrue($store->reserve('merchant:1', '/orders/10025/qr-receipt', self::KEY, $fingerprint, IdempotencyService::TTL));
    }

    public function testDifferentKeyExecutesAgain(): void
    {
        $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, ['a' => 1], $this->operation());
        $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY2, ['a' => 1], $this->operation());

        self::assertSame(2, $this->calls);
    }

    public function testScopeIsolatesActorsAndEndpoints(): void
    {
        $payload = ['a' => 1];

        $this->service->run('merchant:1', '/orders/10025/deliver', self::KEY, $payload, $this->operation());
        $this->service->run('merchant:2', '/orders/10025/deliver', self::KEY, $payload, $this->operation());
        $this->service->run('merchant:1', '/orders/10026/deliver', self::KEY, $payload, $this->operation());

        self::assertSame(3, $this->calls);
    }

    /** 失败不占用幂等键，允许重试。 */
    public function testFailedOperationReleasesKey(): void
    {
        try {
            $this->service->run('merchant:1', '/x', self::KEY, ['a' => 1], static function (): array {
                throw new \RuntimeException('boom');
            });
            self::fail('应当抛出异常');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        // 同一 key 重试应能执行
        $result = $this->service->run('merchant:1', '/x', self::KEY, ['a' => 1], $this->operation());

        self::assertSame('OK', $result['code']);
        self::assertFalse($result['replayed']);
        self::assertSame(1, $this->calls);
    }

    public function testRejectsNonUuidKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->run('merchant:1', '/x', 'not-a-uuid', [], $this->operation());
    }

    public function testValidKeyPatterns(): void
    {
        self::assertTrue(IdempotencyService::isValidKey(self::KEY));
        self::assertTrue(IdempotencyService::isValidKey(strtoupper(self::KEY)));
        self::assertFalse(IdempotencyService::isValidKey('123'));
        self::assertFalse(IdempotencyService::isValidKey(''));
        // v1 UUID 不合规（第三段必须以 4 开头）
        self::assertFalse(IdempotencyService::isValidKey('9f1c2f3e-4a5b-1c6d-8e7f-0a1b2c3d4e5f'));
    }

    /** 键序不同但语义相同的请求体应产生相同指纹。 */
    public function testFingerprintIsOrderInsensitive(): void
    {
        $a = $this->service->fingerprint(['b' => 2, 'a' => ['y' => 1, 'x' => 2]]);
        $b = $this->service->fingerprint(['a' => ['x' => 2, 'y' => 1], 'b' => 2]);

        self::assertSame($a, $b);
    }

    /** 列表顺序是有语义的，不应被规范化掉。 */
    public function testFingerprintRespectsListOrder(): void
    {
        $a = $this->service->fingerprint(['items' => [['sku' => 1], ['sku' => 2]]]);
        $b = $this->service->fingerprint(['items' => [['sku' => 2], ['sku' => 1]]]);

        self::assertNotSame($a, $b);
    }

    public function testFingerprintDiffersOnValueChange(): void
    {
        self::assertNotSame(
            $this->service->fingerprint(['qty' => 1]),
            $this->service->fingerprint(['qty' => 2])
        );
    }
}
