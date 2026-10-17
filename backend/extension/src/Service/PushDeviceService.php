<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\PdoPushDeviceRepository;

/** Authenticated device registration for the customer and merchant apps. */
final class PushDeviceService
{
    public function __construct(
        private readonly PdoPushDeviceRepository $devices,
        private readonly array $getuiConfig,
    ) {
    }

    /** @param array<string,mixed> $body */
    public function bind(string $actorType, string $actorId, array $body): array
    {
        $actorType = strtoupper($actorType);
        if (!in_array($actorType, ['MERCHANT', 'CUSTOMER'], true)
            || preg_match('/^[1-9][0-9]{0,63}$/D', $actorId) !== 1) {
            return $this->fail(RejectionCode::FORBIDDEN->value, '当前账号无法绑定通知设备');
        }

        $roleConfig = $this->roleConfig($actorType);
        $expectedAppId = (string) ($roleConfig['app_id'] ?? '');
        if ($expectedAppId === '') {
            return $this->fail('PUSH_NOT_CONFIGURED', '通知应用尚未配置');
        }

        $appId = $body['app_id'] ?? null;
        $cid = $body['cid'] ?? null;
        $installationId = $body['installation_id'] ?? null;
        if (!is_string($appId) || !is_string($cid) || !is_string($installationId)
            || $appId !== $expectedAppId
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $appId) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $installationId) !== 1
            || preg_match('/^[A-Za-z0-9_-]{8,128}$/D', $cid) !== 1) {
            return $this->fail(RejectionCode::VALIDATION_FAILED->value, 'app_id、cid 或 installation_id 无效');
        }

        $this->devices->bind($actorType, $actorId, $appId, $installationId, $cid);

        return [
            'code' => 'OK',
            'message' => '通知设备已绑定',
            'data' => [
                'app_id' => $appId,
                'installation_id' => $installationId,
                'bound' => true,
            ],
        ];
    }

    public function revoke(string $actorType, string $actorId, string $installationId): array
    {
        $actorType = strtoupper($actorType);
        if (!in_array($actorType, ['MERCHANT', 'CUSTOMER'], true)
            || preg_match('/^[1-9][0-9]{0,63}$/D', $actorId) !== 1
            || preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $installationId) !== 1) {
            return $this->fail(RejectionCode::VALIDATION_FAILED->value, 'installation_id 无效');
        }

        // Scope the update to the authenticated principal. Repeated DELETEs are safe.
        $this->devices->revoke($actorType, $actorId, $installationId);

        return [
            'code' => 'OK',
            'message' => '通知设备已解除绑定',
            'data' => ['installation_id' => $installationId, 'revoked' => true],
        ];
    }

    /** @return array<string,mixed> */
    private function roleConfig(string $actorType): array
    {
        $key = strtolower($actorType);
        $config = $this->getuiConfig[$key] ?? [];

        return is_array($config) ? $config : [];
    }

    private function fail(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message, 'data' => null];
    }
}
