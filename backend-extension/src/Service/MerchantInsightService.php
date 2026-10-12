<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\LedgerKind;
use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\OrderRowMapper;

/**
 * 商家端经营视图：营业统计、资金流水、通知中心。
 *
 * 【统计口径红线 —— 实收与应收绝不相加】
 *   实收 = 已实际到账的钱（在线支付成功 + COD 已核销 − 已成功退款）
 *   应收 = 已送达但尚未收到的 COD 货款
 *
 * 这两者语义完全不同：前者是已经进兜的钱，后者是别人欠你的钱。
 * 把它们相加成一个「今日金额」，店主会误以为账上有那么多现金 ——
 * 这是本项目最刻意要避免的误导，因此所有输出都把两者分开。
 *
 * 【为什么只有成功流水计入实收】
 * 退款是异步的：受理 ≠ 到账。把 PROCESSING 的退款计进去会让实收虚低，
 * 等它失败回滚后又虚高，对账时反复跳数。因此只统计 SUCCESS。
 */
final class MerchantInsightService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly LedgerCalculator $ledger,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
    }

    // =========================================================
    // 营业统计
    // =========================================================

    /**
     * 区间营业统计。
     *
     * @param string $from 起始日（含），Y-m-d
     * @param string $to   结束日（含），Y-m-d
     */
    public function stats(string $from, string $to): array
    {
        if (!$this->isValidDate($from) || !$this->isValidDate($to)) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '日期格式应为 YYYY-MM-DD');
        }
        if ($from > $to) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '起始日期不能晚于结束日期');
        }

        $stmt = $this->pdo->prepare(
            'SELECT `kind`, `amount_fen`, `status`, `occurred_at` FROM `veg_payment_ledger` '
            . 'WHERE DATE(`occurred_at`) BETWEEN :f AND :t ORDER BY `occurred_at` ASC, `id` ASC'
        );
        $stmt->execute(['f' => $from, 't' => $to]);
        $entries = $this->normalize($stmt->fetchAll(\PDO::FETCH_ASSOC));

        $summary = $this->ledger->dailySummary($entries);

        // 逐日趋势：店主关心「哪几天生意好」，需要按天拆分
        $byDay = [];
        foreach ($entries as $e) {
            if ($e['status'] !== 'SUCCESS') {
                continue;
            }
            $day = substr($e['occurred_at'], 0, 10);
            $kind = LedgerKind::from($e['kind']);
            $amount = (int) $e['amount_fen'];
            if ($kind === LedgerKind::WX_REFUND && $amount > 0) {
                $amount = -$amount;
            }
            $byDay[$day] = ($byDay[$day] ?? 0) + $amount;
        }
        ksort($byDay);

        // 订单量（按订单日，不按资金日 —— 两个指标口径不同，不混算）
        $p = $this->tablePrefix;
        $orderStmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total, "
            . "SUM(`is_under_line` = 1) AS cod_count "
            . "FROM `{$p}order` "
            . "WHERE `is_delete_time` = 0 AND DATE(FROM_UNIXTIME(`add_time`)) BETWEEN :f AND :t"
        );
        $orderStmt->execute(['f' => $from, 't' => $to]);
        $orderRow = $orderStmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        // 未收款（当前时点，不是区间 —— 明确标注避免误读为「区间应收」）
        $receivable = $this->receivableNow();

        return $this->ok([
            'from' => $from,
            'to' => $to,

            // ---- 实收（分列，不相加） ----
            'received_wechat_fen' => (int) $summary['online_fen'],
            'received_cod_fen' => (int) $summary['cod_fen'],
            'refunded_fen' => (int) $summary['refund_fen'],
            'received_total_fen' => (int) $summary['received_fen'],

            // ---- 应收（独立口径，当前时点） ----
            'receivable_fen' => $receivable['fen'],
            'receivable_count' => $receivable['count'],

            // ---- 订单量（按订单日） ----
            'order_count' => (int) ($orderRow['total'] ?? 0),
            'cod_order_count' => (int) ($orderRow['cod_count'] ?? 0),

            // ---- 逐日趋势 ----
            'daily' => array_map(
                static fn (string $d, int $fen): array => ['date' => $d, 'received_fen' => $fen],
                array_keys($byDay),
                array_values($byDay)
            ),
        ]);
    }

    // =========================================================
    // 资金流水
    // =========================================================

    /**
     * 资金流水列表（只读审计）。
     *
     * 流水**不允许删除或修改** —— 它是账目的原始记录。
     * 需要纠正时只能追加一条反向流水，这样账面上能看出「发生了什么」。
     */
    public function ledgerList(?string $orderId, ?string $kind, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $where = ['1 = 1'];
        $params = [];

        if ($orderId !== null && $orderId !== '') {
            $where[] = 'l.`order_id` = :oid';
            $params['oid'] = $orderId;
        }
        if ($kind !== null && $kind !== '' && LedgerKind::tryFrom($kind) !== null) {
            $where[] = 'l.`kind` = :kind';
            $params['kind'] = $kind;
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) AS c FROM `veg_payment_ledger` l WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) ($countStmt->fetch(\PDO::FETCH_ASSOC)['c'] ?? 0);

        $offset = ($page - 1) * $pageSize;
        $stmt = $this->pdo->prepare(
            "SELECT l.`id`, l.`order_id`, l.`kind`, l.`amount_fen`, l.`status`, l.`business_no`, "
            . "l.`collection_method`, l.`occurred_at`, o.`order_no` "
            . "FROM `veg_payment_ledger` l "
            . "LEFT JOIN `{$this->tablePrefix}order` o ON o.`id` = l.`order_id` "
            . "WHERE {$whereSql} "
            // 最近的在前：对账时先看今天
            . "ORDER BY l.`occurred_at` DESC, l.`id` DESC LIMIT {$pageSize} OFFSET {$offset}"
        );
        $stmt->execute($params);

        $list = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $amount = (int) $r['amount_fen'];
            $kindEnum = LedgerKind::tryFrom((string) $r['kind']);

            $list[] = [
                'id' => (string) $r['id'],
                'order_id' => (string) $r['order_id'],
                'order_no' => (string) ($r['order_no'] ?? ''),
                'kind' => (string) $r['kind'],
                'kind_label' => $this->kindLabel($kindEnum),
                // 入账为正、退款为负，符号在服务端统一好，客户端不再判断
                'amount_fen' => $amount,
                'is_inflow' => $amount >= 0,
                'status' => (string) $r['status'],
                'status_label' => $this->statusLabel((string) $r['status']),
                'business_no' => (string) $r['business_no'],
                'collection_method' => $r['collection_method'] === null ? null : (string) $r['collection_method'],
                'occurred_at' => (string) $r['occurred_at'],
            ];
        }

        return $this->ok([
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            // 汇总同样分列，不给「净额」以外的合并数字
            'summary' => $this->ledgerSummary($whereSql, $params),
        ]);
    }

    // =========================================================
    // 通知中心
    // =========================================================

    /**
     * 通知列表。
     *
     * 通知**不是独立数据**，而是对既有业务事件的视图（见迁移文件里的说明）。
     * 因此这里读 `veg_outbox_event`，配合一张「读到哪儿了」的游标表算未读数。
     */
    public function notifications(string $actorId, int $limit = 50): array
    {
        $limit = min(200, max(1, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT `id`, `event_type`, `entity_id`, `payload`, `status`, `created_at` '
            . 'FROM `veg_outbox_event` ORDER BY `id` DESC LIMIT ' . $limit
        );
        $stmt->execute();

        $lastRead = $this->lastReadEventId($actorId);

        $list = [];
        $unread = 0;
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $id = (int) $r['id'];
            $payload = json_decode((string) $r['payload'], true);
            $isUnread = $id > $lastRead;
            if ($isUnread) {
                $unread++;
            }

            $list[] = [
                'id' => (string) $id,
                'event_type' => (string) $r['event_type'],
                'title' => $this->eventTitle((string) $r['event_type']),
                'body' => $this->eventBody((string) $r['event_type'], is_array($payload) ? $payload : []),
                'order_id' => $r['entity_id'] === null ? null : (string) $r['entity_id'],
                'level' => $this->eventLevel((string) $r['event_type']),
                'is_unread' => $isUnread,
                'created_at' => (string) $r['created_at'],
            ];
        }

        return $this->ok([
            'list' => $list,
            'total' => count($list),
            'unread_count' => $unread,
        ]);
    }

    /** 标记全部已读（把游标推到最新事件）。 */
    public function markAllRead(string $actorId): array
    {
        $maxId = (int) $this->pdo->query('SELECT COALESCE(MAX(`id`), 0) FROM `veg_outbox_event`')->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_notification_read` (`actor_id`, `last_event_id`, `updated_at`) '
            . 'VALUES (:aid, :lid, NOW()) '
            . 'ON DUPLICATE KEY UPDATE `last_event_id` = GREATEST(`last_event_id`, :lid2), `updated_at` = NOW()'
        );
        // ON DUPLICATE KEY 下同一个占位符不能复用，因此用两个名字
        $stmt->execute(['aid' => $actorId, 'lid' => $maxId, 'lid2' => $maxId]);

        return $this->ok(['last_event_id' => $maxId], '已全部标记为已读');
    }

    // =========================================================
    // 内部
    // =========================================================

    private function lastReadEventId(string $actorId): int
    {
        $stmt = $this->pdo->prepare('SELECT `last_event_id` FROM `veg_notification_read` WHERE `actor_id` = :aid LIMIT 1');
        $stmt->execute(['aid' => $actorId]);
        $v = $stmt->fetchColumn();

        return $v === false ? 0 : (int) $v;
    }

    /** @return array{fen:int,count:int} */
    private function receivableNow(): array
    {
        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(o.`total_price` - o.`pay_price`), 0) AS fen, COUNT(*) AS c "
            . "FROM `veg_order_delivery` d "
            . "INNER JOIN `{$p}order` o ON o.`id` = d.`order_id` "
            . "WHERE d.`payment_method` = 'COD' AND d.`fulfillment` = 'DELIVERED' AND d.`payment_state` = 'UNPAID'"
        );
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        return [
            'fen' => OrderRowMapper::decimalToFen($row['fen'] ?? 0),
            'count' => (int) ($row['c'] ?? 0),
        ];
    }

    /**
     * 流水汇总。
     *
     * 【为什么用 SUM(CASE WHEN) 而不是多次查询】
     * 多次查询会读到不同时刻的数据快照，在并发写入时出现
     * 「入账合计 + 退款合计 ≠ 净额」的诡异结果，对账时极难解释。
     * 一条语句保证同一快照。
     *
     * @param array<string,mixed> $params
     */
    private function ledgerSummary(string $whereSql, array $params): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT "
            . "COALESCE(SUM(CASE WHEN l.`status` = 'SUCCESS' AND l.`amount_fen` > 0 THEN l.`amount_fen` ELSE 0 END), 0) AS inflow, "
            . "COALESCE(SUM(CASE WHEN l.`status` = 'SUCCESS' AND l.`amount_fen` < 0 THEN l.`amount_fen` ELSE 0 END), 0) AS outflow, "
            . "COALESCE(SUM(CASE WHEN l.`status` IN ('PENDING','PROCESSING') THEN 1 ELSE 0 END), 0) AS in_flight_count "
            . "FROM `veg_payment_ledger` l WHERE {$whereSql}"
        );
        $stmt->execute($params);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];

        $inflow = (int) ($row['inflow'] ?? 0);
        $outflow = (int) ($row['outflow'] ?? 0);

        return [
            'inflow_fen' => $inflow,
            'outflow_fen' => $outflow,
            'net_fen' => $inflow + $outflow,
            'in_flight_count' => (int) ($row['in_flight_count'] ?? 0),
        ];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function normalize(array $rows): array
    {
        return array_map(static fn (array $r): array => [
            'kind' => (string) $r['kind'],
            'amount_fen' => (int) $r['amount_fen'],
            'status' => (string) $r['status'],
            'occurred_at' => (string) $r['occurred_at'],
        ], $rows);
    }

    private function kindLabel(?LedgerKind $kind): string
    {
        return match ($kind) {
            LedgerKind::WX_PAY => '微信支付',
            LedgerKind::TOPUP => '改单补款',
            LedgerKind::WX_REFUND => '退款',
            LedgerKind::COD_RECEIPT => '货到付款收款',
            null => '其他',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'PENDING' => '待处理',
            'PROCESSING' => '处理中',
            'SUCCESS' => '已成功',
            'FAILED' => '已失败',
            'CLOSED' => '已关闭',
            default => $status,
        };
    }

    private function eventTitle(string $type): string
    {
        return match ($type) {
            'ORDER_CREATED' => '新订单',
            'ORDER_DISPATCHED' => '已开始配送',
            'ORDER_DELIVERED' => '已确认送达',
            'ORDER_CANCELLED' => '订单已取消',
            'ORDER_REVISED' => '顾客修改了订单',
            'COD_RECEIPT_RECORDED' => '已补记收款',
            'REFUND_QUERY' => '退款处理中',
            'REFUND_NEEDS_ATTENTION' => '退款异常，需人工处理',
            'PAYMENT_VERIFY' => '支付待核对',
            default => '订单动态',
        };
    }

    private function eventBody(string $type, array $payload): string
    {
        $message = (string) ($payload['message'] ?? '');

        return match ($type) {
            'ORDER_REVISED' => $message !== ''
                ? $message
                : sprintf('差价 %s 元', number_format(abs((int) ($payload['delta_fen'] ?? 0)) / 100, 2, '.', '')),
            'REFUND_QUERY' => sprintf('退款 %s 元正在处理', number_format(abs((int) ($payload['refund_fen'] ?? 0)) / 100, 2, '.', '')),
            default => $message,
        };
    }

    /** 通知级别：异常类需要醒目，普通动态不打扰。 */
    private function eventLevel(string $type): string
    {
        return match ($type) {
            'REFUND_NEEDS_ATTENTION' => 'danger',
            'ORDER_CREATED', 'ORDER_REVISED', 'ORDER_CANCELLED' => 'warning',
            default => 'normal',
        };
    }

    private function isValidDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
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
