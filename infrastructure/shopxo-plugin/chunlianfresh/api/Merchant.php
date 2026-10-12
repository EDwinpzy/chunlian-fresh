<?php

declare(strict_types=1);

namespace app\plugins\chunlianfresh\api;

require_once dirname(__DIR__) . '/Bootstrap.php';

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\PdoIdempotencyStore;
use Chunlian\Repository\PdoOrderRepository;
use Chunlian\Service\CatalogService;
use Chunlian\Service\IdempotencyService;
use Chunlian\Service\LedgerCalculator;
use Chunlian\Service\MerchantInsightService;
use Chunlian\Service\MerchantOrderService;
use Chunlian\Service\OrderStateMachine;
use Chunlian\Service\RevisionCalculator;
use Chunlian\Service\RevisionService;

use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\table_prefix;

/**
 * 春连鲜选 · 商家端 API 控制器。
 *
 * 【为什么只有一个 action】
 * ShopXO 的插件分发把 URL 拆成 `pluginsname / pluginscontrol / pluginsaction`
 * 三段固定结构，没有 RESTful 路径参数的概念。
 * 而客户端（Retrofit）用的是 `merchant/orders/123/deliver` 这类路径。
 *
 * 因此这里用 `dispatch` 单一入口，由它解析 `path` 参数并内部路由。
 * 客户端侧无需为框架限制做任何妥协 —— Nginx 负责把
 * `/api/v1/<path>` 重写成插件调用（见 infrastructure/nginx/chunlian.conf）。
 *
 * 【鉴权】
 * 登录校验 `shopxo_admin`（沿用 ShopXO 的 `md5($salt . $pwd)` 算法），
 * 成功后签发随机 token，只把 SHA-256 存进 `veg_merchant_session`。
 * 用自建会话表而不是 ShopXO 的 `admin.token` 单列，
 * 是为了支持多设备、可吊销、可查最后活跃时间。
 */
class Merchant
{
    /** 会话有效期（秒）：30 天 */
    private const SESSION_TTL = 2592000;

    private \PDO $pdo;

    public function __construct(private readonly array $params = [])
    {
        $this->pdo = pdo();
    }

    // =========================================================
    // 入口
    // =========================================================

    public function dispatch(array $params): array
    {
        $path = $this->resolvePath($params);
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        // ---------- 登录：唯一无需鉴权的端点 ----------
        if ($path === 'auth/login') {
            return $this->login($params);
        }

        // ---------- 鉴权 ----------
        $session = $this->authenticate();
        if ($session === null) {
            return [
                'code' => RejectionCode::FORBIDDEN->value,
                'message' => '登录已过期，请重新登录',
                'data' => null,
            ];
        }
        $actorId = (string) $session['actor_id'];

        // ---------- 路由 ----------
        // 顺序敏感：`orders/{id}/xxx` 必须排在 `orders/{id}` 之前，
        // 否则 `deliver` 会被当成订单 ID。
        if ($path === 'dashboard') {
            return $this->service()->dashboard();
        }

        if ($path === 'receivables') {
            return $this->service()->receivables();
        }

        if ($path === 'settlement/daily') {
            return $this->service()->dailySettlement($this->query('date'));
        }

        // ---------- 经营视图 ----------
        if ($path === 'stats') {
            // 默认看最近 7 天：生鲜店的经营节奏以周为单位
            $to = $this->query('to') ?? date('Y-m-d');
            $from = $this->query('from') ?? date('Y-m-d', strtotime($to . ' -6 days'));

            return $this->insight()->stats($from, $to);
        }

        if ($path === 'ledger') {
            return $this->insight()->ledgerList(
                orderId: $this->query('order_id'),
                kind: $this->query('kind'),
                page: (int) ($this->query('page') ?? 1),
                pageSize: (int) ($this->query('page_size') ?? 20),
            );
        }

        if ($path === 'notifications') {
            return $this->insight()->notifications($actorId, (int) ($this->query('limit') ?? 50));
        }

        if ($path === 'notifications/read') {
            return $this->insight()->markAllRead($actorId);
        }

        // ---------- 商品管理 ----------
        if ($path === 'goods/categories') {
            return $this->catalog()->categories();
        }

        if ($path === 'goods') {
            return $this->catalog()->listGoods(
                keyword: $this->query('keyword'),
                onSaleOnly: $this->boolQuery('on_sale_only'),
                page: (int) ($this->query('page') ?? 1),
                pageSize: (int) ($this->query('page_size') ?? 20),
            );
        }

        // goods/{id}/shelves
        if (preg_match('#^goods/(\d+)/shelves$#', $path, $m) === 1) {
            $body = $this->body();

            return $this->catalog()->setShelves(
                $m[1],
                (bool) ($body['is_shelves'] ?? false),
                $actorId
            );
        }

        // goods/{id}/sku/{skuId}
        if (preg_match('#^goods/(\d+)/sku/(\d+)$#', $path, $m) === 1) {
            $body = $this->body();

            return $this->catalog()->updateSku(
                goodsId: $m[1],
                skuId: $m[2],
                priceFen: isset($body['price_fen']) ? (int) $body['price_fen'] : null,
                inventory: isset($body['inventory']) ? (int) $body['inventory'] : null,
                actorId: $actorId,
            );
        }

        // goods/{id}
        if (preg_match('#^goods/(\d+)$#', $path, $m) === 1) {
            return $this->catalog()->goodsDetail($m[1]);
        }

        if ($path === 'orders') {
            return $this->service()->listOrders(
                status: $this->query('status'),
                uncollectedOnly: $this->boolQuery('uncollected_only'),
                keyword: $this->query('keyword'),
                page: (int) ($this->query('page') ?? 1),
                pageSize: (int) ($this->query('page_size') ?? 20),
            );
        }

        // ---------- 订单修订（商家端只读 + 补款提交） ----------
        if (preg_match('#^orders/([^/]+)/revisions$#', $path, $m) === 1) {
            return $this->revision()->history($m[1]);
        }

        if (preg_match('#^orders/([^/]+)/commit-topup$#', $path, $m) === 1) {
            return $this->revision()->commitTopup($m[1], $actorId);
        }

        // orders/{id}/{action}
        if (preg_match('#^orders/([^/]+)/([a-z-]+)$#', $path, $m) === 1) {
            $orderId = $m[1];
            $action = $m[2];
            $body = $this->body();

            // 写操作必须带幂等键，否则重试会变成重复发货/重复收款
            $idemKey = $this->idempotencyKey();
            if ($idemKey === null) {
                return $this->badRequest('写操作必须携带 Idempotency-Key（UUID v4）');
            }

            return match ($action) {
                'deliver' => $this->service()->startDelivery(
                    $orderId,
                    $actorId,
                    (int) ($body['version'] ?? 0),
                    $idemKey
                ),
                'confirm-delivery' => $this->service()->confirmDelivery(
                    $orderId,
                    $actorId,
                    (int) ($body['version'] ?? 0),
                    (bool) ($body['collected_on_delivery'] ?? false),
                    isset($body['collection_method']) ? (string) $body['collection_method'] : null,
                    isset($body['collected_fen']) ? (int) $body['collected_fen'] : null,
                    $idemKey
                ),
                'cod-receipt' => $this->service()->recordCodReceipt(
                    $orderId,
                    $actorId,
                    (int) ($body['version'] ?? 0),
                    (int) ($body['collected_fen'] ?? 0),
                    (string) ($body['collection_method'] ?? ''),
                    $idemKey
                ),
                'cancel' => $this->service()->cancel(
                    $orderId,
                    $actorId,
                    (int) ($body['version'] ?? 0),
                    isset($body['reason']) ? (string) $body['reason'] : null,
                    $idemKey
                ),
                default => $this->notFound(sprintf('未知操作：%s', $action)),
            };
        }

        // orders/{id}
        if (preg_match('#^orders/([^/]+)$#', $path, $m) === 1) {
            return $this->service()->orderDetail($m[1], $actorId);
        }

        return $this->notFound(sprintf('未知接口：%s（%s）', $path, $method));
    }

    // =========================================================
    // 鉴权
    // =========================================================

    /**
     * 商家登录。
     *
     * 密码校验沿用 ShopXO 的算法：`md5($salt . trim($pwd))`。
     * 不自己发明算法 —— 否则后台改的密码与 App 登录会对不上。
     */
    private function login(array $params): array
    {
        $body = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $deviceId = trim((string) ($body['device_id'] ?? ''));

        if ($username === '' || $password === '') {
            return $this->badRequest('账号与密码必填');
        }

        $stmt = $this->pdo->prepare(
            sprintf(
                'SELECT `id`, `username`, `login_pwd`, `login_salt` FROM `%sadmin` '
                . 'WHERE `username` = :u AND `status` = 0 LIMIT 1',
                table_prefix()
            )
        );
        $stmt->execute(['u' => $username]);
        $admin = $stmt->fetch(\PDO::FETCH_ASSOC);

        // 统一失败文案，不区分「账号不存在」与「密码错误」——
        // 区分会帮助攻击者枚举有效账号
        if ($admin === false) {
            $this->sleepToBluntBruteForce();

            return $this->unauthorized('账号或密码错误');
        }

        if (!hash_equals((string) $admin['login_pwd'], md5((string) $admin['login_salt'] . trim($password)))) {
            $this->sleepToBluntBruteForce();

            return $this->unauthorized('账号或密码错误');
        }

        $actorId = (string) $admin['id'];
        $token = bin2hex(random_bytes(32));   // 256 bit 随机
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_TTL);

        $stmt = $this->pdo->prepare(
            'INSERT INTO `veg_merchant_session` '
            . '(`actor_id`, `token_hash`, `device_id`, `device_name`, `last_seen_at`, `expires_at`, `created_at`) '
            . 'VALUES (:actor_id, :token_hash, :device_id, :device_name, :last_seen_at, :expires_at, :created_at)'
        );
        $stmt->execute([
            'actor_id' => $actorId,
            // 只存哈希：数据库泄露时攻击者无法直接拿它冒充商家
            'token_hash' => hash('sha256', $token),
            'device_id' => $deviceId === '' ? 'unknown' : $deviceId,
            'device_name' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 128),
            'last_seen_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        return $this->ok([
            'token' => $token,
            'expires_at' => $expiresAt,
            'merchant_id' => $actorId,
            'shop_name' => '春连鲜选',
        ]);
    }

    /** @return array{actor_id:int}|null */
    private function authenticate(): ?array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string) ($headers['Authorization'] ?? '');
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT `id`, `actor_id` FROM `veg_merchant_session` '
            . 'WHERE `token_hash` = :h AND `revoked_at` IS NULL AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => hash('sha256', trim($m[1]))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // 更新最后活跃时间；失败不影响本次请求
        try {
            $this->pdo->prepare('UPDATE `veg_merchant_session` SET `last_seen_at` = NOW() WHERE `id` = :id')
                ->execute(['id' => $row['id']]);
        } catch (\Throwable) {
            // 忽略
        }

        return ['actor_id' => (int) $row['actor_id']];
    }

    /** 登录失败时轻微延迟，增加暴力破解成本。 */
    private function sleepToBluntBruteForce(): void
    {
        usleep(random_int(200_000, 500_000));
    }

    // =========================================================
    // 服务装配
    // =========================================================

    private function service(): MerchantOrderService
    {
        static $service = null;
        if ($service instanceof MerchantOrderService) {
            return $service;
        }

        $pdo = $this->pdo;
        $prefix = table_prefix();

        $service = new MerchantOrderService(
            pdo: $pdo,
            orders: $this->orders(),
            stateMachine: new OrderStateMachine(),
            idempotency: new IdempotencyService(new PdoIdempotencyStore($pdo)),
            ledger: new LedgerCalculator(),
            tablePrefix: $prefix,
        );

        return $service;
    }

    private function orders(): PdoOrderRepository
    {
        static $repo = null;
        if ($repo instanceof PdoOrderRepository) {
            return $repo;
        }

        $repo = new PdoOrderRepository($this->pdo, table_prefix());

        return $repo;
    }

    private function catalog(): CatalogService
    {
        static $svc = null;
        if ($svc instanceof CatalogService) {
            return $svc;
        }

        $svc = new CatalogService($this->pdo, table_prefix());

        return $svc;
    }

    private function insight(): MerchantInsightService
    {
        static $svc = null;
        if ($svc instanceof MerchantInsightService) {
            return $svc;
        }

        $svc = new MerchantInsightService($this->pdo, new LedgerCalculator(), table_prefix());

        return $svc;
    }

    private function revision(): RevisionService
    {
        static $svc = null;
        if ($svc instanceof RevisionService) {
            return $svc;
        }

        $svc = new RevisionService(
            $this->pdo,
            $this->orders(),
            new RevisionCalculator(),
            table_prefix()
        );

        return $svc;
    }

    // =========================================================
    // 请求解析
    // =========================================================

    /**
     * 解析业务路径。
     *
     * 支持两种入口，便于分别用于生产与调试：
     *   1. `path=merchant/orders/123/deliver`（Nginx 重写后）
     *   2. `pluginsaction=orders`（ShopXO 原生插件调用，无子路径）
     */
    private function resolvePath(array $params): string
    {
        $path = (string) ($params['path'] ?? $_GET['path'] ?? '');
        $path = trim($path, '/');

        // 去掉可选的 merchant/ 前缀，让 Nginx 规则可以自由选择是否保留
        if (str_starts_with($path, 'merchant/')) {
            $path = substr($path, strlen('merchant/'));
        }

        return $path;
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            // 兼容 form-urlencoded 提交
            return is_array($_POST) ? $_POST : [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return is_array($_POST) ? $_POST : [];
    }

    private function query(string $key): ?string
    {
        $v = $_GET[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }

        return is_scalar($v) ? (string) $v : null;
    }

    private function boolQuery(string $key): ?bool
    {
        $v = $this->query($key);
        if ($v === null) {
            return null;
        }

        return in_array(strtolower($v), ['1', 'true', 'yes'], true);
    }

    /**
     * 读取并校验幂等键。
     *
     * 必须是 UUID v4 —— 客户端用 UUID v4 生成，服务端拒绝其他格式
     * 可以尽早发现「客户端把幂等键写成固定字符串」这类错误
     * （那会让第二次不同的操作被当成重复请求而静默忽略）。
     */
    private function idempotencyKey(): ?string
    {
        $key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($key === '') {
            return null;
        }

        return IdempotencyService::isValidKey($key) ? trim($key) : null;
    }

    // =========================================================
    // 信封
    // =========================================================

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function badRequest(string $message): array
    {
        return ['code' => RejectionCode::VALIDATION_FAILED->value, 'message' => $message, 'data' => null];
    }

    private function unauthorized(string $message): array
    {
        return ['code' => RejectionCode::FORBIDDEN->value, 'message' => $message, 'data' => null];
    }

    private function notFound(string $message): array
    {
        return ['code' => RejectionCode::NOT_FOUND->value, 'message' => $message, 'data' => null];
    }
}
