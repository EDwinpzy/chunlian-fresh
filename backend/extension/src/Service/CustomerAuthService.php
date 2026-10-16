<?php

declare(strict_types=1);

namespace Chunlian\Service;

use Chunlian\Domain\RejectionCode;

/**
 * 顾客账号与会话（网页版 + 安卓版）。
 *
 * 【为什么不用 ShopXO 原生的登录接口】
 * ShopXO 的 `user/login` 走的是它自己的 token 单列（`shopxo_user.token`），
 * 一个账号只能有一个有效会话 —— 手机登录会把浏览器挤掉。
 * 顾客端的真实使用是「手机 App + 电脑/手机浏览器」并存，
 * 所以这里沿用商家端已经验证过的自建会话表方案（`veg_customer_session`），
 * 只存 token 的 SHA-256。
 *
 * 【账号仍然落在 shopxo_user】
 * 订单表的 `user_id` 外键指向 `shopxo_user`，ShopXO 后台也按这张表展示会员。
 * 若把顾客另存一张表，后台就会看到「订单没有会员」，售后与对账都会断掉。
 * 密码算法也沿用 ShopXO 的 `md5($salt . trim($pwd))`
 * （`app/common.php` 的 `LoginPwdEncryption`），保证两边改密码互不打架。
 */
final class CustomerAuthService
{
    /** 会话有效期（秒）：90 天。生鲜顾客是低频但长期复购的用户，太短会频繁要求重登。 */
    private const SESSION_TTL = 7776000;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'shopxo_',
    ) {
    }

    // =========================================================
    // 注册 / 登录 / 登出
    // =========================================================

    public function register(string $phone, string $password, string $nickname = ''): array
    {
        $phone = trim($phone);

        if (preg_match('/^1[3-9]\d{9}$/', $phone) !== 1) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '请输入正确的手机号');
        }
        if (mb_strlen($password) < 6) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '密码至少 6 位');
        }
        if (mb_strlen($password) > 32) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '密码不能超过 32 位');
        }

        $p = $this->tablePrefix;

        // 手机号是登录名，必须唯一。先查再加唯一索引式判断，
        // 避免并发注册出两个同号账号（真正的兜底是 DB 唯一索引，
        // 但本表是 ShopXO 原生表，不能随意加索引，因此这里用行锁串行化）
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT `id` FROM `{$p}user` WHERE `mobile` = :m AND `is_delete_time` = 0 LIMIT 1 FOR UPDATE"
            );
            $stmt->execute(['m' => $phone]);
            if ($stmt->fetch(\PDO::FETCH_ASSOC) !== false) {
                $this->pdo->rollBack();

                return $this->fail(RejectionCode::VALIDATION_FAILED, '该手机号已注册，请直接登录');
            }

            $salt = $this->randomString(16);
            $now = time();

            $insert = $this->pdo->prepare(
                "INSERT INTO `{$p}user` "
                . "(`number_code`, `status`, `salt`, `pwd`, `username`, `nickname`, `mobile`, "
                . "`email`, `gender`, `avatar`, `province`, `city`, `county`, `birthday`, `address`, "
                . "`integral`, `locking_integral`, `referrer`, `is_delete_time`, `is_logout_time`, `add_time`, `upd_time`) "
                . "VALUES (:number_code, 0, :salt, :pwd, :username, :nickname, :mobile, "
                . "'', 0, '', '', '', '', 0, '', 0, 0, 0, 0, 0, :add_time, :upd_time)"
            );
            $insert->execute([
                'number_code' => $this->randomString(20),
                'salt' => $salt,
                'pwd' => $this->hashPassword($password, $salt),
                'username' => $phone,
                'nickname' => $nickname !== '' ? mb_substr($nickname, 0, 30) : $this->maskPhone($phone),
                'mobile' => $phone,
                'add_time' => $now,
                'upd_time' => $now,
            ]);

            $userId = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $session = $this->issueToken($userId);

        return $this->ok([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'user' => $this->profile($userId)['data'],
        ], '注册成功');
    }

    public function login(string $phone, string $password): array
    {
        $phone = trim($phone);
        $p = $this->tablePrefix;

        $stmt = $this->pdo->prepare(
            "SELECT `id`, `salt`, `pwd`, `status`, `nickname`, `mobile` FROM `{$p}user` "
            . "WHERE `mobile` = :m AND `is_delete_time` = 0 LIMIT 1"
        );
        $stmt->execute(['m' => $phone]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        // 统一失败文案：区分「账号不存在」与「密码错误」会帮攻击者枚举手机号
        if ($user === false) {
            $this->sleepToBluntBruteForce();

            return $this->fail(RejectionCode::FORBIDDEN, '手机号或密码错误');
        }

        if (!hash_equals((string) $user['pwd'], $this->hashPassword($password, (string) $user['salt']))) {
            $this->sleepToBluntBruteForce();

            return $this->fail(RejectionCode::FORBIDDEN, '手机号或密码错误');
        }

        if ((int) $user['status'] !== 0) {
            return $this->fail(RejectionCode::FORBIDDEN, '账号已被禁用，请联系店主');
        }

        $userId = (int) $user['id'];
        $session = $this->issueToken($userId);

        return $this->ok([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'user' => $this->profile($userId)['data'],
        ], '登录成功');
    }

    public function logout(string $token): array
    {
        if ($token === '') {
            return $this->ok(null, '已退出');
        }

        $this->pdo->prepare(
            'UPDATE `veg_customer_session` SET `revoked_at` = NOW() '
            . 'WHERE `token_hash` = :h AND `revoked_at` IS NULL'
        )->execute(['h' => hash('sha256', $token)]);

        return $this->ok(null, '已退出');
    }

    /**
     * 用 token 解析当前顾客。返回 null 表示未登录/已过期。
     *
     * @return array{user_id:int}|null
     */
    public function resolve(string $token): ?array
    {
        if (trim($token) === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT `id`, `user_id` FROM `veg_customer_session` '
            . 'WHERE `token_hash` = :h AND `revoked_at` IS NULL AND `expires_at` > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => hash('sha256', trim($token))]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        // 会话活跃时间用于「我的设备」与异常登录排查；失败不影响本次请求
        try {
            $this->pdo->prepare('UPDATE `veg_customer_session` SET `last_seen_at` = NOW() WHERE `id` = :id')
                ->execute(['id' => $row['id']]);
        } catch (\Throwable) {
            // 忽略
        }

        return ['user_id' => (int) $row['user_id']];
    }

    // =========================================================
    // 资料
    // =========================================================

    public function profile(int $userId): array
    {
        $p = $this->tablePrefix;
        $stmt = $this->pdo->prepare(
            "SELECT `id`, `nickname`, `mobile`, `avatar`, `add_time` FROM `{$p}user` "
            . "WHERE `id` = :id AND `is_delete_time` = 0 LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return $this->fail(RejectionCode::NOT_FOUND, '账号不存在');
        }

        return $this->ok([
            'user_id' => (string) $row['id'],
            'nickname' => (string) $row['nickname'],
            'mobile_masked' => $this->maskPhone((string) $row['mobile']),
            'avatar' => (string) $row['avatar'],
            'registered_at' => date('Y-m-d', (int) $row['add_time']),
        ]);
    }

    public function updateProfile(int $userId, array $patch): array
    {
        $sets = [];
        $params = ['id' => $userId];

        if (array_key_exists('nickname', $patch)) {
            $nickname = trim((string) $patch['nickname']);
            if ($nickname === '') {
                return $this->fail(RejectionCode::VALIDATION_FAILED, '昵称不能为空');
            }
            $sets[] = '`nickname` = :nickname';
            $params['nickname'] = mb_substr($nickname, 0, 30);
        }

        if (array_key_exists('avatar', $patch)) {
            $sets[] = '`avatar` = :avatar';
            $params['avatar'] = mb_substr((string) $patch['avatar'], 0, 255);
        }

        if ($sets === []) {
            return $this->fail(RejectionCode::VALIDATION_FAILED, '没有需要修改的字段');
        }

        $sets[] = '`upd_time` = ' . time();
        $this->pdo->prepare(
            "UPDATE `{$this->tablePrefix}user` SET " . implode(', ', $sets) . ' WHERE `id` = :id'
        )->execute($params);

        return $this->profile($userId);
    }

    // =========================================================
    // 内部
    // =========================================================

    /** @return array{token:string, expires_at:string} */
    private function issueToken(int $userId): array
    {
        $token = bin2hex(random_bytes(32)); // 256 bit
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + self::SESSION_TTL);

        $this->pdo->prepare(
            'INSERT INTO `veg_customer_session` '
            . '(`user_id`, `token_hash`, `device_name`, `last_seen_at`, `expires_at`, `created_at`) '
            . 'VALUES (:user_id, :token_hash, :device_name, :last_seen_at, :expires_at, :created_at)'
        )->execute([
            'user_id' => $userId,
            'token_hash' => hash('sha256', $token),
            'device_name' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '未知设备'), 0, 120),
            'last_seen_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);

        // 顺手清掉已过期的会话行，避免表无限增长。
        // 不做成定时任务是因为这里本来就是低频写入点（登录/注册）。
        try {
            $this->pdo->exec('DELETE FROM `veg_customer_session` WHERE `expires_at` < DATE_SUB(NOW(), INTERVAL 30 DAY)');
        } catch (\Throwable) {
            // 忽略
        }

        return ['token' => $token, 'expires_at' => $expiresAt];
    }

    /** 与 ShopXO 的 `LoginPwdEncryption` 保持一致：md5($salt . trim($pwd))。 */
    private function hashPassword(string $password, string $salt): string
    {
        return md5($salt . trim($password));
    }

    private function randomString(int $length): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }

        return $out;
    }

    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 7) {
            return $phone === '' ? '' : '****';
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }

    private function sleepToBluntBruteForce(): void
    {
        usleep(random_int(200_000, 500_000));
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
