<?php

declare(strict_types=1);

namespace Chunlian\Domain;

/** 收货联系信息。仅收集履约所需字段。 */
final class Contact
{
    public function __construct(
        public readonly string $name,
        public readonly string $phone,
        public readonly string $address,
    ) {
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

    /** 脱敏手机号，用于日志与通知栏。 */
    public function maskedPhone(): string
    {
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
