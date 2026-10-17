<?php

declare(strict_types=1);

namespace Chunlian\Repository;

/** Persistent bindings between an authenticated account and a Getui CID. */
final class PdoPushDeviceRepository
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function bind(
        string $actorType,
        string $actorId,
        string $appId,
        string $installationId,
        string $cid,
    ): void {
        $insert = 'INSERT INTO `veg_push_device` '
            . '(`actor_type`, `actor_id`, `app_id`, `installation_id`, `cid`, `revoked_at`, `created_at`, `updated_at`) '
            . 'VALUES (:actor_type, :actor_id, :app_id, :installation_id, :cid, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) ';
        $upsert = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite'
            ? 'ON CONFLICT(`app_id`, `installation_id`) DO UPDATE SET '
                . '`actor_type` = excluded.`actor_type`, `actor_id` = excluded.`actor_id`, '
                . '`cid` = excluded.`cid`, `revoked_at` = NULL, `updated_at` = CURRENT_TIMESTAMP'
            : 'ON DUPLICATE KEY UPDATE '
                . '`actor_type` = VALUES(`actor_type`), `actor_id` = VALUES(`actor_id`), '
                . '`cid` = VALUES(`cid`), `revoked_at` = NULL, `updated_at` = CURRENT_TIMESTAMP';
        $stmt = $this->pdo->prepare($insert . $upsert);
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'app_id' => $appId,
            'installation_id' => $installationId,
            'cid' => $cid,
        ]);
    }

    public function revoke(string $actorType, string $actorId, string $installationId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `veg_push_device` SET `revoked_at` = COALESCE(`revoked_at`, CURRENT_TIMESTAMP), '
            . '`updated_at` = CURRENT_TIMESTAMP '
            . 'WHERE `actor_type` = :actor_type AND `actor_id` = :actor_id '
            . 'AND `installation_id` = :installation_id'
        );
        $stmt->execute([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'installation_id' => $installationId,
        ]);
    }

    /**
     * Return devices belonging to currently enabled ShopXO admins.
     * The merchant notification feed is currently shop-wide and has no per-shop
     * order key, so its recipient boundary is the same enabled-admin set.
     *
     * @return list<array{actor_id:string,app_id:string,installation_id:string,cid:string}>
     */
    public function activeMerchantDevices(string $shopxoTablePrefix): array
    {
        if (preg_match('/^[a-zA-Z0-9_]*$/', $shopxoTablePrefix) !== 1) {
            throw new \InvalidArgumentException('Invalid ShopXO table prefix');
        }

        $stmt = $this->pdo->query(
            'SELECT d.`actor_id`, d.`app_id`, d.`installation_id`, d.`cid` '
            . 'FROM `veg_push_device` d '
            . 'INNER JOIN `' . $shopxoTablePrefix . 'admin` a ON a.`id` = d.`actor_id` '
            . 'WHERE d.`actor_type` = \'MERCHANT\' AND d.`revoked_at` IS NULL AND a.`status` = 0 '
            . 'ORDER BY d.`id` ASC'
        );

        return array_map(static fn (array $row): array => [
            'actor_id' => (string) $row['actor_id'],
            'app_id' => (string) $row['app_id'],
            'installation_id' => (string) $row['installation_id'],
            'cid' => (string) $row['cid'],
        ], $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }
}
