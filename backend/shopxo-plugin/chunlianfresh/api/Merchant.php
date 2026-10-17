<?php

declare(strict_types=1);

namespace app\plugins\chunlianfresh\api;

require_once dirname(__DIR__) . '/Bootstrap.php';

use Chunlian\Domain\RejectionCode;
use Chunlian\Repository\PdoIdempotencyStore;
use Chunlian\Repository\PdoOrderRepository;
use Chunlian\Repository\PdoPushDeviceRepository;
use Chunlian\Service\CatalogService;
use Chunlian\Service\FreshCategoryManagementService;
use Chunlian\Service\GoodsWriteService;
use Chunlian\Service\IdempotencyService;
use Chunlian\Service\LedgerCalculator;
use Chunlian\Service\ManualPaymentService;
use Chunlian\Service\MerchantInsightService;
use Chunlian\Service\MerchantOrderService;
use Chunlian\Service\PushDeviceService;
use Chunlian\Service\MerchantOpenAccessPolicy;
use Chunlian\Service\OrderStateMachine;
use Chunlian\Service\RevisionCalculator;
use Chunlian\Service\RevisionService;
use Chunlian\Service\ShopSettingService;

use function app\plugins\chunlianfresh\pdo;
use function app\plugins\chunlianfresh\extension_root;
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
        $allowedMethods = match (true) {
            in_array($path, ['auth/login', 'auth/open-session', 'auth/logout', 'notifications/read', 'uploads'], true) => ['POST'],
            $path === 'push-devices' => ['POST'],
            preg_match('#^push-devices/[A-Za-z0-9._:-]{8,128}$#', $path) === 1 => ['DELETE'],
            $path === 'store' => ['GET', 'PATCH', 'PUT'],
            $path === 'goods/categories' => ['GET', 'POST'],
            preg_match('#^goods/categories/\\d+$#', $path) === 1 => ['PATCH', 'PUT', 'DELETE'],
            $path === 'goods' => ['GET', 'POST'],
            preg_match('#^goods/\d+/shelves$#', $path) === 1 => ['POST'],
            preg_match('#^goods/\d+/sku/\d+$#', $path) === 1 => ['PATCH', 'PUT'],
            preg_match('#^goods/\d+$#', $path) === 1 => ['GET', 'PATCH', 'PUT', 'DELETE'],
            preg_match('#^orders/[^/]+/(deliver|confirm-delivery|cod-receipt|qr-receipt|manual-refund|cancel)$#', $path) === 1 => ['POST'],
            preg_match('#^orders/[^/]+/commit-topup$#', $path) === 1 => ['POST'],
            default => ['GET'],
        };
        if (!in_array($method, $allowedMethods, true)) {
            return $this->badRequest('接口不支持此请求方式');
        }

        // ---------- 无需鉴权的端点 ----------
        if ($path === 'auth/login') {
            return $this->login($params);
        }
        if ($path === 'auth/open-session') {
            return $this->openSession();
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
        if ($path === 'auth/logout') {
            $this->pdo->prepare('UPDATE `veg_merchant_session` SET `revoked_at` = NOW() WHERE `id` = :id')
                ->execute(['id' => $session['id']]);
            return $this->ok(null, '已退出登录');
        }

        if ($path === 'push-devices' && $method === 'POST') {
            return $this->pushDevices()->bind('MERCHANT', $actorId, $this->body());
        }
        if (preg_match('#^push-devices/([A-Za-z0-9._:-]{8,128})$#', $path, $m) === 1 && $method === 'DELETE') {
            return $this->pushDevices()->revoke('MERCHANT', $actorId, $m[1]);
        }

        // ---------- 路由 ----------
        // 顺序敏感：`orders/{id}/xxx` 必须排在 `orders/{id}` 之前，
        // 否则 `deliver` 会被当成订单 ID。
        if ($path === 'dashboard') {
            $data = $this->service()->dashboard();

            // 工作台要能一眼看到「营业中 / 已打烊」并能直接切换，
            // 所以把店铺设置一并带上，省掉客户端再发一次请求
            if (($data['code'] ?? '') === 'OK' && is_array($data['data'] ?? null)) {
                $data['data']['store'] = $this->setting()->get();
            }

            return $data;
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

        // ---------- 店铺设置 ----------
        // 营业开关直接决定顾客端能否下单，属于高频且需要即时生效的设置
        if ($path === 'store') {
            if ($method === 'GET') {
                // 统一信封：客户端所有接口都按 {code,message,data} 解析，
                // 这里直接吐裸对象会让客户端反序列化失败
                return $this->ok($this->setting()->get());
            }

            return $this->setting()->update($this->body(), $actorId);
        }

        // ---------- 图片上传 ----------
        if ($path === 'uploads') {
            return $this->upload($actorId);
        }

        // ---------- 商品管理 ----------
        if ($path === 'goods/categories') {
            if ($method === 'POST') {
                $key = $this->idempotencyKey();
                if ($key === null) {
                    return $this->badRequest('新建分类必须携带 Idempotency-Key（UUID v4）');
                }
                $payload = $this->body();
                $result = (new IdempotencyService(new PdoIdempotencyStore($this->pdo)))->run(
                    $actorId,
                    'merchant.category.create',
                    $key,
                    $payload,
                    fn (): array => $this->freshCategories()->create($payload, $actorId)
                );

                return $result['code'] === 'OK'
                    ? $result['data']
                    : [
                        'code' => $result['code'],
                        'message' => $result['data']['message'] ?? '请求正在处理，请稍后重试',
                        'data' => null,
                    ];
            }

            return $this->freshCategories()->list();
        }

        if (preg_match('#^goods/categories/(\\d+)$#', $path, $m) === 1) {
            if ($method === 'DELETE') {
                return $this->freshCategories()->delete($m[1], $actorId);
            }

            return $this->freshCategories()->update($m[1], $this->body(), $actorId);
        }

        if ($path === 'goods') {
            if ($method === 'POST') {
                $key = $this->idempotencyKey();
                if ($key === null) {
                    return $this->badRequest('新建商品必须携带 Idempotency-Key（UUID v4）');
                }
                $payload = $this->body();
                $result = (new IdempotencyService(new PdoIdempotencyStore($this->pdo)))->run(
                    $actorId, 'merchant.goods.create', $key, $payload,
                    fn (): array => $this->goodsWrite()->create($payload, $actorId)
                );
                return $result['code'] === 'OK'
                    ? $result['data']
                    : ['code' => $result['code'], 'message' => $result['data']['message'] ?? '请求正在处理，请稍后重试', 'data' => null];
            }

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
            if ($method === 'DELETE') {
                return $this->goodsWrite()->archive($m[1], $actorId);
            }
            if ($method === 'PATCH' || $method === 'PUT') {
                return $this->goodsWrite()->update($m[1], $this->body(), $actorId);
            }

            return $this->catalog()->goodsDetail($m[1]);
        }

        if ($path === 'orders') {
            return $this->service()->listOrders(
                status: $this->query('status'),
                keyword: $this->query('keyword'),
                page: (int) ($this->query('page') ?? 1),
                pageSize: (int) ($this->query('page_size') ?? 20),
                sort: $this->query('sort') ?? 'DEFAULT',
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
                'qr-receipt' => $this->manualPayment()->confirmQrReceipt(
                    $orderId,
                    $actorId,
                    (int) ($body['version'] ?? 0),
                    (int) ($body['amount_fen'] ?? 0),
                    $idemKey
                ),
                'manual-refund' => $this->manualPayment()->confirmManualRefund(
                    $orderId,
                    $actorId,
                    (int) ($body['version'] ?? 0),
                    (int) ($body['amount_fen'] ?? 0),
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

        return $this->issueSession((string) $admin['id'], $deviceId);
    }

    /**
     * Public credentialless management access. This is intentionally disabled unless
     * the operator explicitly sets CHUNLIAN_MERCHANT_OPEN_ACCESS=1.
     *
     * @return array{code:string,message:?string,data:array<string,string>|null}
     */
    private function openSession(): array
    {
        if (!MerchantOpenAccessPolicy::isEnabled()) {
            return $this->forbidden('公开管理免登录未启用');
        }

        $body = $this->body();
        $rawDeviceId = $body['device_id'] ?? null;
        if (!is_string($rawDeviceId)) {
            return $this->badRequest('device_id 必须是字符串');
        }

        $deviceId = trim($rawDeviceId);
        if ($deviceId === '' || strlen($deviceId) > 64) {
            return $this->badRequest('device_id 必填且不能超过 64 字节');
        }

        $stmt = $this->pdo->query(
            sprintf('SELECT `id` FROM `%sadmin` WHERE `status` = 0 ORDER BY `id` ASC LIMIT 1', table_prefix())
        );
        $actorId = $stmt->fetchColumn();
        if ($actorId === false) {
            return $this->forbidden('没有可用的管理员账号，无法建立管理会话');
        }

        return $this->issueSession((string) $actorId, $deviceId);
    }

    /**
     * Issue the same 30-day, SHA-256-backed session used by password login.
     *
     * @return array{code:string,message:?string,data:array{token:string,expires_at:string,merchant_id:string,shop_name:string}}
     */
    private function issueSession(string $actorId, string $deviceId): array
    {
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

    /** @return array{id:int,actor_id:int}|null */
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

        return ['id' => (int) $row['id'], 'actor_id' => (int) $row['actor_id']];
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

    private function pushDevices(): PushDeviceService
    {
        $config = require extension_root() . '/config/extension.php';
        $getuiConfig = is_array($config['getui'] ?? null) ? $config['getui'] : [];

        return new PushDeviceService(new PdoPushDeviceRepository($this->pdo), $getuiConfig);
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

    private function manualPayment(): ManualPaymentService
    {
        static $svc = null;
        if ($svc instanceof ManualPaymentService) {
            return $svc;
        }

        $svc = new ManualPaymentService(
            $this->pdo,
            $this->orders(),
            $this->revision(),
            new IdempotencyService(new PdoIdempotencyStore($this->pdo)),
            table_prefix()
        );

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

    private function freshCategories(): FreshCategoryManagementService
    {
        static $svc = null;
        if ($svc instanceof FreshCategoryManagementService) {
            return $svc;
        }

        $svc = new FreshCategoryManagementService($this->pdo, table_prefix());

        return $svc;
    }

    private function goodsWrite(): GoodsWriteService
    {
        static $svc = null;
        if ($svc instanceof GoodsWriteService) {
            return $svc;
        }

        $svc = new GoodsWriteService($this->pdo, table_prefix());

        return $svc;
    }

    // =========================================================
    // 图片上传
    // =========================================================

    /**
     * 商品图片上传。
     *
     * 【为什么自己写而不是用 ShopXO 的上传组件】
     * ShopXO 的附件上传走的是它自己的 `AttachmentService`，
     * 会把文件登记到 `shopxo_attachment` 表并带上后台管理员的会话上下文 ——
     * 那是「后台网页」的模型。安卓 App 通过 API 上传时没有那套会话，
     * 强行复用会出现「文件写进去了但附件记录不完整」的半成品状态。
     *
     * 这里做最小可用实现：校验类型与大小 → 落到 ShopXO 的公开上传目录
     * → 返回可直接存进 `goods.images` 的相对路径。
     * 路径规则与 ShopXO 自身一致（/static/upload/images/goods/YYYY/MM/DD/），
     * 因此 nginx 的静态规则与 CDN 缓存都不用额外配置。
     */
    private function upload(string $actorId): array
    {
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return $this->badRequest('请选择要上传的图片');
        }

        $maxBytes = max(1, (int) (getenv('UPLOAD_MAX_MB') ?: 5)) * 1024 * 1024;
        if ((int) $file['size'] > $maxBytes) {
            return $this->badRequest(sprintf('图片不能超过 %d MB', (int) ($maxBytes / 1024 / 1024)));
        }

        // 用 getimagesize 而不是只看扩展名或 MIME：扩展名与 MIME 都是客户端说了算，
        // 只有真正解析出图片尺寸才能确认这不是一个改了后缀的可执行文件
        $info = @getimagesize((string) $file['tmp_name']);
        if ($info === false) {
            return $this->badRequest('文件不是有效的图片');
        }

        $allowed = [
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            IMAGETYPE_GIF => 'gif',
            IMAGETYPE_WEBP => 'webp',
        ];
        $type = (int) ($info[2] ?? 0);
        if (!isset($allowed[$type])) {
            return $this->badRequest('仅支持 JPG / PNG / GIF / WebP 格式');
        }

        // __DIR__ ends in app/plugins/chunlianfresh/api: four parents reach ShopXO.
        $shopxoRoot = dirname(__DIR__, 4);
        $relativeDir = '/static/upload/images/goods/' . date('Y/m/d');
        $absoluteDir = $shopxoRoot . '/public' . $relativeDir;

        if (!is_dir($absoluteDir) && !@mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            return ['code' => RejectionCode::VALIDATION_FAILED->value, 'message' => '上传目录不可写，请联系维护者', 'data' => null];
        }

        $filename = date('YmdHis') . random_int(1000, 9999) . '.' . $allowed[$type];
        $absolutePath = $absoluteDir . '/' . $filename;

        if (!move_uploaded_file((string) $file['tmp_name'], $absolutePath)) {
            return ['code' => RejectionCode::VALIDATION_FAILED->value, 'message' => '保存图片失败，请重试', 'data' => null];
        }

        // 站点以 www-data 运行；新建的文件若属主不对，之后 ShopXO 后台替换图片会失败
        @chmod($absolutePath, 0644);

        $relativePath = $relativeDir . '/' . $filename;

        $base = (string) (getenv('APP_URL') ?: '');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $base = $scheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? '');
        }

        return $this->ok([
            'path' => $relativePath,
            'url' => rtrim($base, '/') . $relativePath,
            'width' => (int) $info[0],
            'height' => (int) $info[1],
            'bytes' => (int) $file['size'],
        ], '上传成功');
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

    private function forbidden(string $message): array
    {
        return ['code' => RejectionCode::FORBIDDEN->value, 'message' => $message, 'data' => null];
    }

    private function notFound(string $message): array
    {
        return ['code' => RejectionCode::NOT_FOUND->value, 'message' => $message, 'data' => null];
    }
}
