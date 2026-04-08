# Perfbase for Joomla

Perfbase integration for Joomla via a Joomla system plugin.

`perfbase/joomla` is a thin framework adapter over `perfbase/php-sdk`. It connects Joomla request and console lifecycle events to the Perfbase SDK while leaving extension interaction, payload construction, and submission inside the SDK.

## Support Matrix

| Component | Supported |
|-----------|-----------|
| PHP | `>=8.1 <9.0` |
| Joomla | `4.4` and `5.x` |
| Package type | Joomla system plugin |
| Runtime contexts | HTTP and CLI |
| Perfbase SDK | `^1.0.0` |
| Perfbase PHP extension | Required at runtime |

This package supports:

- Site requests
- Administrator requests when `profile_admin` is enabled
- Joomla API requests as standard HTTP contexts
- Joomla console commands

This package does not currently provide:

- Dedicated Joomla scheduler or task lifecycles
- Deep component or template execution timelines beyond the main trace span
- Browser-level end-to-end instrumentation tests inside full Joomla applications

## Features

- HTTP profiling via Joomla lifecycle hooks
- CLI profiling for Joomla console commands
- Low-cardinality action and span naming
- Sanitized HTTP URLs without query strings
- Include and exclude filters for HTTP and CLI
- Production-safe failure handling
- Shutdown fallback cleanup for interrupted execution paths
- Runtime config validation for malformed adapter settings

## Installation

### 1. Install the package into Joomla

Place the package under:

```text
plugins/system/perfbase
```

### 2. Install Composer dependencies

From the plugin directory:

```bash
composer install --no-dev --prefer-dist
```

### 3. Enable the plugin

In Joomla administrator, enable:

```text
System - Perfbase
```

### 4. Install the Perfbase PHP extension

The plugin depends on the Perfbase PHP extension at runtime. Without the extension, the plugin degrades safely but no traces will be captured.

## Quick Start

1. Install and enable the Perfbase PHP extension.
2. Install this package under `plugins/system/perfbase`.
3. Run `composer install --no-dev --prefer-dist`.
4. Enable `System - Perfbase` in Joomla administrator.
5. Configure `api_key`.
6. Set `enabled = yes`.
7. Start with `sample_rate = 0.1`.
8. Leave `profile_admin = no` until you want administrator coverage explicitly.

## Configuration

The plugin configuration UI is defined in [`perfbase.xml`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/perfbase.xml). Runtime normalization and validation live in [`ConfigResolver.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/src/Config/ConfigResolver.php).

### Core Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Master switch for profiling |
| `api_key` | empty | Required Perfbase project API key |
| `api_url` | `https://receiver.perfbase.com` | Perfbase receiver URL |
| `sample_rate` | `0.1` | Fraction of requests and commands to profile |
| `timeout` | `5` | SDK request timeout in seconds |
| `proxy` | empty | Optional proxy URL |
| `flags` | `FeatureFlags::DefaultFlags` | Perfbase feature-flag bitmask |

### Context Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `profile_admin` | `false` | Include administrator requests |
| `environment` | empty | Optional environment override |
| `app_version` | empty | Optional application-version override |
| `include_http` | `*` | Newline-separated HTTP include filters |
| `exclude_http` | empty | Newline-separated HTTP exclude filters |
| `include_console` | `*` | Newline-separated CLI include filters |
| `exclude_console` | empty | Newline-separated CLI exclude filters |

### Error Handling

| Setting | Default | Description |
|---------|---------|-------------|
| `debug` | `false` | Re-throw profiling errors during development |
| `log_errors` | `true` | Log profiling errors when debug mode is off |

## Filter Syntax

HTTP and CLI filters support:

- `*` or `.*` to match everything
- glob patterns like `cache:*` or `api/*`
- regex patterns like `/^GET /`

Examples:

```text
include_http
*
GET com_content:article:display
api

exclude_http
/administrator/cache/*
/health

include_console
cache:*
database:*
```

Filters are matched against normalized identifiers rather than raw query-heavy URLs.

## Runtime Behavior

### HTTP

The plugin uses:

- `onAfterInitialise`
- `onAfterRoute`
- `onAfterDispatch`
- `onAfterRespond`
- `register_shutdown_function`

HTTP actions are normalized to:

- `METHOD option:view:task` when Joomla route metadata exists
- `METHOD /sanitized/path` when it does not

Examples:

- `GET com_content:article:display`
- `POST /users/{id}`

### CLI

CLI spans are derived from the first non-option command token in `$_SERVER['argv']`.

Example:

- `cache:clean` -> `console.cache.clean`

## Captured Attributes

### HTTP Attributes

- `source=http`
- `action`
- `http_method`
- `http_url`
- `http_status_code`
- `user_ip`
- `user_agent`
- `user_id`
- `hostname`
- `php_version`
- `environment`
- `app_version`
- `joomla.client`
- `joomla.option`
- `joomla.view`
- `joomla.task`
- `joomla.format`

Notes:

- `http_url` excludes the query string
- `action` is low-cardinality by design
- administrator requests are excluded unless `profile_admin` is enabled

### CLI Attributes

- `source=console`
- `action`
- `cli.command`
- `hostname`
- `php_version`
- `environment`
- `app_version`

## Operational Notes

- If the SDK cannot be initialized, the plugin fails open and does not break the host application.
- If the Perfbase extension is unavailable, the plugin no-ops safely.
- Invalid adapter config is validated at boot and logged in production mode.
- In production, profiling errors are logged only when `log_errors` is enabled.
- In development, set `debug = yes` if you want profiling errors to surface immediately.

## Verification

Current package verification:

- PHPUnit: passing
- PHPStan: passing on `src/`
- Overall line coverage: `99.52%`
- Overall method coverage: `98.55%`

Key class coverage:

- [`PerfbasePlugin.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/src/Extension/PerfbasePlugin.php): `100%` methods, `100%` lines
- [`HttpRequestLifecycle.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/src/Lifecycle/HttpRequestLifecycle.php): `100%` methods, `100%` lines
- [`SpanNaming.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/src/Support/SpanNaming.php): `100%` methods, `100%` lines

CI coverage includes:

- supported PHP versions
- PHPUnit
- PHPStan
- Joomla host smoke tests for Joomla `4.4` and `5.x`
- both HTTP and CLI host smoke paths via [`tests/Host/joomla-smoke.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/tests/Host/joomla-smoke.php)

Commands used during development:

```bash
composer run test
./vendor/bin/phpstan analyse --memory-limit=2G --debug src
```

In this sandbox, `composer run phpstan` may fail because PHPStan tries to open a local TCP worker socket. The direct command above was used for the clean static-analysis run.

## Development

```bash
composer install
composer run test
./vendor/bin/phpstan analyse --memory-limit=2G --debug src
```

Important files:

- [`perfbase.xml`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/perfbase.xml)
- [`services/provider.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/services/provider.php)
- [`ConfigResolver.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/src/Config/ConfigResolver.php)
- [`PerfbasePlugin.php`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/src/Extension/PerfbasePlugin.php)
- [`tests/`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/tests)
- [`ci.yml`](/Users/ben/Projects/Perfbase/environment/projects/lib-joomla/.github/workflows/ci.yml)

## License

Apache-2.0
