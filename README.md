# GCWorld Common

`gcworld/common` provides shared configuration and service-loading foundations
for GCWorld applications. It combines YAML-backed configuration with a
per-application singleton, lazy database and Redis connections, path helpers,
and package/project version discovery.

### Version
2.7.20

## Requirements

- PHP 8.4 or newer
- Composer 2
- PDO and the PDO driver required by the configured database
- The Redis PHP extension

## Installation

Install the package with Composer:

```console
composer require gcworld/common
```

## Application common class

Create one project-specific subclass and implement `getEnvironment()` using
the application's `CommonEnvironmentEnumInterface` enum:

```php
<?php

namespace App;

use GCWorld\Common\Common;
use GCWorld\Interfaces\CommonEnvironmentEnumInterface;

final class AppCommon extends Common
{
    protected ?string $configPath = '/path/to/project/config/config.yml';

    public function getEnvironment(): CommonEnvironmentEnumInterface
    {
        return AppEnvironment::LOCAL;
    }
}
```

Resolve the shared instance and its services where needed:

```php
$common = AppCommon::getInstance();

$server = $common->getConfig('server');
$database = $common->getDatabase();
$cache = $common->getCache();
$tempDirectory = $common->getDirectory('temp');
$baseUrl = $common->getPath('base');
```

`getInstance()` keeps a separate instance for each concrete subclass.

## Configuration

Pass a YAML path through the subclass's `$configPath` property. When no path is
set, `CommonConfig` searches upward from the package for
`config/config.yml`. See [config/config.example.yml](config/config.example.yml)
for a complete starting point.

The primary sections are:

- `database`: named MySQL connections, aliases, ports, and TLS options
- `cache`: named standalone Redis or Redis Cluster connections
- `paths.file`: application filesystem paths
- `paths.web`: public base, temporary, and asset-cache paths
- `common.sort`: recursively sort the YAML keys once and reset the option
- `common.resolve_hosts`: resolve values stored under `host` keys

Additional YAML files can be merged through `includes`. Paths are relative to
the main configuration file, and later files override matching values loaded
earlier:

```yaml
includes:
  - database.yml
  - services/cache.yml
```

Configuration caching is enabled by default. After parsing the YAML file,
Common writes a neighboring `.php` cache file and loads that file on later
requests. Remove the generated cache file when changing YAML configuration so
the updated values can be compiled.

Configuration files are trusted application input. Do not allow users to
control configuration paths or file contents.

## Database connections

`getDatabase()` creates a `gcworld/database` connection only when requested
and reuses it for the remainder of the process. The default connection name is
`default`:

```php
$primary = $common->getDatabase();
$reporting = $common->getDatabase('reporting');
```

A database entry can reference another configured entry with `alias`. Circular
aliases are rejected. Use `closeDatabase()` to disconnect and remove a cached
connection.

## Redis connections

`getCache()` supports standalone Redis and Redis Cluster configurations,
including authentication, timeouts, and persistent connections. It returns
`null` when a named cache is not configured.

An `instance:identifier` value creates a distinct cached connection identity
while using the configuration for `instance`:

```php
$defaultCache = $common->getCache();
$workerCache = $common->getCache('default:queue-worker');
```

`closeCache()` removes that connection from Common's in-process cache.

## Paths and versions

`getDirectory()` reads from `paths.file`. `getPath()` reads from `paths.web`
and derives its base URL from `HTTP_HOST` outside CLI requests. Only use that
dynamic base URL when the web server or trusted proxy validates the host
header.

`getCommonVersion()` reads this package's `VERSION` file.
`getProjectVersion()` reads the consuming project's `VERSION` file from the
expected Composer installation layout and falls back to
`COMMON-ONLY:<common-version>` when no project version is available. Pass
`fresh: true` to bypass the in-process project-version cache.

## Local development

The repository includes a Docker Compose environment based on the same PHP
image used by CI:

```console
./dc up -d
./dc exec php composer install
./dc exec php composer check
./dc down
```

Copy `docker-compose.override.yml.example` to the ignored
`docker-compose.override.yml` only when local Composer credentials or SSH keys
are required inside the container.

The Composer quality suite includes syntax checks and PHPStan level 6 analysis.
Run them independently with `composer lint` or `composer phpstan`.

## Releases

Releases use bare semantic-version tags such as `2.7.20`. Before tagging a
release:

1. Move the relevant notes from `Unreleased` to a matching version heading in
   `CHANGELOG.md`.
2. Update `VERSION` and the value immediately below `### Version` in this file.
3. Push the release commit and matching tag.

GitHub Actions validates release metadata and the PHP 8.4/8.5 quality matrix
before creating a GitHub Release from the matching changelog section. Release
tags must not be moved or reused.

## License

This package is proprietary software.
