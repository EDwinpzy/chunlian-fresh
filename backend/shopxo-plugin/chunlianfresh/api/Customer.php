<?php

declare(strict_types=1);

namespace app\plugins\chunlianfresh\api;

require_once dirname(__DIR__) . '/Bootstrap.php';

use app\service\UserService;

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\PdoIdempotencyStore;
use Chunlian\Repository\PdoOrderRepository;
use Chunlian\Repository\PdoPushDeviceRepository;
use Chunlian\Service\CustomerAuthService;
use Chunlian\Service\CustomerCatalogService;
use Chunlian\Service\CustomerOrderService;
use Chunlian\Service\IdempotencyService;
use Chunlian\Service\OrderStateMachine;
use Chunlian\Service\PushDeviceService;
use Chunlian\Service\RevisionCalculator;
use Chunlian\Service\RevisionService;
use Chunlian\Service\ShopSettingService;

use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\extension_root;
use function app\plugins\chunlianfresh\table_prefix;

/**
 * 春连鲜选 · 顾客端 API 控制器（网页版 + 安卓版）。
 *
 * 【为什么顾客端现在自己实现了目录/登录/下单】
 * 最初的设计是「顾客端只做订单修订，其余全走 ShopXO 原生接口」。
 * 但在做网页版与安卓版时，原生接口暴露出三个绕不过去的问题：
 *   1. 金额是「元」字符串，与本项目其余接口（整数分）口径不一致，
 *      两端来回换算极易出错；
 *   2. 原生下单**不写 `veg_order_delivery` 扩展行**，而履约/支付/收货/修订
 *      四维状态正是本项目的核心不变量 —— 缺了扩展行，商家端的
 *      「开始配送 / 确认送达 / COD 收款」全部无从判断；
 *   3. 原生登录是 `shopxo_user.token` 单列，手机与浏览器会互相挤下线。
 * 因此这里自建了目录、会话、地址与下单四块能力，直接读写 ShopXO 的表，
 * 保证后台仍能看到会员与订单，同时四维状态从建单第一刻起就是完整的。
 *
 * 【订单修订仍然是独立的一块】
 * 已付款订单在发货前增删商品会牵动补款/部分退款/库存/并发版本，
 * 那是本项目真正的新开发模块（`RevisionService`），
 * 不复用 ShopXO 的任何逻辑。
 */
class Customer
{
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

        // ---------------- 公开接口（无需登录） ----------------
        if ($path === 'shop') {
            return $this->ok($this->setting()->get());
        }

        if ($path === 'catalog/categories') {
            return $this->catalog()->categories();
        }

        if ($path === 'catalog/goods') {
            return $this->catalog()->goodsList(
                categoryId: $this->query('category_id'),
                keyword: $this->query('keyword'),
                sort: $this->query('sort'),
                page: (int) ($this->query('page') ?? 1),
                pageSize: (int) ($this->query('page_size') ?? 20),
                ids: $this->query('ids'),
            );
        }

        if (preg_match('#^catalog/goods/(\d+)$#', $path, $m) === 1) {
            return $this->catalog()->goodsDetail($m[1]);
        }

        if ($path === 'auth/register' && $method === 'POST') {
            $body = $this->body();

            return $this->auth()->register(
                (string) ($body['phone'] ?? ''),
                (string) ($body['password'] ?? ''),
                (string) ($body['nickname'] ?? ''),
            );
        }

        if ($path === 'auth/login' && $method === 'POST') {
            $body = $this->body();

            return $this->auth()->login(
                (string) ($body['phone'] ?? ''),
                (string) ($body['password'] ?? ''),
            );
        }

        // ---------------- 以下均需登录 ----------------
        $token = $this->bearerToken();

        if ($path === 'auth/logout' && $method === 'POST') {
            return $this->auth()->logout($token);
        }

        $userId = $this->currentUserId($token);
        if ($userId === null) {
            return [
                'code' => RejectionCode::FORBIDDEN->value,
                'message' => '请先登录',
                'data' => null,
            ];
        }

        if ($path === 'auth/me') {
            return $this->auth()->profile($userId);
        }

        if ($path === 'auth/profile' && ($method === 'PATCH' || $method === 'POST')) {
            return $this->auth()->updateProfile($userId, $this->body());
        }

        if ($path === 'push-devices' && $method === 'POST') {
            return $this->pushDevices()->bind('CUSTOMER', (string) $userId, $this->body());
        }
        if (preg_match('#^push-devices/([A-Za-z0-9._:-]{8,128})$#', $path, $m) === 1 && $method === 'DELETE') {
            return $this->pushDevices()->revoke('CUSTOMER', (string) $userId, $m[1]);
        }

        // ---------------- 收货地址 ----------------
        if ($path === 'addresses') {
            return $method === 'POST'
                ? $this->orders()->saveAddress($userId, $this->body())
                : $this->orders()->addresses($userId);
        }

        if (preg_match('#^addresses/(\d+)/default$#', $path, $m) === 1 && $method === 'POST') {
            return $this->orders()->setDefaultAddress($userId, $m[1]);
        }

        if (preg_match('#^addresses/(\d+)$#', $path, $m) === 1 && $method === 'DELETE') {
            return $this->orders()->deleteAddress($userId, $m[1]);
        }

        // ---------------- 下单 ----------------
        if ($path === 'orders/preview' && $method === 'POST') {
            return $this->orders()->preview($this->body());
        }

        if ($path === 'orders') {
            if ($method === 'POST') {
                $idem = $this->idempotencyKey();
                if ($idem === null) {
                    return $this->badRequest('写操作必须携带 Idempotency-Key（UUID v4）');
                }

                return $this->orders()->create($userId, $this->body(), $idem);
            }

            return $this->orders()->listOrders(
                $userId,
                $this->query('status'),
                (int) ($this->query('page') ?? 1),
                (int) ($this->query('page_size') ?? 20),
                $this->query('keyword'),
            );
        }

        // ---------------- 订单修订（顺序敏感：必须排在 orders/{id} 之前） ----------------
        if (preg_match('#^orders/(\d+)/revision/preview$#', $path, $m) === 1) {
            if (($deny = $this->assertOwnership($m[1], $userId)) !== null) {
                return $deny;
            }

            return $this->revision()->preview($m[1], $this->items());
        }

        if (preg_match('#^orders/(\d+)/revision$#', $path, $m) === 1) {
            if (($deny = $this->assertOwnership($m[1], $userId)) !== null) {
                return $deny;
            }

            $idempotencyKey = $this->idempotencyKey();
            if ($idempotencyKey === null) {
                return $this->badRequest('改单必须携带 Idempotency-Key（UUID v4）');
            }

            $body = $this->body();
            $version = $body['client_version'] ?? $body['version'] ?? null;
            if (!is_scalar($version) || preg_match('/^[1-9]\d*$/', (string) $version) !== 1) {
                return $this->badRequest('改单必须携带有效的 client_version');
            }

            return $this->revision()->submit(
                orderId: $m[1],
                items: $this->items($body),
                actorId: (string) $userId,
                actorType: 'CUSTOMER',
                clientVersion: (int) $version,
                idempotencyKey: $idempotencyKey,
            );
        }

        if (preg_match('#^orders/(\d+)/revisions$#', $path, $m) === 1) {
            if (($deny = $this->assertOwnership($m[1], $userId)) !== null) {
                return $deny;
            }

            return $this->revision()->history($m[1]);
        }

        // ---------------- 订单信息修改（不涉及金额） ----------------
        if (preg_match('#^orders/(\d+)/info$#', $path, $m) === 1 && ($method === 'POST' || $method === 'PATCH')) {
            return $this->orders()->updateInfo($userId, $m[1], $this->body());
        }

        // ---------------- 订单动作 ----------------
        if (preg_match('#^orders/(\d+)/(cancel|receive|switch-to-cod)$#', $path, $m) === 1 && $method === 'POST') {
            $idem = $this->idempotencyKey();
            if ($idem === null) {
                return $this->badRequest('写操作必须携带 Idempotency-Key（UUID v4）');
            }

            $body = $this->body();
            $version = (int) ($body['version'] ?? 0);

            return match ($m[2]) {
                'cancel' => $this->orders()->cancel(
                    $userId,
                    $m[1],
                    $version,
                    isset($body['reason']) ? (string) $body['reason'] : null,
                    $idem
                ),
                'receive' => $this->orders()->receive($userId, $m[1], $version, $idem),
                'switch-to-cod' => $this->orders()->switchToCod($userId, $m[1], $version, $idem),
            };
        }

        if (preg_match('#^orders/(\d+)/refunds$#', $path, $m) === 1) {
            return $this->orders()->refunds($userId, $m[1]);
        }

        if (preg_match('#^orders/(\d+)$#', $path, $m) === 1) {
            return $this->orders()->orderDetail($userId, $m[1]);
        }

        return [
            'code' => RejectionCode::NOT_FOUND->value,
            'message' => sprintf('未知接口：%s（%s）', $path, $method),
            'data' => null,
        ];
    }

    // =========================================================
    // 鉴权
    // =========================================================

    /**
     * 取当前登录顾客 ID。
     *
     * 支持两种凭据，便于渐进迁移：
     *   1. `Authorization: Bearer <token>` —— 网页版/安卓版使用（自建会话表）
     *   2. ShopXO 的 `token` 请求参数 —— 兼容既有的修订接口调用方
     *
     * 【归属校验不在这里做】
     * 每个涉及具体订单的分支都必须调用 `assertOwnership()` ——
     * 漏掉一个，任何登录用户就能改别人的订单。
     */
    private function currentUserId(string $token): ?int
    {
        $resolved = $this->auth()->resolve($token);
        if ($resolved !== null) {
            return $resolved['user_id'];
        }

        // 兜底：ShopXO 原生 token（小程序/旧客户端）
        try {
            $info = UserService::LoginUserInfo();
        } catch (\Throwable) {
            return null;
        }

        if (empty($info) || empty($info['id'])) {
            return null;
        }

        return (int) $info['id'];
    }

    private function assertOwnership(string $orderId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            sprintf('SELECT `id`, `user_id` FROM `%sorder` WHERE `id` = :id LIMIT 1', table_prefix())
        );
        $stmt->execute(['id' => $orderId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return ['code' => RejectionCode::NOT_FOUND->value, 'message' => '订单不存在', 'data' => null];
        }

        if ((string) $row['user_id'] !== (string) $userId) {
            return ['code' => RejectionCode::FORBIDDEN->value, 'message' => '无权访问该订单', 'data' => null];
        }

        return null;
    }

    private function bearerToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = (string) ($headers['Authorization'] ?? '');
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) === 1) {
            return trim($m[1]);
        }

        return '';
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
    private function items(?array $body = null): array
    {
        $body ??= $this->body();
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

    private function query(string $key): ?string
    {
        $v = $_GET[$key] ?? null;
        if ($v === null || $v === '') {
            return null;
        }

        return is_scalar($v) ? (string) $v : null;
    }

    private function idempotencyKey(): ?string
    {
        $key = (string) ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '');
        if ($key === '') {
            return null;
        }

        return IdempotencyService::isValidKey($key) ? trim($key) : null;
    }

    // =========================================================
    // 服务装配
    // =========================================================

    private function auth(): CustomerAuthService
    {
        static $svc = null;
        if ($svc instanceof CustomerAuthService) {
            return $svc;
        }

        $svc = new CustomerAuthService($this->pdo, table_prefix());

        return $svc;
    }

    private function pushDevices(): PushDeviceService
    {
        $config = require extension_root() . '/config/extension.php';
        $getuiConfig = is_array($config['getui'] ?? null) ? $config['getui'] : [];

        return new PushDeviceService(new PdoPushDeviceRepository($this->pdo), $getuiConfig);
    }

    private function catalog(): CustomerCatalogService
    {
        static $svc = null;
        if ($svc instanceof CustomerCatalogService) {
            return $svc;
        }

        // 图片要拼成绝对地址：顾客端可能部署在与 API 不同的域名/端口上
        $base = (string) (getenv('APP_URL') ?: '');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '');
        }

        $svc = new CustomerCatalogService($this->pdo, table_prefix(), $base);

        return $svc;
    }

    private function setting(): ShopSettingService
    {
        static $svc = null;
        if ($svc instanceof ShopSettingService) {
            return $svc;
        }

        $svc = new ShopSettingService($this->pdo);

        return $svc;
    }

    private function orders(): CustomerOrderService
    {
        static $svc = null;
        if ($svc instanceof CustomerOrderService) {
            return $svc;
        }

        $svc = new CustomerOrderService(
            pdo: $this->pdo,
            orders: new PdoOrderRepository($this->pdo, table_prefix()),
            stateMachine: new OrderStateMachine(),
            idempotency: new IdempotencyService(new PdoIdempotencyStore($this->pdo)),
            catalog: $this->catalog(),
            setting: $this->setting(),
            tablePrefix: table_prefix(),
        );

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
            new PdoOrderRepository($this->pdo, table_prefix()),
            new RevisionCalculator(),
            table_prefix()
        );

        return $svc;
    }

    private function ok(mixed $data, ?string $message = null): array
    {
        return ['code' => 'OK', 'message' => $message, 'data' => $data];
    }

    private function badRequest(string $message): array
    {
        return ['code' => RejectionCode::VALIDATION_FAILED->value, 'message' => $message, 'data' => null];
    }

    private function notFound(string $message): array
    {
        return ['code' => RejectionCode::NOT_FOUND->value, 'message' => $message, 'data' => null];
    }
}
