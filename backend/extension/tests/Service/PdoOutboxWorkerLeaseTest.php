<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\PdoOutboxWorkerLease;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoOutboxWorkerLeaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for outbox worker lease tests');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE veg_outbox_event ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, event_type TEXT NOT NULL, entity_id TEXT NULL, payload TEXT NOT NULL, '
            . "status TEXT NOT NULL DEFAULT 'PENDING', retry_count INTEGER NOT NULL DEFAULT 0, next_retry_at TEXT NULL, "
            . 'sent_at TEXT NULL, last_error TEXT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL)'
        );
    }

    public function testConcurrentWorkerInstancesCannotClaimOrCompleteTheSameEvent(): void
    {
        $eventId = $this->insertEvent();
        $first = new PdoOutboxWorkerLease($this->pdo, str_repeat('a', 32));
        $second = new PdoOutboxWorkerLease($this->pdo, str_repeat('b', 32));

        $event = $first->claimNext();
        self::assertIsArray($event);
        self::assertSame($eventId, (int) $event['id']);
        self::assertSame('PROCESSING', $this->eventStatus($eventId));
        self::assertNull($second->claimNext());
        self::assertFalse($second->markSent($eventId));
        self::assertTrue($first->markSent($eventId));
        self::assertSame('SENT', $this->eventStatus($eventId));
    }

    public function testExpiredLeaseCanBeReclaimedAndOldWorkerCannotKeepProcessing(): void
    {
        $eventId = $this->insertEvent();
        $first = new PdoOutboxWorkerLease($this->pdo, str_repeat('c', 32), PdoOutboxWorkerLease::DEFAULT_LEASE_SECONDS, 8, 0, 1);
        $second = new PdoOutboxWorkerLease($this->pdo, str_repeat('d', 32), PdoOutboxWorkerLease::DEFAULT_LEASE_SECONDS, 8, 0, 1);
        self::assertIsArray($first->claimNext());

        $expired = date('Y-m-d H:i:s', time() - PdoOutboxWorkerLease::DEFAULT_LEASE_SECONDS - 1);
        $this->pdo->prepare('UPDATE veg_outbox_event SET updated_at=? WHERE id=?')->execute([$expired, $eventId]);

        $reclaimed = $second->claimNext();
        self::assertIsArray($reclaimed);
        self::assertSame($eventId, (int) $reclaimed['id']);
        self::assertSame(1, (int) $reclaimed['retry_count']);
        self::assertFalse($first->renew($eventId));
        self::assertFalse($first->savePayload($eventId, '{"stale":true}'));
        self::assertFalse($first->markSent($eventId));
        self::assertTrue($second->markSent($eventId));
        self::assertSame('SENT', $this->eventStatus($eventId));
    }

    public function testCidProgressCanBeCheckpointedOnlyByCurrentLease(): void
    {
        $eventId = $this->insertEvent();
        $lease = new PdoOutboxWorkerLease($this->pdo, str_repeat('e', 32));
        self::assertIsArray($lease->claimNext());
        self::assertTrue($lease->savePayload($eventId, '{"_worker_push":{"merchant_cid_results":{"hash":"successed_online"}}}'));

        $payload = $this->pdo->query('SELECT payload FROM veg_outbox_event WHERE id=' . $eventId)->fetchColumn();
        self::assertSame('successed_online', json_decode((string) $payload, true)['_worker_push']['merchant_cid_results']['hash']);
    }

    private function insertEvent(): int
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO veg_outbox_event (event_type, entity_id, payload, status, retry_count, next_retry_at, created_at, updated_at)
             VALUES ('ORDER_CREATED', '456', '{}', 'PENDING', 0, ?, ?, ?)"
        );
        $stmt->execute([$now, $now, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    private function eventStatus(int $eventId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM veg_outbox_event WHERE id=?');
        $stmt->execute([$eventId]);

        return (string) $stmt->fetchColumn();
    }
}
