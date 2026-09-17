<?php

declare(strict_types=1);

namespace GCWorld\Common\Tests\Fixtures;

use GCWorld\Common\Common;
use GCWorld\Interfaces\CommonEnvironmentEnumInterface;

final class TestCommon extends Common
{
    public static function fromConfig(string $configPath): self
    {
        $common = new self();
        $common->configPath = $configPath;

        return $common;
    }

    public function getEnvironment(): CommonEnvironmentEnumInterface
    {
        return TestEnvironment::TEST;
    }
}
