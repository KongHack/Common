<?php

declare(strict_types=1);

namespace GCWorld\Common\Tests\Fixtures;

use GCWorld\Common\Common;
use GCWorld\Interfaces\CommonEnvironmentEnumInterface;

final class AlternateTestCommon extends Common
{
    public function getEnvironment(): CommonEnvironmentEnumInterface
    {
        return TestEnvironment::TEST;
    }
}
