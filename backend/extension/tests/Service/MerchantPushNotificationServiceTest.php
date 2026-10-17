<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Repository\PdoPushDeviceRepository;
use Chunlian\Service\GetuiRestV2Client;
use Chunlian\Service\MerchantPushNotificationService;
use PDO;
use PHPUnit\Framework\TestCase;

final class MerchantPushNotificationServiceTest extends TestCase
{
    private PDO $pdo;
    private PdoPushDeviceRepository $devices;
    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is required for merchant push dispatcher tests');
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
        $this->devices = new PdoPushDeviceRepository($this->pdo);
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chunlian-dispatch-test-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->cacheDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if (is_dir($this->cacheDirectory)) {
            rmdir($this->cacheDirectory);
        }
        parent::tearDown();
    }

    public function testNoBoundActiveMerchantDevicesIsACompletedSafeSkip(): void
    {
        $calls = 0;
        $client = new GetuiRestV2Client(['token_cache_dir' => $this->cacheDirectory], function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $timeout,
        ) use (&$calls): array {
            $calls++;

            return ['status' => 500, 'body' => 'unexpected request'];
        });
        $service = new MerchantPushNotificationService($this->pdo, [], $client);

        self::assertSame(0, $service->dispatchOrderCreated(123, '456', 'shopxo_'));
        self::assertSame(0, $calls);
    }

    public function testTargetsEnabledMerchantDevicesDeduplicatesCidsAndUsesUniqueRequestIdsPerAttempt(): void
    {
        $this->pdo->exec('INSERT INTO shopxo_admin (id, status) VALUES (12, 0), (13, 0), (14, 1)');
        $this->devices->bind('MERCHANT', '12', 'merchant-app', 'install-merchant-12', 'cid-shared-123');
        $this->devices->bind('MERCHANT', '13', 'merchant-app', 'install-merchant-13', 'cid-shared-123');
        $this->devices->bind('MERCHANT', '14', 'merchant-app', 'install-merchant-14', 'cid-disabled-123');
        $this->devices->bind('CUSTOMER', '12', 'customer-app', 'install-customer-12', 'cid-customer-123');

        $pushRequests = [];
        $cid = 'cid-shared-123';
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$pushRequests, $cid): array {
            if (str_ends_with($url, '/auth')) {
                return ['status' => 200, 'body' => json_encode([
                    'code' => 0,
                    'data' => ['token' => 'server-token', 'expire_time' => (int) floor(microtime(true) * 1000) + 86400000],
                ], JSON_THROW_ON_ERROR)];
            }

            $decoded = json_decode((string) $body, true);
            $pushRequests[] = $decoded;

            return ['status' => 200, 'body' => json_encode([
                'code' => 0,
                'data' => ['task-id' => [$cid => 'successed_offline']],
            ], JSON_THROW_ON_ERROR)];
        };
        $config = [
            'merchant' => ['app_id' => 'merchant-app', 'app_key' => 'server-key', 'master_secret' => 'server-secret'],
            'token_cache_dir' => $this->cacheDirectory,
        ];
        $service = new MerchantPushNotificationService($this->pdo, $config, new GetuiRestV2Client($config, $transport));

        self::assertSame(1, $service->dispatchOrderCreated(123, '456', 'shopxo_'));
        self::assertSame(1, $service->dispatchOrderCreated(123, '456', 'shopxo_'));
        self::assertCount(2, $pushRequests);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $pushRequests[0]['request_id']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/D', $pushRequests[1]['request_id']);
        self::assertNotSame($pushRequests[0]['request_id'], $pushRequests[1]['request_id']);
    }

    public function testPartialFailureRetriesOnlyCidsWithoutCheckpointedProviderResults(): void
    {
        $this->pdo->exec('INSERT INTO shopxo_admin (id, status) VALUES (12, 0), (13, 0)');
        $this->devices->bind('MERCHANT', '12', 'merchant-app', 'install-merchant-12', 'cid-merchant-12');
        $this->devices->bind('MERCHANT', '13', 'merchant-app', 'install-merchant-13', 'cid-merchant-13');

        $pushRequests = [];
        $secondCidAttempts = 0;
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$pushRequests, &$secondCidAttempts): array {
            if (str_ends_with($url, '/auth')) {
                return ['status' => 200, 'body' => json_encode([
                    'code' => 0,
                    'data' => ['token' => 'server-token', 'expire_time' => (int) floor(microtime(true) * 1000) + 86400000],
                ], JSON_THROW_ON_ERROR)];
            }

            $request = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR);
            $cid = $request['audience']['cid'][0];
            $pushRequests[] = ['cid' => $cid, 'request_id' => $request['request_id']];
            if ($cid === 'cid-merchant-13' && ++$secondCidAttempts === 1) {
                return ['status' => 503, 'body' => 'temporary failure'];
            }

            return ['status' => 200, 'body' => json_encode([
                'code' => 0,
                'data' => ['task-id' => [$cid => 'successed_offline']],
            ], JSON_THROW_ON_ERROR)];
        };
        $config = [
            'merchant' => ['app_id' => 'merchant-app', 'app_key' => 'server-key', 'master_secret' => 'server-secret'],
            'token_cache_dir' => $this->cacheDirectory,
        ];
        $service = new MerchantPushNotificationService($this->pdo, $config, new GetuiRestV2Client($config, $transport));
        $completed = [];
        $checkpoint = static function (string $cid, string $status) use (&$completed): void {
            $completed[hash('sha256', $cid)] = $status;
        };

        try {
            $service->dispatchOrderCreated(123, '456', 'shopxo_', [], $checkpoint);
            self::fail('Expected second CID attempt to fail');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('HTTP request failed', $e->getMessage());
        }

        self::assertSame(['successed_offline'], array_values($completed));
        self::assertSame(2, count($pushRequests));

        self::assertSame(2, $service->dispatchOrderCreated(123, '456', 'shopxo_', $completed, $checkpoint));
        self::assertSame([
            'cid-merchant-12',
            'cid-merchant-13',
            'cid-merchant-13',
        ], array_column($pushRequests, 'cid'));
        self::assertCount(3, array_unique(array_column($pushRequests, 'request_id')));
    }

    public function testAppIdMismatchDoesNotSendToTheWrongApplication(): void
    {
        $this->pdo->exec('INSERT INTO shopxo_admin (id, status) VALUES (12, 0)');
        $this->devices->bind('MERCHANT', '12', 'registered-app', 'install-merchant-12', 'cid-merchant-123');
        $calls = 0;
        $config = ['merchant' => ['app_id' => 'configured-app']];
        $client = new GetuiRestV2Client(['token_cache_dir' => $this->cacheDirectory], function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $timeout,
        ) use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => '{"code":0}'];
        });
        $service = new MerchantPushNotificationService($this->pdo, $config, $client);

        try {
            $service->dispatchOrderCreated(123, '456', 'shopxo_');
            self::fail('Expected configured app mismatch to retry rather than skip silently');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('do not match', $e->getMessage());
        }
        self::assertSame(0, $calls);
    }

    public function testMissingMerchantSecretsFailDispatchInsteadOfReportingSuccess(): void
    {
        $this->pdo->exec('INSERT INTO shopxo_admin (id, status) VALUES (12, 0)');
        $this->devices->bind('MERCHANT', '12', 'merchant-app', 'install-merchant-12', 'cid-merchant-123');
        $service = new MerchantPushNotificationService($this->pdo, [
            'merchant' => ['app_id' => 'merchant-app', 'app_key' => 'server-key'],
        ]);

        try {
            $service->dispatchOrderCreated(123, '456', 'shopxo_');
            self::fail('Expected missing merchant secret to fail dispatch instead of succeeding');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('credentials are not configured', $e->getMessage());
        }
    }
}
