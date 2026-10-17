<?php

declare(strict_types=1);

namespace Chunlian\Service;

/** Minimal HTTPS client for GeTui REST API v2 single-CID notifications. */
final class GetuiRestV2Client
{
    private const API_BASE_URL = 'https://restapi.getui.com/v2';
    private const INVALID_TOKEN_CODE = '10001';
    private const MAX_TTL_MS = 259200000;

    private readonly ?\Closure $transport;
    private readonly int $timeoutSeconds;
    private readonly int $pushTtlMs;
    private readonly string $tokenCacheDirectory;

    /**
     * @param array<string,mixed> $config
     * @param callable(string,string,array<string,string>,?string,int):array{status:int,body:string}|null $transport
     */
    public function __construct(array $config, ?callable $transport = null)
    {
        $this->transport = $transport === null ? null : \Closure::fromCallable($transport);
        $this->timeoutSeconds = max(1, min(30, (int) ($config['timeout_seconds'] ?? 8)));
        $ttl = (int) ($config['push_ttl_ms'] ?? 7200000);
        $this->pushTtlMs = $ttl >= -1 && $ttl <= self::MAX_TTL_MS ? $ttl : 7200000;
        $cacheDirectory = trim((string) ($config['token_cache_dir'] ?? ''));
        $this->tokenCacheDirectory = $cacheDirectory !== ''
            ? $cacheDirectory
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'chunlian-fresh-getui-tokens';
    }

    /**
     * Send the same safe, generic order notification to one registered CID.
     * Include both the GeTui notification and Android offline vendor payload.
     *
     * @param array<string,mixed> $credentials
     * @return string GeTui's accepted processing status.
     */
    public function sendNewOrder(array $credentials, string $cid, string $requestId): string
    {
        $appId = (string) ($credentials['app_id'] ?? '');
        $appKey = (string) ($credentials['app_key'] ?? '');
        $masterSecret = (string) ($credentials['master_secret'] ?? '');
        if ($appId === '' || $appKey === '' || $masterSecret === '') {
            throw new \RuntimeException('GeTui merchant credentials are not configured');
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $appId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $cid) !== 1
            || preg_match('/^[A-Za-z0-9_-]{10,32}$/D', $requestId) !== 1) {
            throw new \InvalidArgumentException('Invalid GeTui app, CID or request identifier');
        }

        $body = [
            'request_id' => $requestId,
            'settings' => ['ttl' => $this->pushTtlMs],
            'audience' => ['cid' => [$cid]],
            'push_message' => [
                'notification' => [
                    'title' => '有新订单',
                    'body' => '您有一笔新订单，请打开商家端查看。',
                    'click_type' => 'startapp',
                ],
            ],
            'push_channel' => [
                'android' => [
                    'ups' => [
                        'notification' => [
                            'title' => '有新订单',
                            'body' => '您有一笔新订单，请打开商家端查看。',
                            'click_type' => 'startapp',
                        ],
                    ],
                ],
            ],
        ];

        $url = self::API_BASE_URL . '/' . rawurlencode($appId) . '/push/single/cid';
        $token = $this->getToken($appId, $appKey, $masterSecret);
        $response = $this->postJson($url, ['token' => $token], $body);

        if ($this->providerCode($response) === self::INVALID_TOKEN_CODE) {
            $token = $this->getToken($appId, $appKey, $masterSecret, true);
            // Treat the token refresh resend as a new provider request. GeTui
            // warns that a duplicate request_id can cause message loss.
            $body['request_id'] = bin2hex(random_bytes(16));
            $response = $this->postJson($url, ['token' => $token], $body);
        }

        if ($this->providerCode($response) !== '0') {
            throw new \RuntimeException('GeTui rejected push request (code ' . $this->providerCode($response) . ')');
        }

        $status = $this->cidStatus($response['data'] ?? null, $cid);
        if (!in_array($status, ['successed_offline', 'successed_online', 'successed_ignore'], true)) {
            throw new \RuntimeException('GeTui response did not contain a valid CID delivery status');
        }

        return $status;
    }

    private function getToken(string $appId, string $appKey, string $masterSecret, bool $forceRefresh = false): string
    {
        if (!is_dir($this->tokenCacheDirectory)
            && !@mkdir($this->tokenCacheDirectory, 0700, true)
            && !is_dir($this->tokenCacheDirectory)) {
            throw new \RuntimeException('GeTui token cache directory is unavailable');
        }
        @chmod($this->tokenCacheDirectory, 0700);

        // Include a one-way credential fingerprint so rotating a secret never reuses
        // a token obtained with the previous credential set or exposes secrets in paths.
        $cacheKey = hash('sha256', $appId . "\0" . $appKey . "\0" . $masterSecret);
        $cachePath = rtrim($this->tokenCacheDirectory, '/\\') . DIRECTORY_SEPARATOR . 'token-' . $cacheKey . '.json';
        $lock = @fopen($cachePath . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('GeTui token cache lock is unavailable');
        }
        @chmod($cachePath . '.lock', 0600);

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('GeTui token cache lock could not be acquired');
            }

            if (!$forceRefresh) {
                $cached = $this->readCachedToken($cachePath);
                if ($cached !== null) {
                    return $cached;
                }
            }

            $timestamp = (string) floor(microtime(true) * 1000);
            $signature = hash('sha256', $appKey . $timestamp . $masterSecret);
            $response = $this->postJson(
                self::API_BASE_URL . '/' . rawurlencode($appId) . '/auth',
                [],
                ['appkey' => $appKey, 'timestamp' => $timestamp, 'sign' => $signature],
            );

            if ($this->providerCode($response) !== '0') {
                throw new \RuntimeException('GeTui token request failed (code ' . $this->providerCode($response) . ')');
            }

            $data = $response['data'] ?? null;
            $token = is_array($data) ? ($data['token'] ?? null) : null;
            $expireTime = is_array($data) ? ($data['expire_time'] ?? null) : null;
            if (!is_string($token) || $token === '' || !is_scalar($expireTime)
                || !ctype_digit((string) $expireTime)
                || (int) $expireTime <= (int) floor(microtime(true) * 1000)) {
                throw new \RuntimeException('GeTui token response is incomplete or expired');
            }

            $this->writeCachedToken($cachePath, $token, (int) $expireTime);

            return $token;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readCachedToken(string $cachePath): ?string
    {
        $raw = @file_get_contents($cachePath);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $cache = json_decode($raw, true);
        if (!is_array($cache) || !is_string($cache['token'] ?? null)
            || !is_int($cache['expire_time'] ?? null)
            || $cache['token'] === ''
            || $cache['expire_time'] <= (int) floor(microtime(true) * 1000) + 300000) {
            return null;
        }

        return $cache['token'];
    }

    private function writeCachedToken(string $cachePath, string $token, int $expireTime): void
    {
        $temporaryPath = $cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $contents = json_encode(['token' => $token, 'expire_time' => $expireTime], JSON_THROW_ON_ERROR);
        if (file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('GeTui token could not be cached');
        }
        @chmod($temporaryPath, 0600);

        if (!@rename($temporaryPath, $cachePath)) {
            @unlink($temporaryPath);
            throw new \RuntimeException('GeTui token cache could not be updated');
        }
        @chmod($cachePath, 0600);
    }

    /** @param array<string,string> $headers @param array<string,mixed> $body @return array<string,mixed> */
    private function postJson(string $url, array $headers, array $body): array
    {
        $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $requestHeaders = ['Content-Type' => 'application/json;charset=utf-8'];
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }

        try {
            if ($this->transport !== null) {
                $result = ($this->transport)('POST', $url, $requestHeaders, $encoded, $this->timeoutSeconds);
            } else {
                $result = $this->streamRequest($url, $requestHeaders, $encoded);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('GeTui request failed or timed out', 0, $e);
        }

        if (!is_array($result) || !is_int($result['status'] ?? null) || !is_string($result['body'] ?? null)) {
            throw new \RuntimeException('GeTui returned an invalid HTTP response');
        }
        if ($result['status'] < 200 || $result['status'] >= 300) {
            throw new \RuntimeException('GeTui HTTP request failed (status ' . $result['status'] . ')');
        }

        $decoded = json_decode($result['body'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('GeTui returned invalid JSON');
        }

        return $decoded;
    }

    /** @param array<string,string> $headers @return array{status:int,body:string} */
    private function streamRequest(string $url, array $headers, string $body): array
    {
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseHeaders = $http_response_header ?? [];
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }
        if (!is_string($responseBody) || $status === 0) {
            throw new \RuntimeException('GeTui request failed or timed out');
        }

        return ['status' => $status, 'body' => $responseBody];
    }

    /** @param array<string,mixed> $response */
    private function providerCode(array $response): string
    {
        $code = $response['code'] ?? null;

        return is_scalar($code) ? (string) $code : 'invalid';
    }

    private function cidStatus(mixed $data, string $cid): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        foreach ($data as $taskResult) {
            if (is_array($taskResult) && is_string($taskResult[$cid] ?? null)) {
                return $taskResult[$cid];
            }
        }

        return null;
    }
}
