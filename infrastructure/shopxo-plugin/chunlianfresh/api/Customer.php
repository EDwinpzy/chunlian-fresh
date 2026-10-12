<?php

declare(strict_types=1);

namespace app\plugins\chunlianfresh\api;

require_once dirname(__DIR__) . '/Bootstrap.php';

use app\service\UserService;

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\PdoOrderRepository;
use Chunlian\Service\RevisionCalculator;
use Chunlian\Service\RevisionService;

use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\table_prefix;

/**
 * 春连鲜选 · 顾客端 API 控制器。
 *
 * 【当前只做订单修订】顾客端其余功能（商品浏览、购物车、下单、支付）
 * 全部走 ShopXO 原生接口 —— 那些能力 ShopXO 已经做得完整且经过验证，
 * 重写一遍只会引入新的 bug 与不一致。
 *
 * 本项目在顾客侧真正新增的能力只有一件：**配送前的订单修订**。
 * ShopXO 原生不支持「已付款订单在发货前自由增删商品并结算差价」，
 * 而这正是生鲜场景的高频需求（顾客早上买菜，中午想加两个菜）。
 *
 * 【鉴权】
 * 复用 ShopXO 的用户 token 机制：`UserService::LoginUserInfo()` 会从
 * 请求参数 `token` 读取并校验。不自己发明一套 —— 否则顾客在原生接口
 * 与扩展接口之间会出现「登录状态不一致」的诡异问题。
 */
class Customer
{
    private \PDO $pdo;

    public function __construct(private readonly array $params = [])
    {
        $this->pdo = pdo();
    }

    public function dispatch(array $params): array
    {
        $path = $this->resolvePath($params);

        // ---------- 鉴权 ----------
        $user = $this->currentUser();
        if ($user === null) {
            return [
                'code' => RejectionCode::FORBIDDEN->value,
                'message' => '请先登录',
                'data' => null,
            ];
        }
        $userId = (string) $user['id'];

        // orders/{id}/revision/preview
        if (preg_match('#^orders/([^/]+)/revision/preview$#', $path, $m) === 1) {
            if (($deny = $this->assertOwnership($m[1], $userId)) !== null) {
                return $deny;
            }

            return $this->revision()->preview($m[1], $this->items());
        }

        // orders/{id}/revision
        if (preg_match('#^orders/([^/]+)/revision$#', $path, $m) === 1) {
            if (($deny = $this->assertOwnership($m[1], $userId)) !== null) {
                return $deny;
            }

            return $this->revision()->submit($m[1], $this->items(), $userId, 'CUSTOMER');
        }

        // orders/{id}/revisions（顾客看自己的改单记录）
        if (preg_match('#^orders/([^/]+)/revisions$#', $path, $m) === 1) {
            if (($deny = $this->assertOwnership($m[1], $userId)) !== null) {
                return $deny;
            }

            return $this->revision()->history($m[1]);
        }

        return [
            'code' => RejectionCode::NOT_FOUND->value,
            'message' => sprintf('未知接口：%s', $path),
            'data' => null,
        ];
    }

    // =========================================================
    // 鉴权
    // =========================================================

    /**
     * 取当前登录顾客。
     *
     * 【归属校验不在这里做】
     * `RevisionService` 会按 orderId 操作订单，但**不校验订单是否属于
     * 当前顾客** —— 那是本层的职责。若漏掉，任何登录用户都能改别人的订单。
     * 因此这里必须显式核对 `user_id`。
     *
     * @return array{id:int}|null
     */
    private function currentUser(): ?array
    {
        try {
            $info = UserService::LoginUserInfo();
        } catch (\Throwable) {
            return null;
        }

        if (empty($info) || empty($info['id'])) {
            return null;
        }

        return ['id' => (int) $info['id']];
    }

    /**
     * 校验订单归属当前顾客。
     *
     * 【为什么单独抽出来而不是塞进 dispatch】
     * 每个涉及具体订单的分支都必须调用它。放在一处便于审查
     * 「有没有哪个分支忘了校验」—— 这是最容易漏的安全检查。
     */
    private function assertOwnership(string $orderId, string $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            sprintf('SELECT `id`, `user_id` FROM `%sorder` WHERE `id` = :id LIMIT 1', table_prefix())
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['code' => RejectionCode::NOT_FOUND->value, 'message' => '订单不存在', 'data' => null];
        }

        if ((string) $row['user_id'] !== $userId) {
            // 不区分「不存在」与「不属于你」会泄露订单是否存在；
            // 但这里已经确认存在，因此统一返回「无权访问」即可
            return ['code' => RejectionCode::FORBIDDEN->value, 'message' => '无权访问该订单', 'data' => null];
        }

        return null;
    }

    // =========================================================
    // 请求解析
    // =========================================================

    private function resolvePath(array $params): string
    {
        $path = (string) ($params['path'] ?? $_GET['path'] ?? '');

        return trim($path, '/');
    }

    /** 从请求体读取商品明细。 */
    private function items(): array
    {
        $body = $this->body();
        $raw = $body['items'] ?? [];

        if (!is_array($raw)) {
            return [];
        }

        $items = [];
        foreach ($raw as $it) {
            if (!is_array($it)) {
                continue;
            }
            $items[] = [
                'sku_id' => (string) ($it['sku_id'] ?? ''),
                'qty' => (int) ($it['qty'] ?? 0),
            ];
        }

        return $items;
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return is_array($_POST) ? $_POST : [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return is_array($_POST) ? $_POST : [];
    }

    private function revision(): RevisionService
    {
        static $svc = null;
        if ($svc instanceof RevisionService) {
            return $svc;
        }

        $svc = new RevisionService(
            $this->pdo,
            new PdoOrderRepository($this->pdo, table_prefix()),
            new RevisionCalculator(),
            table_prefix()
        );

        return $svc;
    }
}
