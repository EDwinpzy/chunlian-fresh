<?php

declare(strict_types=1);

namespace Chunlian\Tests\Service;

use Chunlian\Service\MerchantOpenAccessPolicy;
use PHPUnit\Framework\TestCase;

final class MerchantOpenAccessPolicyTest extends TestCase
{
    public function testOnlyExactOneEnablesOpenAccess(): void
    {
        self::assertTrue(MerchantOpenAccessPolicy::isEnabled('1'));

        foreach (['', '0', '01', 'true', 'yes', ' 1', '1 '] as $configuredValue) {
            self::assertFalse(
                MerchantOpenAccessPolicy::isEnabled($configuredValue),
                sprintf('Expected %s to keep open access disabled.', var_export($configuredValue, true)),
            );
        }
    }

    public function testEnvironmentVariableDefaultsToDisabled(): void
    {
        $original = getenv(MerchantOpenAccessPolicy::ENVIRONMENT_VARIABLE);
        putenv(MerchantOpenAccessPolicy::ENVIRONMENT_VARIABLE);

        try {
            self::assertFalse(MerchantOpenAccessPolicy::isEnabled());

            putenv(MerchantOpenAccessPolicy::ENVIRONMENT_VARIABLE . '=1');
            self::assertTrue(MerchantOpenAccessPolicy::isEnabled());
        } finally {
            if ($original === false) {
                putenv(MerchantOpenAccessPolicy::ENVIRONMENT_VARIABLE);
            } else {
                putenv(MerchantOpenAccessPolicy::ENVIRONMENT_VARIABLE . '=' . $original);
            }
        }
    }
}
