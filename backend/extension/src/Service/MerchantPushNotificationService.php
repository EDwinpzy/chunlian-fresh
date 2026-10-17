<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Repository\PdoPushDeviceRepository;

/** Sends system notifications for order-created outbox events. */
final class MerchantPushNotificationService
{
    private readonly PdoPushDeviceRepository $devices;
    private readonly GetuiRestV2Client $client;

    /** @param array<string,mixed> $getuiConfig */
    public function __construct(
        \PDO $pdo,
        private readonly array $getuiConfig,
        ?GetuiRestV2Client $client = null,
    )
    {
        $this->devices = new PdoPushDeviceRepository($pdo);
        $this->client = $client ?? new GetuiRestV2Client($getuiConfig);
    }

    /**
     * @param array<string,string> $completedCidResults CID hashes mapped to terminal GeTui statuses
     * @param callable(string,string):void|null $onCidResult Persist one terminal provider result before moving on
     * @param callable():void|null $beforePush Refresh the worker's outbox lease before the external call
     * @return int number of eligible, distinct merchant CIDs (including checkpointed CIDs)
     */
    public function dispatchOrderCreated(
        int $outboxId,
        string $orderId,
        string $shopxoTablePrefix,
        array $completedCidResults = [],
        ?callable $onCidResult = null,
        ?callable $beforePush = null,
    ): int
    {
        if ($outboxId < 1 || preg_match('/^[1-9][0-9]{0,19}$/D', $orderId) !== 1) {
            throw new \RuntimeException('ORDER_CREATED event has invalid identifiers');
        }

        $devices = $this->devices->activeMerchantDevices($shopxoTablePrefix);
        if ($devices === []) {
            // No currently enabled merchant admin has a registered push device.
            return 0;
        }

        $merchantConfig = $this->getuiConfig['merchant'] ?? [];
        $merchantConfig = is_array($merchantConfig) ? $merchantConfig : [];
        $configuredAppId = (string) ($merchantConfig['app_id'] ?? '');
        if ($configuredAppId === '') {
            throw new \RuntimeException('GETUI_MERCHANT_APP_ID is required for registered merchant devices');
        }

        $eligibleCids = [];
        foreach ($devices as $device) {
            if ($device['app_id'] === $configuredAppId) {
                $eligibleCids[$device['cid']] = true;
            }
        }
        if ($eligibleCids === []) {
            throw new \RuntimeException('No merchant push bindings match GETUI_MERCHANT_APP_ID');
        }

        $terminalStatuses = ['successed_offline', 'successed_online', 'successed_ignore'];
        $completed = [];
        foreach ($completedCidResults as $cidHash => $status) {
            if (is_string($cidHash)
                && preg_match('/^[a-f0-9]{64}$/D', $cidHash) === 1
                && is_string($status)
                && in_array($status, $terminalStatuses, true)) {
                $completed[$cidHash] = true;
            }
        }

        foreach (array_keys($eligibleCids) as $cid) {
            $cidHash = hash('sha256', $cid);
            if (isset($completed[$cidHash])) {
                continue;
            }

            if ($beforePush !== null) {
                $beforePush();
            }

            // GeTui documents request_id as a unique identifier and warns that
            // duplicates can drop messages. Generate a fresh one for each CID
            // attempt; a later outbox retry will call this loop again.
            $requestId = bin2hex(random_bytes(16));
            $status = $this->client->sendNewOrder($merchantConfig, $cid, $requestId);
            if ($onCidResult !== null) {
                $onCidResult($cid, $status);
            }
        }

        return count($eligibleCids);
    }
}
