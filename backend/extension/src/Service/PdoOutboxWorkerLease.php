<?php

declare(strict_types=1);

namespace Chunlian\Service;

/**
 * Atomically claims one outbox event and protects it with an expiring lease.
 * The status and timestamp columns already exist on veg_outbox_event; the
 * claim marker is kept in last_error only while the event is being processed.
 */
final class PdoOutboxWorkerLease
{
    public const DEFAULT_LEASE_SECONDS = 300;

    private readonly string $claimMarker;

    public function __construct(
        private readonly \PDO $pdo,
        string $workerId,
        private readonly int $leaseSeconds = self::DEFAULT_LEASE_SECONDS,
        private readonly int $maxRetry = 8,
        private readonly int $backoffBaseSeconds = 30,
        private readonly int $backoffCapSeconds = 3600,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $workerId) !== 1) {
            throw new \InvalidArgumentException('Worker ID must be 32 lowercase hexadecimal characters');
        }
        if ($leaseSeconds < 30 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Worker lease must be between 30 and 3600 seconds');
        }
        if ($maxRetry < 1 || $backoffBaseSeconds < 0 || $backoffCapSeconds < $backoffBaseSeconds) {
            throw new \InvalidArgumentException('Invalid outbox retry policy');
        }

        $this->claimMarker = 'WORKER_CLAIM:' . $workerId;
    }

    /** @return array<string,mixed>|null */
    public function claimNext(): ?array
    {
        $this->recoverExpiredClaims();
        $now = $this->now();

        // A conditional update is the claim's compare-and-set. If two workers
        // read the same candidate, only one can change it from PENDING.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidateStmt = $this->pdo->prepare(
                "SELECT id
                 FROM veg_outbox_event
                 WHERE status='PENDING'
                   AND (next_retry_at IS NULL OR next_retry_at<=?)
                 ORDER BY id ASC
                 LIMIT 1"
            );
            $candidateStmt->execute([$now]);
            $candidateId = $candidateStmt->fetchColumn();
            if ($candidateId === false) {
                return null;
            }

            $claim = $this->pdo->prepare(
                "UPDATE veg_outbox_event
                 SET status='PROCESSING', last_error=?, updated_at=?
                 WHERE id=? AND status='PENDING'
                   AND (next_retry_at IS NULL OR next_retry_at<=?)"
            );
            $claim->execute([$this->claimMarker, $now, $candidateId, $now]);
            if ($claim->rowCount() !== 1) {
                continue;
            }

            $eventStmt = $this->pdo->prepare(
                "SELECT id, event_type, entity_id, payload, retry_count
                 FROM veg_outbox_event
                 WHERE id=? AND status='PROCESSING' AND last_error=?"
            );
            $eventStmt->execute([$candidateId, $this->claimMarker]);
            $event = $eventStmt->fetch(\PDO::FETCH_ASSOC);

            return is_array($event) ? $event : null;
        }

        return null;
    }

    private function recoverExpiredClaims(): void
    {
        $now = $this->now();
        $expiredAt = date('Y-m-d H:i:s', time() - $this->leaseSeconds);
        $stmt = $this->pdo->prepare(
            "SELECT id, retry_count FROM veg_outbox_event
             WHERE status='PROCESSING' AND updated_at<=?"
        );
        $stmt->execute([$expiredAt]);
        $expiredClaims = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($expiredClaims as $claim) {
            $eventId = (int) $claim['id'];
            $retryCount = (int) $claim['retry_count'] + 1;
            if ($retryCount >= $this->maxRetry) {
                $recover = $this->pdo->prepare(
                    "UPDATE veg_outbox_event
                     SET status='DEAD', retry_count=?, last_error='Worker lease expired; retry limit reached', updated_at=?
                     WHERE id=? AND status='PROCESSING' AND updated_at<=?"
                );
                $recover->execute([$retryCount, $now, $eventId, $expiredAt]);
                continue;
            }

            $delay = min($this->backoffCapSeconds, $this->backoffBaseSeconds * $retryCount);
            $nextRetryAt = date('Y-m-d H:i:s', (int) strtotime($now) + $delay);
            $recover = $this->pdo->prepare(
                "UPDATE veg_outbox_event
                 SET status='PENDING', retry_count=?, last_error='Worker lease expired; retrying', next_retry_at=?, updated_at=?
                 WHERE id=? AND status='PROCESSING' AND updated_at<=?"
            );
            $recover->execute([$retryCount, $nextRetryAt, $now, $eventId, $expiredAt]);
        }
    }

    public function renew(int $eventId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE veg_outbox_event SET updated_at=?
             WHERE id=? AND status='PROCESSING' AND last_error=?"
        );
        $stmt->execute([$this->now(), $eventId, $this->claimMarker]);

        return $this->owns($eventId);
    }

    public function savePayload(int $eventId, string $payload): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE veg_outbox_event SET payload=?, updated_at=?
             WHERE id=? AND status='PROCESSING' AND last_error=?"
        );
        $stmt->execute([$payload, $this->now(), $eventId, $this->claimMarker]);

        return $this->owns($eventId);
    }

    public function markSent(int $eventId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE veg_outbox_event
             SET status='SENT', sent_at=?, updated_at=?, last_error=NULL
             WHERE id=? AND status='PROCESSING' AND last_error=?"
        );
        $now = $this->now();
        $stmt->execute([$now, $now, $eventId, $this->claimMarker]);

        return $stmt->rowCount() === 1;
    }

    public function markFailed(int $eventId, int $retryCount, int $maxRetry, int $delaySeconds, string $message): bool
    {
        $now = $this->now();
        if ($retryCount >= $maxRetry) {
            $stmt = $this->pdo->prepare(
                "UPDATE veg_outbox_event
                 SET status='DEAD', retry_count=?, last_error=?, updated_at=?
                 WHERE id=? AND status='PROCESSING' AND last_error=?"
            );
            $stmt->execute([$retryCount, $message, $now, $eventId, $this->claimMarker]);
        } else {
            $nextRetryAt = date('Y-m-d H:i:s', time() + max(0, $delaySeconds));
            $stmt = $this->pdo->prepare(
                "UPDATE veg_outbox_event
                 SET status='PENDING', retry_count=?, last_error=?, next_retry_at=?, updated_at=?
                 WHERE id=? AND status='PROCESSING' AND last_error=?"
            );
            $stmt->execute([$retryCount, $message, $nextRetryAt, $now, $eventId, $this->claimMarker]);
        }

        return $stmt->rowCount() === 1;
    }

    private function owns(int $eventId): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM veg_outbox_event
             WHERE id=? AND status='PROCESSING' AND last_error=?"
        );
        $stmt->execute([$eventId, $this->claimMarker]);

        return $stmt->fetchColumn() !== false;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}
