# GCWorld Common

`gcworld/common` is the shared base library used to build GCWorld applications around a single project-level service object. It provides a common singleton pattern, YAML-backed configuration loading, lazy database and Redis connections, path helpers, and version lookup helpers for both the library and the consuming project.

## What It Provides

- An abstract `Common` base class implementing the shared project service container pattern
- YAML configuration loading through `CommonConfig`
- Optional cached config compilation to a neighboring `.php` file
- Lazy MySQL connection management through `gcworld/database`
- Lazy Redis and Redis Cluster connection management
- File path and web path helpers from config
- Library and project version discovery

## Requirements

- PHP `>=8.4`
- `ext-pdo`
- `ext-redis`

## Installation

```bash
composer require gcworld/common
```

## Usage

Create a project-specific class that extends `GCWorld\Common\Common` and implements `getEnvironment()`.

```php
<?php

namespace App;

use GCWorld\Common\Common;
use GCWorld\Interfaces\CommonEnvironmentEnumInterface;

final class AppCommon extends Common
{
    protected ?string $configPath = '/path/to/config/config.yml';

    public function getEnvironment(): CommonEnvironmentEnumInterface
    {
        return AppEnvironment::LOCAL;
    }
}
```

Then resolve shared services from the singleton instance:

```php
$common = AppCommon::getInstance();

$config = $common->getConfig('server');
$db = $common->getDatabase();
$cache = $common->getCache();
$tempPath = $common->getDirectory('temp');
$baseUrl = $common->getPath('base');
```

## Configuration

`CommonConfig` loads a YAML file and can automatically search upward for `config/config.yml` if no explicit path is provided.

The sample config in [config/config.example.yml](/home/mitch/repositories/Common/config/config.example.yml) shows the expected sections:

- `database`: named MySQL connections, including alias support and SSL options
- `cache`: standalone Redis or Redis Cluster definitions
- `paths.file`: filesystem paths such as `root`, `temp`, and `private`
- `paths.web`: web-facing paths such as `base`, `temp`, and `asset_cache`
- `common.sort`: optional key sorting and write-back of the YAML file
- `common.resolve_hosts`: optional hostname resolution for `host` entries

Included config files are also supported through the YAML `includes` section.

## Behavior Notes

- Database and cache connections are created on first use and cached per instance name.
- Redis cache instances support an `instance:identifier` suffix for distinct persistent connections.
- `getProjectVersion()` looks for a `VERSION` file in the consuming project and falls back to `COMMON-ONLY:<common version>` when used standalone.
- `getCommonVersion()` reads this library's `VERSION` file.

## Version
2.7.19
