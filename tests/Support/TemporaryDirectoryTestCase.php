<?php

declare(strict_types=1);

namespace GCWorld\Common\Tests\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class TemporaryDirectoryTestCase extends TestCase
{
    protected string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'common-test-');
        if ($path === false) {
            throw new RuntimeException('Unable to reserve a temporary path.');
        }

        unlink($path);
        if (!mkdir($path)) {
            throw new RuntimeException('Unable to create the temporary directory.');
        }

        $this->temporaryDirectory = $path;
    }

    protected function tearDown(): void
    {
        $files = glob($this->temporaryDirectory.DIRECTORY_SEPARATOR.'*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    protected function writeFile(string $name, string $contents): string
    {
        $path = $this->temporaryDirectory.DIRECTORY_SEPARATOR.$name;
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to write test file: '.$path);
        }

        return $path;
    }
}
