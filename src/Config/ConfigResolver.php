<?php

namespace Perfbase\Plugin\System\Perfbase\Config;

use Perfbase\SDK\Config;
use Perfbase\SDK\FeatureFlags;

class ConfigResolver
{
    public const DEFAULT_FLAGS = FeatureFlags::DefaultFlags;

    /**
     * The `flags` default shipped before 1.5.0. It predates the extension's
     * current flag set (it sets reserved bits that never had any effect and
     * leaves out HTTP, cache, mail, file and process tracking), so sites still
     * on it are moved to the current default.
     */
    public const LEGACY_DEFAULT_FLAGS = 8145;

    public const DEFAULT_API_URL = 'https://ingress.perfbase.cloud';

    public const DEFAULT_TIMEOUT = 5;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resolve(array $params = []): array
    {
        $config = array_merge($this->getDefaults(), [
            'enabled' => $this->toBool($params['enabled'] ?? null, false),
            'debug' => $this->toBool($params['debug'] ?? null, false),
            'log_errors' => $this->toBool($params['log_errors'] ?? null, true),
            'api_key' => trim((string) ($params['api_key'] ?? '')),
            'api_url' => $this->toString($params['api_url'] ?? null, self::DEFAULT_API_URL),
            'sample_rate' => $this->clampFloat((float) ($params['sample_rate'] ?? 0.1), 0.0, 1.0),
            'profile_http_status_codes' => $this->parseStatusCodeList($params['profile_http_status_codes'] ?? '200-299,500-599'),
            'flags' => $this->resolveFlags($params['flags'] ?? null),
            'timeout' => $this->toPositiveInt($params['timeout'] ?? null, self::DEFAULT_TIMEOUT),
            'proxy' => trim((string) ($params['proxy'] ?? '')),
            'environment' => trim((string) ($params['environment'] ?? '')),
            'app_version' => trim((string) ($params['app_version'] ?? '')),
            'profile_admin' => $this->toBool($params['profile_admin'] ?? null, false),
        ]);

        $config['include'] = [
            'http' => $this->parseList($params['include_http'] ?? '*', ['*']),
            'console' => $this->parseList($params['include_console'] ?? '*', ['*']),
        ];

        $config['exclude'] = [
            'http' => $this->parseList($params['exclude_http'] ?? '', []),
            'console' => $this->parseList($params['exclude_console'] ?? '', []),
        ];

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        return [
            'enabled' => false,
            'debug' => false,
            'log_errors' => true,
            'api_key' => '',
            'api_url' => self::DEFAULT_API_URL,
            'sample_rate' => 0.1,
            'profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
            'flags' => self::DEFAULT_FLAGS,
            'timeout' => self::DEFAULT_TIMEOUT,
            'proxy' => '',
            'environment' => '',
            'app_version' => '',
            'profile_admin' => false,
            'include' => [
                'http' => ['*'],
                'console' => ['*'],
            ],
            'exclude' => [
                'http' => [],
                'console' => [],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    public function validate(array $config): array
    {
        $errors = [];

        if (($config['enabled'] ?? false) && trim((string) ($config['api_key'] ?? '')) === '') {
            $errors['api_key'] = 'API key is required';
        }

        if (!filter_var($config['api_url'] ?? '', FILTER_VALIDATE_URL)) {
            $errors['api_url'] = 'Invalid API URL';
        }

        $sampleRate = (float) ($config['sample_rate'] ?? 0);

        if ($sampleRate < 0.0 || $sampleRate > 1.0) {
            $errors['sample_rate'] = 'Sample rate must be between 0.0 and 1.0';
        }

        $statusCodes = $config['profile_http_status_codes'] ?? [];

        if (!is_array($statusCodes)) {
            $errors['profile_http_status_codes'] = 'HTTP status codes must be a list';
        } else {
            foreach ($statusCodes as $index => $statusCode) {
                if (!is_int($statusCode) || $statusCode < 100 || $statusCode > 599) {
                    $errors[sprintf('profile_http_status_codes.%s', (string) $index)] =
                        'HTTP status codes must be integers between 100 and 599';
                }
            }
        }

        $timeout = (int) ($config['timeout'] ?? 0);

        if ($timeout <= 0) {
            $errors['timeout'] = 'Timeout must be greater than 0';
        }

        $flags = (int) ($config['flags'] ?? 0);

        if ($flags < 0 || ($flags & ~FeatureFlags::ValidFlagsMask) !== 0) {
            $errors['flags'] = 'Invalid flags value';
        }

        foreach (['include', 'exclude'] as $mode) {
            $filterSets = $config[$mode] ?? [];

            if (!is_array($filterSets)) {
                continue;
            }

            foreach ($filterSets as $context => $filters) {
                if (!is_array($filters)) {
                    continue;
                }

                foreach ($filters as $index => $filter) {
                    $filter = (string) $filter;

                    if ($this->isRegexFilter($filter) && !$this->isValidRegex($filter)) {
                        $errors[sprintf('%s.%s.%s', $mode, (string) $context, (string) $index)] = sprintf(
                            'Invalid regex filter: %s',
                            $filter
                        );
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function isEnabled(array $config): bool
    {
        return (bool) ($config['enabled'] ?? false) && trim((string) ($config['api_key'] ?? '')) !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    public function buildSdkConfig(array $config): Config
    {
        $flags = (int) $config['flags'];
        $proxy = trim((string) $config['proxy']);

        return Config::fromArray([
            'api_key' => trim((string) $config['api_key']),
            'api_url' => (string) $config['api_url'],
            'flags' => $flags >= 0 ? $flags & FeatureFlags::ValidFlagsMask : self::DEFAULT_FLAGS,
            'timeout' => (int) $config['timeout'],
            'proxy' => $proxy !== '' ? $proxy : null,
        ]);
    }

    /**
     * Admin form values arrive as strings and may be blank.
     *
     * @param mixed $value
     */
    private function resolveFlags($value): int
    {
        if (is_int($value)) {
            $flags = $value;
        } elseif (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1) {
            $flags = (int) trim($value);
        } else {
            return self::DEFAULT_FLAGS;
        }

        if ($flags === self::LEGACY_DEFAULT_FLAGS) {
            return self::DEFAULT_FLAGS;
        }

        return $flags;
    }

    /**
     * @param mixed $value
     */
    private function toPositiveInt($value, int $default): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : $default;
        }

        if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 && (int) trim($value) > 0) {
            return (int) trim($value);
        }

        return $default;
    }

    /**
     * @param mixed $value
     */
    private function toString($value, string $default): string
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return $default;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : $default;
    }

    /**
     * @param mixed $value
     */
    private function toBool($value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * @param mixed $value
     * @param array<string> $default
     * @return array<string>
     */
    private function parseList($value, array $default): array
    {
        if (is_array($value)) {
            $items = array_map('strval', $value);
        } else {
            $items = preg_split('/\r\n|\r|\n/', (string) $value) ?: [];
        }

        $items = array_values(array_filter(array_map('trim', $items), static fn (string $item): bool => $item !== ''));

        return $items !== [] ? $items : $default;
    }

    /**
     * @param mixed $value
     * @return array<int, int|string>
     */
    private function parseStatusCodeList($value): array
    {
        if ($value === null) {
            return [...range(200, 299), ...range(500, 599)];
        }

        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,]+/', (string) $value) ?: [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (is_int($item)) {
                $normalized[] = $item;
                continue;
            }

            $item = trim((string) $item);

            if ($item === '') {
                continue;
            }

            if (ctype_digit($item)) {
                $normalized[] = (int) $item;
                continue;
            }

            if (preg_match('/^(\d{3})\s*-\s*(\d{3})$/', $item, $matches) === 1) {
                $start = (int) $matches[1];
                $end = (int) $matches[2];

                if ($start <= $end) {
                    foreach (range($start, $end) as $statusCode) {
                        $normalized[] = $statusCode;
                    }

                    continue;
                }
            }

            $normalized[] = $item;
        }

        return array_values(array_unique($normalized, SORT_REGULAR));
    }

    private function clampFloat(float $value, float $min, float $max): float
    {
        return min($max, max($min, $value));
    }

    private function isRegexFilter(string $filter): bool
    {
        return preg_match('/^\/.*\/$/', $filter) === 1;
    }

    private function isValidRegex(string $filter): bool
    {
        return @preg_match($filter, '') !== false;
    }
}
