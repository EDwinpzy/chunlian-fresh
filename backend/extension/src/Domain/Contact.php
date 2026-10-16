<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/**
 * 收货联系信息。仅收集履约所需字段。
 *
 * 【严格 vs 宽松 —— 两条构造路径】
 *
 * 默认（严格）：姓名、手机号、地址都必须合法，否则抛异常。
 * 用于**新写入**的数据 —— 脏数据应在入口就被挡住。
 *
 * 宽松（`lenient: true`）：跳过校验，仅做规范化。
 * 用于**读取历史/外部数据**（如从 ShopXO 原生表映射）。
 * 理由：库里可能存在历史订单，其手机号是座机或当年填写不规范。
 * 若读取也严格校验，一条脏数据会让整个订单列表查询抛异常 ——
 * 商家连别的单都看不到了，这比"看到一个手机号格式异常的单"糟得多。
 *
 * ⚠️  **绝不允许**把宽松构造出的 Contact 直接写回数据库 ——
 * 那等于绕过入口校验，脏数据会持续扩散。
 */
final class Contact
{
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly string $address,
        bool $lenient = false,
    ) {
        if ($lenient) {
            return;
        }

        if (trim($name) === '') {
            throw new \InvalidArgumentException('收货人姓名必填');
        }
        if (!self::isValidPhone($phone)) {
            throw new \InvalidArgumentException('手机号格式非法');
        }
        if (trim($address) === '') {
            throw new \InvalidArgumentException('详细地址必填');
        }
    }

    /** 中国大陆手机号：1 开头 11 位。 */
    public static function isValidPhone(string $phone): bool
    {
        return (bool) preg_match('/^1[3-9]\d{9}$/', trim($phone));
    }

    /**
     * 从历史/外部数据宽松构造。
     * 见类注释中「严格 vs 宽松」的说明与使用禁忌。
     */
    public static function fromRawLenient(string $name, string $rawPhone, string $address): self
    {
        $name = trim($name);
        $address = trim($address);

        return new self(
            name: $name === '' ? '（未填写）' : $name,
            phone: self::normalizePhone($rawPhone),
            address: $address === '' ? '（未填写）' : $address,
            lenient: true,
        );
    }

    /** 规范化手机号：去空白与分隔符、全角数字转半角、去国家码前缀。 */
    public static function normalizePhone(string $raw): string
    {
        $s = trim($raw);

        // 全角数字 → 半角
        $s = strtr($s, [
            '０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4',
            '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9',
        ]);

        // 去常见分隔符
        $s = str_replace([' ', '-', '－', '—', '(', ')', '（', '）'], '', $s);

        // 去 +86 / 86 前缀
        if (str_starts_with($s, '+86')) {
            $s = substr($s, 3);
        } elseif (str_starts_with($s, '86') && strlen($s) === 13) {
            $s = substr($s, 2);
        }

        return $s;
    }

    /**
     * 手机号是否可直接用于拨打。
     * 宽松读取后仍需用它判断 —— 界面据此决定是否显示拨号按钮。
     */
    public function hasDialablePhone(): bool
    {
        return self::isValidPhone($this->phone);
    }

    /** 脱敏手机号，用于日志与通知栏。 */
    public function maskedPhone(): string
    {
        if (strlen($this->phone) < 7) {
            return '****';
        }

        return substr($this->phone, 0, 3) . '****' . substr($this->phone, -4);
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
        ];
    }

    /** 对外输出（脱敏），用于列表与通知。 */
    public function toMaskedArray(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->maskedPhone(),
            'address' => $this->address,
        ];
    }
}
