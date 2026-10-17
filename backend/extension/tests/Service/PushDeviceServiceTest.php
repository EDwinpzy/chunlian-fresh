<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Repository\PdoPushDeviceRepository;
use Chunlian\Service\PushDeviceService;
use PDO;
use PHPUnit\Framework\TestCase;

final class PushDeviceServiceTest extends TestCase
{
    private PDO $pdo;
    private PushDeviceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for push-device service tests');
        }

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE veg_push_device ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, actor_type TEXT NOT NULL, actor_id TEXT NOT NULL, '
            . 'app_id TEXT NOT NULL, installation_id TEXT NOT NULL, cid TEXT NOT NULL, revoked_at TEXT NULL, '
            . 'created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(app_id, installation_id))'
        );
        $this->service = new PushDeviceService(
            new PdoPushDeviceRepository($this->pdo),
            [
                'merchant' => ['app_id' => 'merchant-app'],
                'customer' => ['app_id' => 'customer-app'],
            ],
        );
    }

    public function testBindUsesAuthenticatedActorAndDoesNotTrustBodyIdentity(): void
    {
        $result = $this->service->bind('MERCHANT', '12', [
            'app_id' => 'merchant-app',
            'cid' => 'cid-merchant-123',
            'installation_id' => 'install-merchant-123',
            'actor_id' => '999',
            'actor_type' => 'CUSTOMER',
        ]);

        self::assertSame('OK', $result['code']);
        $row = $this->pdo->query('SELECT actor_type, actor_id FROM veg_push_device')->fetch(PDO::FETCH_ASSOC);
        self::assertSame('MERCHANT', $row['actor_type']);
        self::assertSame('12', $row['actor_id']);
    }

    public function testClientCannotChooseAnotherApplicationsCredentials(): void
    {
        $result = $this->service->bind('MERCHANT', '12', [
            'app_id' => 'customer-app',
            'cid' => 'cid-merchant-123',
            'installation_id' => 'install-merchant-123',
        ]);

        self::assertSame('VALIDATION_FAILED', $result['code']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM veg_push_device')->fetchColumn());
    }

    public function testMissingConfiguredAppIdRejectsRegistrationWithoutPersisting(): void
    {
        $service = new PushDeviceService(new PdoPushDeviceRepository($this->pdo), []);
        $result = $service->bind('MERCHANT', '12', [
            'app_id' => 'merchant-app',
            'cid' => 'cid-merchant-123',
            'installation_id' => 'install-merchant-123',
        ]);

        self::assertSame('PUSH_NOT_CONFIGURED', $result['code']);
        self::assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM veg_push_device')->fetchColumn());
    }
}
