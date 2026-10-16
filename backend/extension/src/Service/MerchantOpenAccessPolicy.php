<?php

declare(strict_types=1);

namespace Chunlian\Service;

/** Controls the intentionally public merchant-app access mode. */
final class MerchantOpenAccessPolicy
{
    public const ENVIRONMENT_VARIABLE = 'CHUNLIAN_MERCHANT_OPEN_ACCESS';

    /**
     * Open management access is enabled only by the exact value "1".
     * An explicit value can be passed by tests; null reads the process environment.
     */
    public static function isEnabled(?string $configuredValue = null): bool
    {
        if ($configuredValue === null) {
            $configuredValue = getenv(self::ENVIRONMENT_VARIABLE);
        }

        return $configuredValue === '1';
    }
}
