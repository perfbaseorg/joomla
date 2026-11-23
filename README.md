<p align="center">
  <a href="https://perfbase.com">
    <img src="https://cdn.perfbase.com/img/logo-full.svg" alt="Perfbase" width="300">
  </a>
</p>

<h3 align="center">Perfbase for Joomla</h3>
<p align="center">
  Joomla integration for <a href="https://perfbase.com">Perfbase</a>.
</p>

<p align="center">
  <a href="https://packagist.org/packages/perfbase/joomla"><img src="https://img.shields.io/packagist/v/perfbase/joomla" alt="Packagist Version"></a>
  <a href="https://github.com/perfbaseorg/joomla/blob/main/LICENSE.txt"><img src="https://img.shields.io/packagist/l/perfbase/joomla" alt="License"></a>
  <a href="https://github.com/perfbaseorg/joomla/actions/workflows/ci.yml"><img src="https://img.shields.io/github/actions/workflow/status/perfbaseorg/joomla/ci.yml?branch=main" alt="CI"></a>
  <img src="https://img.shields.io/badge/php-8.1%2B-blue" alt="PHP Version">
  <img src="https://img.shields.io/badge/joomla-4.4--6.x-blue" alt="Joomla Version">
</p>

This package is a thin adapter over [`perfbase/php-sdk`](https://packagist.org/packages/perfbase/php-sdk). It connects Joomla request and console lifecycle events to the Perfbase SDK while leaving extension interaction, payload construction, and submission to the shared SDK.

## Support Matrix

| Component | Supported |
|-----------|-----------|
| PHP | `>=8.1 <8.7` (Joomla 6 itself requires PHP 8.3+) |
| Joomla | `4.4`, `5.x` and `6.x` |
| Package type | Joomla system plugin |
| Runtime contexts | HTTP and CLI |
| Perfbase SDK | `^1.9` |
| Perfbase PHP extension | Required at runtime |

This package supports:

- Site requests
- Administrator requests when `profile_admin` is enabled
- Joomla API requests as standard HTTP contexts
- CLI applications that trigger `onAfterInitialise` (Joomla's own console application does not; see [CLI](#cli))

This package does not currently provide:

- Dedicated Joomla scheduler or task lifecycles
- Deep component or template execution timelines beyond the main trace span
- End-to-end instrumentation tests inside full Joomla application installs

## Features

- HTTP profiling via Joomla lifecycle hooks
- CLI profiling for applications that trigger `onAfterInitialise`
- Low-cardinality action and span naming
- Full HTTP URLs with credential-looking query values redacted
- Include and exclude filters for HTTP and CLI
- Production-safe failure handling
- Shutdown fallback cleanup for interrupted execution paths
- Runtime config validation for malformed adapter settings

## Installation

### 1. Download the installable ZIP

Download the `perfbase-joomla-<version>.zip` artifact from the matching GitHub Release.

Release ZIPs are self-contained Joomla plugin artifacts. They include the production `perfbase/php-sdk` dependency under the plugin's own `vendor/` directory, so normal Joomla Extension Manager installs do not require a Composer install in the Joomla root.

### 2. Install the ZIP in Joomla

In Joomla administrator, go to:

```text
System -> Install -> Extensions -> Upload Package File
```

Upload the release ZIP.

### Updates

The plugin registers a Joomla update server:

```text
https://raw.githubusercontent.com/perfbaseorg/joomla/main/updates/perfbase.xml
```

After the first ZIP install, Joomla can discover future releases through the administrator extension update UI. Release metadata points Joomla at the matching GitHub Release ZIP.

### 3. Enable the plugin

In Joomla administrator, enable:

```text
System - Perfbase
```

### 4. Install the Perfbase PHP extension

The plugin depends on the Perfbase PHP extension at runtime. Without the extension, the plugin degrades safely but no traces will be captured.

### Development install

For local development, place the source checkout under:

```text
plugins/system/perfbase
```

Then install dependencies from the plugin directory:

```bash
composer install --no-dev --prefer-dist
```

## Quick Start

1. Install and enable the Perfbase PHP extension.
2. Install the release ZIP through Joomla Extension Manager.
3. Enable `System - Perfbase` in Joomla administrator.
4. Configure `api_key`.
5. Set `enabled = yes`.
6. Start with `sample_rate = 0.1`.
7. Leave `profile_admin = no` until you want administrator coverage explicitly.

## Configuration

The plugin configuration UI is defined in [`perfbase.xml`](./perfbase.xml). Runtime normalization and validation live in [`ConfigResolver.php`](./src/Config/ConfigResolver.php).

### Core Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `false` | Master switch for profiling |
| `api_key` | empty | Required Perfbase project API key |
| `api_url` | `https://ingress.perfbase.cloud` | Perfbase receiver URL |
| `sample_rate` | `0.1` | Fraction of requests and commands to profile |
| `timeout` | `5` | SDK request timeout in seconds |
| `proxy` | empty | Optional proxy URL |
| `flags` | `16775265` | Perfbase feature-flag bitmask; defaults to the SDK's `FeatureFlags::DefaultFlags` (see [Feature Flags](#feature-flags)) |

### Context Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `profile_admin` | `false` | Include administrator requests |
| `profile_http_status_codes` | `200-299,500-599` | HTTP status codes that should be submitted |
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

### Feature Flags

`flags` is a bitmask passed to the SDK and the Perfbase extension. The default, `16775265` (`FeatureFlags::DefaultFlags`), enables every feature except per-function CPU time and memory allocation tracking. Sites still on the old default, `8145`, are treated as the current default.

| Flag | Value | Notes |
|---|---:|---|
| `UsePreciseClock` | `1` | Opt-in. High-resolution wall timing on hosts without a fast default clock |
| `TrackCpuTime` | `4` | Opt-in. Per-function CPU time (adds a syscall per call) |
| `TrackMemoryAllocation` | `8` | Per-function memory allocation |
| `TrackExceptions` | `32` | Exception throws |
| `TrackFileCompilation` | `64` | `include` / `require` compilation |
| `TrackPdo` | `2048` | PDO, mysqli and legacy mysql queries |
| `TrackMongodb` | `4096` | MongoDB operations |
| `TrackElasticsearch` | `8192` | Elasticsearch operations |
| `TrackCaches` | `16384` | Redis and Memcached |
| `TrackHttp` | `32768` | Outbound HTTP (cURL, Guzzle) |
| `TrackMail` | `65536` | `mail()` calls |
| `TrackFileOperations` | `131072` | File I/O |
| `TrackProc` | `262144` | Process execution and the sleep family |
| `TrackProcessList` | `524288` | Process-list snapshot at trace end |
| `TrackErrors` | `1048576` | PHP errors, warnings and notices |
| `TrackMagicMethods` | `2097152` | Magic method call counts |
| `TrackOpcache` | `4194304` | OPcache statistics |
| `TrackStreams` | `8388608` | Stream errors (PHP 8.6+, inert before) |

`0`, or a mask containing only opt-in flags, means "every feature except the opt-in flags" plus any opt-in flags set. Any mask containing a regular flag enables exactly those features. Blank or non-numeric `flags` and `timeout` values fall back to their defaults, and a blank `api_url` falls back to `https://ingress.perfbase.cloud`.

`profile_http_status_codes` accepts comma-separated values, newline-separated values, and ranges such as `200-299,500-599` or `200,201,404`. The runtime config is normalized to an integer allowlist. Leave it blank if you want to disable HTTP trace submission entirely.

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

By default, 2xx and 5xx HTTP responses are submitted. A 404 will still be profiled in-process, but the trace is discarded unless `profile_http_status_codes` includes `404`.

### CLI

CLI profiling starts from `onAfterInitialise` when the application reports the `cli` client. Joomla's `cli/joomla.php` console application does not trigger `onAfterInitialise` (it dispatches the framework's `application.*` and `console.*` events instead), so Joomla console commands are not currently traced.

CLI actions are derived from the first non-option command token in `$_SERVER['argv']`.
Each CLI trace uses the stable span name `cli`. Earlier releases named this span `artisan`.

Example:

- `cache:clean` -> `action=cache:clean`

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

- `http_url` is the full request URL including the query string, passed through the SDK's `UrlSanitizer`: values of credential-looking parameters (`token`, `password`, `api_key`, `signature`, `nonce`, session IDs, `email`, and similar) are replaced with `REDACTED`, user info and fragments are dropped, and Joomla CSRF form tokens (sent as a 32-character hex parameter name) are redacted
- `action` never contains the query string
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
- Submission failures, including a `PerfbaseException` thrown by `submitTrace()`, are caught and logged; they never reach the application.
- If the Perfbase extension is unavailable, the plugin no-ops safely.
- When profiling is enabled with an API key, invalid adapter config is validated at boot and logged in production mode.
- In production, profiling errors are logged only when `log_errors` is enabled.
- In development, set `debug = yes` if you want profiling errors to surface immediately.

## Verification

Current package verification:

- PHPUnit: passing
- PHPStan: passing on `src/`
CI coverage includes:

- PHP `8.1`, `8.3`, `8.5` and `8.6`
- PHPUnit
- PHPStan
- Joomla framework-package smoke checks for the supported `4.4`, `5.x` and `6.x` dependency lines (`joomla/di` and `joomla/event` `2.x`, `3.x` and `4.x`)
- both HTTP and CLI smoke paths via [`tests/Host/joomla-smoke.php`](./tests/Host/joomla-smoke.php)
- provider autoload coverage via [`tests/Host/provider-autoload-smoke.php`](./tests/Host/provider-autoload-smoke.php)
- local CMS stubs layered on top of `joomla/di` and `joomla/event`

The smoke job is not a full `joomla/cms` application install. It validates adapter compatibility against the supported Joomla framework-package lines plus local CMS stubs.

Commands used during development:

```bash
composer run test
composer run test:coverage   # needs pcov or Xdebug
./vendor/bin/phpstan analyse --memory-limit=2G --debug src
```

Release artifacts are built automatically when a `v<semver>` tag is pushed on a commit reachable from `main`. The release workflow stamps the staged ZIP manifest from the tag version, builds `dist/perfbase-joomla-<version>.zip`, verifies the ZIP contents against the tag, attaches it to the GitHub Release, and commits updated Joomla update metadata to `updates/perfbase.xml` on `main`.

To build the same ZIP locally:

```bash
bin/build-release-zip v1.0.0
bin/verify-release-zip dist/perfbase-joomla-1.0.0.zip
bin/update-joomla-update-metadata v1.0.0
bin/verify-joomla-update-metadata
```

## Development

```bash
composer install
composer run test
./vendor/bin/phpstan analyse --memory-limit=2G --debug src
```

Important files:

- [`perfbase.xml`](./perfbase.xml)
- [`services/provider.php`](./services/provider.php)
- [`updates/perfbase.xml`](./updates/perfbase.xml)
- [`ConfigResolver.php`](./src/Config/ConfigResolver.php)
- [`PerfbasePlugin.php`](./src/Extension/PerfbasePlugin.php)
- [`tests/`](./tests)
- [`ci.yml`](./.github/workflows/ci.yml)

## Documentation

Full documentation is available at [perfbase.com/docs](https://perfbase.com/docs).

- **Docs**: [perfbase.com/docs](https://perfbase.com/docs)
- **Issues**: [github.com/perfbaseorg/joomla/issues](https://github.com/perfbaseorg/joomla/issues)
- **Support**: [support@perfbase.com](mailto:support@perfbase.com)

## License

Apache-2.0. See [LICENSE.txt](LICENSE.txt).
