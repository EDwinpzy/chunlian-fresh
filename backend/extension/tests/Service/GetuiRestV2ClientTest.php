<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\GetuiRestV2Client;
use PHPUnit\Framework\TestCase;

final class GetuiRestV2ClientTest extends TestCase
{
    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chunlian-getui-test-' . bin2hex(random_bytes(8));
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

    public function testUsesV2AuthCachesTokenAndSendsOnlyGenericNotificationText(): void
    {
        $requests = [];
        $cid = 'cid-merchant-123';
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$requests, $cid): array {
            $decoded = json_decode((string) $body, true);
            $requests[] = compact('method', 'url', 'headers', 'decoded', 'timeout');
            if (str_ends_with($url, '/auth')) {
                return $this->jsonResponse([
                    'code' => 0,
                    'data' => ['token' => 'server-token', 'expire_time' => (int) floor(microtime(true) * 1000) + 86400000],
                ]);
            }

            return $this->jsonResponse(['code' => 0, 'data' => ['task-id' => [$cid => 'successed_offline']]]);
        };

        $client = new GetuiRestV2Client([
            'timeout_seconds' => 4,
            'push_ttl_ms' => 7200000,
            'token_cache_dir' => $this->cacheDirectory,
        ], $transport);
        $credentials = ['app_id' => 'merchant-app', 'app_key' => 'server-app-key', 'master_secret' => 'server-master-secret'];

        self::assertSame('successed_offline', $client->sendNewOrder($credentials, $cid, '0123456789abcdef0123456789abcdef'));
        self::assertSame('successed_offline', $client->sendNewOrder($credentials, $cid, 'fedcba9876543210fedcba9876543210'));

        self::assertCount(3, $requests);
        self::assertSame('https://restapi.getui.com/v2/merchant-app/auth', $requests[0]['url']);
        self::assertSame('https://restapi.getui.com/v2/merchant-app/push/single/cid', $requests[1]['url']);
        self::assertSame(4, $requests[1]['timeout']);
        self::assertSame('server-token', $requests[1]['headers']['token']);
        self::assertSame(hash('sha256', 'server-app-key' . $requests[0]['decoded']['timestamp'] . 'server-master-secret'), $requests[0]['decoded']['sign']);
        self::assertSame('0123456789abcdef0123456789abcdef', $requests[1]['decoded']['request_id']);
        self::assertSame('fedcba9876543210fedcba9876543210', $requests[2]['decoded']['request_id']);
        self::assertSame(7200000, $requests[1]['decoded']['settings']['ttl']);
        self::assertSame('有新订单', $requests[1]['decoded']['push_message']['notification']['title']);
        self::assertSame('您有一笔新订单，请打开商家端查看。', $requests[1]['decoded']['push_message']['notification']['body']);
        self::assertSame('startapp', $requests[1]['decoded']['push_message']['notification']['click_type']);
        self::assertSame('有新订单', $requests[1]['decoded']['push_channel']['android']['ups']['notification']['title']);
        self::assertSame('您有一笔新订单，请打开商家端查看。', $requests[1]['decoded']['push_channel']['android']['ups']['notification']['body']);
        self::assertSame('startapp', $requests[1]['decoded']['push_channel']['android']['ups']['notification']['click_type']);
        self::assertArrayNotHasKey('master_secret', $requests[1]['decoded']);
        self::assertArrayNotHasKey('app_key', $requests[1]['decoded']);
    }

    public function testRefreshesTokenOnceWhenGeTuiReturnsInvalidToken(): void
    {
        $authCalls = 0;
        $pushCalls = 0;
        $requestIds = [];
        $cid = 'cid-merchant-123';
        $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use (&$authCalls, &$pushCalls, &$requestIds, $cid): array {
            if (str_ends_with($url, '/auth')) {
                $authCalls++;

                return $this->jsonResponse([
                    'code' => 0,
                    'data' => ['token' => 'token-' . $authCalls, 'expire_time' => (int) floor(microtime(true) * 1000) + 86400000],
                ]);
            }

            $pushCalls++;
            $requestIds[] = json_decode((string) $body, true, 512, JSON_THROW_ON_ERROR)['request_id'];

            return $pushCalls === 1
                ? $this->jsonResponse(['code' => 10001])
                : $this->jsonResponse(['code' => 0, 'data' => ['task-id' => [$cid => 'successed_online']]]);
        };
        $client = new GetuiRestV2Client(['token_cache_dir' => $this->cacheDirectory], $transport);

        self::assertSame('successed_online', $client->sendNewOrder(
            ['app_id' => 'merchant-app', 'app_key' => 'key', 'master_secret' => 'secret'],
            $cid,
            '1234567890abcdef1234567890abcdef',
        ));
        self::assertSame(2, $authCalls);
        self::assertSame(2, $pushCalls);
        self::assertCount(2, array_unique($requestIds));
        self::assertNotSame($requestIds[0], $requestIds[1]);
    }

    public function testProviderErrorsAndMalformedCidResultsRemainRetryable(): void
    {
        $cases = [
            ['code' => 400, 'msg' => 'bad request'],
            ['code' => 0, 'data' => ['task-id' => ['some-other-cid' => 'successed_offline']]],
        ];
        foreach ($cases as $response) {
            $transport = function (string $method, string $url, array $headers, ?string $body, int $timeout) use ($response): array {
                if (str_ends_with($url, '/auth')) {
                    return $this->jsonResponse([
                        'code' => 0,
                        'data' => ['token' => 'token', 'expire_time' => (int) floor(microtime(true) * 1000) + 86400000],
                    ]);
                }

                return $this->jsonResponse($response);
            };
            $client = new GetuiRestV2Client(['token_cache_dir' => $this->cacheDirectory], $transport);

            try {
                $client->sendNewOrder(
                    ['app_id' => 'merchant-app', 'app_key' => 'key', 'master_secret' => 'secret'],
                    'cid-merchant-123',
                    '1234567890abcdef1234567890abcdef',
                );
                self::fail('Expected invalid provider response to throw');
            } catch (\RuntimeException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
    }

    /** @param array<string,mixed> $payload @return array{status:int,body:string} */
    private function jsonResponse(array $payload): array
    {
        return ['status' => 200, 'body' => json_encode($payload, JSON_THROW_ON_ERROR)];
    }
}
