<?php

declare(strict_types=1);

namespace GCWorld\Common\Tests;

use Exception;
use GCWorld\Common\Tests\Fixtures\AlternateTestCommon;
use GCWorld\Common\Tests\Fixtures\TestCommon;
use GCWorld\Common\Tests\Fixtures\TestEnvironment;
use GCWorld\Common\Tests\Support\TemporaryDirectoryTestCase;

final class CommonTest extends TemporaryDirectoryTestCase
{
    public function testSingletonIsStableAndIsolatedByConcreteClass(): void
    {
        self::assertSame(TestCommon::getInstance(), TestCommon::getInstance());
        self::assertSame(AlternateTestCommon::getInstance(), AlternateTestCommon::getInstance());
        self::assertNotSame(TestCommon::getInstance(), AlternateTestCommon::getInstance());
    }

    public function testProvidesEnvironmentConfigurationAndPaths(): void
    {
        $common = $this->createCommon();

        self::assertSame(TestEnvironment::TEST, $common->getEnvironment());
        self::assertSame(['name' => 'test-server'], $common->getConfig('server'));
        self::assertSame([], $common->getConfig('missing'));
        self::assertSame('/tmp/common/', $common->getDirectory('temp'));
        self::assertSame('', $common->getDirectory('missing'));
        self::assertSame('https://example.test/', $common->getPath('base'));
        self::assertSame('https://example.test/tmp', $common->getPath('temp'));
        self::assertSame('https://example.test/assets', $common->getPath('asset_cache'));
        self::assertSame('', $common->getPath('missing'));
    }

    public function testReturnsNullForMissingCacheConfiguration(): void
    {
        self::assertNull($this->createCommon()->getCache('missing'));
    }

    public function testRejectsCircularDatabaseAliases(): void
    {
        $common = $this->createCommon(
            "database:\n  first:\n    alias: second\n  second:\n    alias: first\n",
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Circular DB alias detected for instance: first');

        $common->getDatabase('first');
    }

    public function testReportsPackageAndStandaloneProjectVersions(): void
    {
        $common = $this->createCommon();
        $expectedVersion = trim((string) file_get_contents(dirname(__DIR__).DIRECTORY_SEPARATOR.'VERSION'));

        self::assertSame($expectedVersion, $common->getCommonVersion());
        self::assertSame('COMMON-ONLY:'.$expectedVersion, $common->getProjectVersion(true));
    }

    private function createCommon(string $additionalConfig = ''): TestCommon
    {
        $config = <<<YAML
server:
  name: test-server
cache: []
paths:
  file:
    temp: /tmp/common/
  web:
    base: 'https://example.test/'
    temp: tmp
    asset_cache: assets
common:
  sort: false
  resolve_hosts: false
YAML;

        if ($additionalConfig !== '') {
            $config .= "\n".$additionalConfig;
        }

        return TestCommon::fromConfig($this->writeFile('config.yml', $config."\n"));
    }
}
