<?php

declare(strict_types=1);

namespace Chunlian\Tests\Repository;

use Chunlian\Repository\PdoPushDeviceRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoPushDeviceRepositoryTest extends TestCase
{
    private PDO $pdo;
    private PdoPushDeviceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for push-device repository tests');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE veg_push_device ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT NOT NULL, actor_id TEXT NOT NULL, '
            . 'app_id TEXT NOT NULL, installation_id TEXT NOT NULL, cid TEXT NOT NULL, revoked_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(app_id, installation_id))'
        );
        $this->pdo->exec('CREATE TABLE shopxo_admin (id INTEGER PRIMARY KEY, status INTEGER NOT NULL)');
        $this->repository = new PdoPushDeviceRepository($this->pdo);
    }

    public function testBindingIsIdempotentAndRotatesTheCid(): void
    {
        $this->repository->bind('MERCHANT', '12', 'merchant-app', 'install-12345678', 'cid-old-123456');
        $this->repository->bind('MERCHANT', '12', 'merchant-app', 'install-12345678', 'cid-new-123456');

        $row = $this->pdo->query('SELECT actor_type, actor_id, cid, revoked_at FROM veg_push_device')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('MERCHANT', $row['actor_type']);
        self::assertSame('12', $row['actor_id']);
        self::assertSame('cid-new-123456', $row['cid']);
        self::assertNull($row['revoked_at']);
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM veg_push_device')->fetchColumn());
    }

    public function testRevokeIsScopedToTheAuthenticatedActorAndSafeToRepeat(): void
    {
        $this->repository->bind('MERCHANT', '12', 'merchant-app', 'install-12345678', 'cid-merchant-123');
        $this->repository->bind('CUSTOMER', '12', 'customer-app', 'install-12345678', 'cid-customer-123');

        $this->repository->revoke('MERCHANT', '13', 'install-12345678');
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM veg_push_device WHERE actor_type='MERCHANT' AND revoked_at IS NOT NULL"
        )->fetchColumn());

        $this->repository->revoke('MERCHANT', '12', 'install-12345678');
        $this->repository->revoke('MERCHANT', '12', 'install-12345678');
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM veg_push_device WHERE actor_type='MERCHANT' AND revoked_at IS NOT NULL"
        )->fetchColumn());
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM veg_push_device WHERE actor_type='CUSTOMER' AND revoked_at IS NOT NULL"
        )->fetchColumn());
    }

    public function testMerchantRecipientSetMatchesEnabledAdminFeedBoundary(): void
    {
        $this->pdo->exec('INSERT INTO shopxo_admin (id, status) VALUES (12, 0), (13, 1)');
        $this->repository->bind('MERCHANT', '12', 'merchant-app', 'install-active-123', 'cid-active-123');
        $this->repository->bind('MERCHANT', '13', 'merchant-app', 'install-disabled-123', 'cid-disabled-123');
        $this->repository->bind('CUSTOMER', '12', 'customer-app', 'install-customer-123', 'cid-customer-123');

        $devices = $this->repository->activeMerchantDevices('shopxo_');

        self::assertCount(1, $devices);
        self::assertSame('12', $devices[0]['actor_id']);
        self::assertSame('merchant-app', $devices[0]['app_id']);
        self::assertSame('cid-active-123', $devices[0]['cid']);
    }
}
