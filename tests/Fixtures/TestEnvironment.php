<?php

declare(strict_types=1);

namespace GCWorld\Common\Tests\Fixtures;

use GCWorld\Interfaces\CommonEnvironmentEnumInterface;

enum TestEnvironment: string implements CommonEnvironmentEnumInterface
{
    case TEST = 'test';

    public function text(): string
    {
        return 'Test';
    }

    public function isCI(): bool
    {
        return true;
    }
}
