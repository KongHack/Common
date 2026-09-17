<?php

declare(strict_types=1);

namespace GCWorld\Common\Tests;

use Exception;
use GCWorld\Common\CommonConfig;
use GCWorld\Common\Exceptions\ConfigInclusionException;
use GCWorld\Common\Exceptions\ConfigLoadException;
use GCWorld\Common\Tests\Support\TemporaryDirectoryTestCase;

final class CommonConfigTest extends TemporaryDirectoryTestCase
{
    public function testLoadsYamlAndWritesCompiledCache(): void
    {
        $configPath = $this->writeFile(
            'config.yml',
            "server:\n  name: primary\ncommon:\n  sort: false\n  resolve_hosts: false\n",
        );

        $config = (new CommonConfig($configPath))->getArray();

        self::assertSame('primary', $config['server']['name']);
        self::assertFileExists($this->temporaryDirectory.DIRECTORY_SEPARATOR.'config.php');
    }

    public function testLoadsCompiledCacheBeforeChangedYaml(): void
    {
        $configPath = $this->writeFile(
            'config.yml',
            "server:\n  name: original\ncommon:\n  sort: false\n  resolve_hosts: false\n",
        );
        new CommonConfig($configPath);
        file_put_contents(
            $configPath,
            "server:\n  name: changed\ncommon:\n  sort: false\n  resolve_hosts: false\n",
        );

        $cached = (new CommonConfig($configPath))->getArray();

        self::assertSame('original', $cached['server']['name']);
        self::assertArrayHasKey('GCINTERNAL', $cached);
    }

    public function testIncludesRelativeFilesInOrder(): void
    {
        $this->writeFile(
            'first.yml',
            "service:\n  name: first\n  options:\n    first: true\n",
        );
        $this->writeFile(
            'second.yml',
            "service:\n  name: second\n  options:\n    second: true\n",
        );
        $configPath = $this->writeFile(
            'config.yml',
            "includes:\n  - first.yml\n  - second.yml\nservice:\n  name: base\n  options:\n    base: true\n",
        );

        $config = (new CommonConfig($configPath))->getArray();

        self::assertSame('second', $config['service']['name']);
        self::assertSame(
            ['base' => true, 'first' => true, 'second' => true],
            $config['service']['options'],
        );
    }

    public function testRejectsMissingIncludedFile(): void
    {
        $configPath = $this->writeFile('config.yml', "includes:\n  - missing.yml\n");

        $this->expectException(ConfigInclusionException::class);
        $this->expectExceptionMessage('Config Inclusion File Not Found. missing.yml');

        new CommonConfig($configPath);
    }

    public function testRejectsNonStringIncludedPath(): void
    {
        $configPath = $this->writeFile('config.yml', "includes:\n  - 123\n");

        $this->expectException(ConfigInclusionException::class);
        $this->expectExceptionMessage('Config inclusion paths must be strings.');

        new CommonConfig($configPath);
    }

    public function testRejectsScalarIncludedDocument(): void
    {
        $this->writeFile('invalid.yml', "scalar\n");
        $configPath = $this->writeFile('config.yml', "includes:\n  - invalid.yml\n");

        $this->expectException(ConfigInclusionException::class);
        $this->expectExceptionMessage('Config Inclusion File Failed to Load. invalid.yml');

        new CommonConfig($configPath);
    }

    public function testRejectsScalarRootDocumentWithConfigLoadException(): void
    {
        $configPath = $this->writeFile('config.yml', "scalar\n");

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('Config File Failed to Load: '.$configPath);

        new CommonConfig($configPath);
    }

    public function testRejectsInvalidCompiledCacheWithConfigLoadException(): void
    {
        $configPath = $this->writeFile('config.yml', "server:\n  name: primary\n");
        $cachePath = $this->writeFile('config.php', "<?php\n\nreturn 'invalid';\n");

        $this->expectException(ConfigLoadException::class);
        $this->expectExceptionMessage('Config File Failed to Load: '.$cachePath);

        new CommonConfig($configPath);
    }

    public function testRejectsNonYamlExtension(): void
    {
        $configPath = $this->writeFile('config.yaml', "server: primary\n");

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Common Config File must end in .yml');

        new CommonConfig($configPath);
    }

    public function testResolvesHostValues(): void
    {
        $configPath = $this->writeFile(
            'config.yml',
            "service:\n  host: localhost:6379\ncommon:\n  resolve_hosts: true\n",
        );

        $config = (new CommonConfig($configPath))->getArray();

        self::assertSame(gethostbyname('localhost').':6379', $config['service']['host']);
    }
}
