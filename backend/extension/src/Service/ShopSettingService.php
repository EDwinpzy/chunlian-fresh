<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;

/** Store operation and customer-facing presentation settings. */
final class ShopSettingService
{
    private const ROW_ID = 1;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    /** Read the current settings, including safe defaults for an empty table. */
    public function get(): array
    {
        $settingStmt = $this->pdo->prepare(
            'SELECT `shop_name`, `notice`, `phone`, `payment_qr_url`, `delivery_fee_fen`, `is_open`, '
            . '`min_lead_minutes`, `updated_at` FROM `veg_shop_setting` WHERE `id` = :id LIMIT 1'
        );
        $settingStmt->execute(['id' => self::ROW_ID]);
        $setting = $settingStmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'shop_name' => '春连鲜选',
            'notice' => '',
            'phone' => '',
            'payment_qr_url' => '',
            'delivery_fee_fen' => 0,
            'is_open' => 1,
            'min_lead_minutes' => 30,
            'updated_at' => '',
        ];

        $profileStmt = $this->pdo->prepare(
            'SELECT `shop_logo_url`, `shop_address`, `business_hours`, `shop_description`, '
            . '`delivery_description`, `home_banner_title`, `home_banner_subtitle`, `shop_slogan`, '
            . '`search_hot_words`, `home_section_title`, `updated_at` AS `updated_at_2` '
            . 'FROM `veg_shop_profile` WHERE `id` = :id LIMIT 1'
        );
        $profileStmt->execute(['id' => self::ROW_ID]);
        $profile = $profileStmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'shop_logo_url' => '',
            'shop_address' => '',
            'business_hours' => '',
            'shop_description' => '',
            'delivery_description' => '',
            'home_banner_title' => '',
            'home_banner_subtitle' => '',
            'shop_slogan' => '',
            'search_hot_words' => '[]',
            'home_section_title' => '',
            'updated_at_2' => '',
        ];

        return $this->serialize(array_merge($setting, $profile));
    }

    /** Apply a validated partial update across both settings tables atomically. */
    public function update(array $patch, string $actorId): array
    {
        $allowed = [
            'shop_name', 'notice', 'phone', 'payment_qr_url', 'delivery_fee_fen', 'is_open', 'min_lead_minutes',
            'shop_logo_url', 'shop_address', 'business_hours', 'shop_description', 'delivery_description',
            'home_banner_title', 'home_banner_subtitle', 'shop_slogan', 'search_hot_words', 'home_section_title',
        ];
        if (array_diff(array_keys($patch), $allowed) !== []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '店铺设置包含不支持的字段');
        }

        $mainChanges = [];
        $profileChanges = [];
        foreach ([
            'shop_name' => 60,
            'notice' => 255,
            'shop_logo_url' => 500,
            'shop_address' => 255,
            'business_hours' => 120,
            'shop_description' => 500,
            'delivery_description' => 500,
            'home_banner_title' => 80,
            'home_banner_subtitle' => 120,
            'shop_slogan' => 120,
            'home_section_title' => 80,
        ] as $field => $maxLength) {
            if (!array_key_exists($field, $patch)) {
                continue;
            }
            if (!is_string($patch[$field])) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, $field . ' 必须是文本');
            }
            $value = trim($patch[$field]);
            if ($field === 'shop_name' && $value === '') {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '店铺名称不能为空');
            }
            if (mb_strlen($value) > $maxLength) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, sprintf('%s 不能超过 %d 个字', $this->fieldLabel($field), $maxLength));
            }
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, $this->fieldLabel($field) . ' 含有不支持的控制字符');
            }

            if (in_array($field, ['shop_logo_url'], true)) {
                if (!$this->isImageUrl($value)) {
                    return $this->fail(RejectionCode::VALIDATION_FAILED, '请上传有效的店铺 Logo 图片');
                }
                $profileChanges[$field] = $value;
            } elseif (in_array($field, ['shop_address', 'business_hours', 'shop_description', 'delivery_description', 'home_banner_title', 'home_banner_subtitle', 'shop_slogan', 'home_section_title'], true)) {
                $profileChanges[$field] = $value;
            } else {
                $mainChanges[$field] = $value;
            }
        }

        foreach (['payment_qr_url' => '收款二维码', 'shop_logo_url' => '店铺 Logo'] as $field => $label) {
            if (!array_key_exists($field, $patch) || $field === 'shop_logo_url') {
                continue;
            }
            if (!is_string($patch[$field])) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, $label . ' 地址必须是文本');
            }
            $url = trim($patch[$field]);
            if (!$this->isImageUrl($url)) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '请上传有效的' . $label . '图片');
            }
            $mainChanges[$field] = $url;
        }

        if (array_key_exists('phone', $patch)) {
            if (!is_string($patch['phone'])) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '联系电话必须是文本');
            }
            $phone = trim($patch['phone']);
            if ($phone !== '' && preg_match('/^[0-9+\-\s]{5,20}$/', $phone) !== 1) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '联系电话格式不正确');
            }
            $mainChanges['phone'] = $phone;
        }

        if (array_key_exists('delivery_fee_fen', $patch)) {
            $fee = $patch['delivery_fee_fen'];
            if (!is_int($fee) || $fee < 0) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '配送费必须是非负整数分');
            }
            if ($fee > 100000) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '配送费过高，请确认金额单位');
            }
            $mainChanges['delivery_fee_fen'] = $fee;
        }

        if (array_key_exists('is_open', $patch)) {
            if (!is_bool($patch['is_open'])) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '营业状态必须是布尔值');
            }
            $mainChanges['is_open'] = $patch['is_open'] ? 1 : 0;
        }

        if (array_key_exists('min_lead_minutes', $patch)) {
            $minutes = $patch['min_lead_minutes'];
            if (!is_int($minutes) || $minutes < 0 || $minutes > 7 * 24 * 60) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '预约提前量必须是 0 到 10080 的整数分钟');
            }
            $mainChanges['min_lead_minutes'] = $minutes;
        }

        if (array_key_exists('search_hot_words', $patch)) {
            $words = $patch['search_hot_words'];
            if (!is_array($words) || !array_is_list($words) || count($words) > 10) {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '热门搜索词必须是最多 10 项的文本数组');
            }
            $normalized = [];
            foreach ($words as $word) {
                if (!is_string($word)) {
                    return $this->fail(RejectionCode::VALIDATION_FAILED, '每个热门搜索词都必须是文本');
                }
                $word = trim($word);
                if ($word === '' || mb_strlen($word) > 24) {
                    return $this->fail(RejectionCode::VALIDATION_FAILED, '热门搜索词不能为空且不能超过 24 个字');
                }
                if (in_array($word, $normalized, true)) {
                    return $this->fail(RejectionCode::VALIDATION_FAILED, '热门搜索词不能重复');
                }
                $normalized[] = $word;
            }
            $profileChanges['search_hot_words'] = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        if ($mainChanges === [] && $profileChanges === []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '没有需要修改的字段');
        }

        $before = $this->get();
        try {
            $this->pdo->beginTransaction();
            $this->ensureSettingRow();
            $this->ensureProfileRow();
            $this->applyChanges('veg_shop_setting', $mainChanges);
            $this->applyChanges('veg_shop_profile', $profileChanges);
            $after = $this->get();
            $this->writeAudit($actorId, $before, $after);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return $this->ok($after, '设置已保存');
    }

    /** Used by order creation to enforce the server-side closing switch. */
    public function assertOpen(): ?array
    {
        if ($this->get()['is_open'] === false) {
            return $this->fail(RejectionCode::SHOP_CLOSED, '商家暂停营业，暂不能新下单');
        }

        return null;
    }

    private function applyChanges(string $table, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $sets = [];
        $params = ['id' => self::ROW_ID];
        foreach ($changes as $field => $value) {
            $sets[] = "`{$field}` = :{$field}";
            $params[$field] = $value;
        }
        $sets[] = '`updated_at` = NOW()';
        $this->pdo->prepare(
            'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE `id` = :id'
        )->execute($params);
    }

    private function ensureSettingRow(): void
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO `veg_shop_setting` '
            . '(`id`, `shop_name`, `notice`, `phone`, `payment_qr_url`, `delivery_fee_fen`, `is_open`, `min_lead_minutes`, `updated_at`) '
            . 'VALUES (:id, :shop_name, :notice, :phone, :payment_qr_url, :fee, :is_open, :lead, NOW())'
        )->execute([
            'id' => self::ROW_ID,
            'shop_name' => '春连鲜选',
            'notice' => '',
            'phone' => '',
            'payment_qr_url' => '',
            'fee' => 0,
            'is_open' => 1,
            'lead' => 30,
        ]);
    }

    private function ensureProfileRow(): void
    {
        $this->pdo->exec(
            "INSERT IGNORE INTO `veg_shop_profile` (`id`, `search_hot_words`, `updated_at`) VALUES (1, '[]', NOW())"
        );
    }

    private function isImageUrl(string $value): bool
    {
        return strlen($value) <= 500
            && ($value === '' || preg_match('#^(https?://[^\s]+|/static/upload/[^\s]+)$#', $value) === 1);
    }

    private function fieldLabel(string $field): string
    {
        return [
            'shop_name' => '店铺名称',
            'notice' => '店铺公告',
            'shop_logo_url' => '店铺 Logo 地址',
            'shop_address' => '店铺地址',
            'business_hours' => '营业时间',
            'shop_description' => '店铺简介',
            'delivery_description' => '配送说明',
            'home_banner_title' => '首页主标题',
            'home_banner_subtitle' => '首页副标题',
            'shop_slogan' => '店铺宣传语',
            'home_section_title' => '首页商品分区标题',
        ][$field] ?? $field;
    }

    /** @param array<string,mixed> $row */
    private function serialize(array $row): array
    {
        $words = json_decode((string) ($row['search_hot_words'] ?? '[]'), true);
        if (!is_array($words) || !array_is_list($words)) {
            $words = [];
        }
        $words = array_values(array_filter($words, 'is_string'));

        $settingUpdated = (string) ($row['updated_at'] ?? '');
        $profileUpdated = (string) ($row['updated_at_2'] ?? '');

        return [
            'shop_name' => (string) ($row['shop_name'] ?? '春连鲜选'),
            'notice' => (string) ($row['notice'] ?? ''),
            'phone' => (string) ($row['phone'] ?? ''),
            'payment_qr_url' => (string) ($row['payment_qr_url'] ?? ''),
            'delivery_fee_fen' => (int) ($row['delivery_fee_fen'] ?? 0),
            'is_open' => ((int) ($row['is_open'] ?? 1)) === 1,
            'min_lead_minutes' => (int) ($row['min_lead_minutes'] ?? 30),
            'shop_logo_url' => (string) ($row['shop_logo_url'] ?? ''),
            'shop_address' => (string) ($row['shop_address'] ?? ''),
            'business_hours' => (string) ($row['business_hours'] ?? ''),
            'shop_description' => (string) ($row['shop_description'] ?? ''),
            'delivery_description' => (string) ($row['delivery_description'] ?? ''),
            'home_banner_title' => (string) ($row['home_banner_title'] ?? ''),
            'home_banner_subtitle' => (string) ($row['home_banner_subtitle'] ?? ''),
            'shop_slogan' => (string) ($row['shop_slogan'] ?? ''),
            'search_hot_words' => $words,
            'home_section_title' => (string) ($row['home_section_title'] ?? ''),
            'updated_at' => max($settingUpdated, $profileUpdated),
        ];
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private function writeAudit(string $actorId, array $before, array $after): void
    {
        $changed = [];
        foreach ($after as $key => $value) {
            if ($key === 'updated_at') {
                continue;
            }
            if (($before[$key] ?? null) !== $value) {
                $changed[$key] = ['from' => $before[$key] ?? null, 'to' => $value];
            }
        }
        if ($changed === []) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO `veg_operation_log` '
            . '(`actor_type`, `actor_id`, `order_id`, `action`, `detail_json`, `created_at`) '
            . 'VALUES (:actor_type, :actor_id, NULL, :action, :detail_json, :created_at)'
        )->execute([
            'actor_type' => 'MERCHANT',
            'actor_id' => $actorId === '' ? null : $actorId,
            'action' => 'SHOP_SETTING_UPDATE',
            'detail_json' => json_encode($changed, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function fail(RejectionCode $code, string $message): array
    {
        return ['code' => $code->value, 'message' => $message, 'data' => null];
    }
}
