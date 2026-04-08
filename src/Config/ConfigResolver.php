<?php

namespace Perfbase\Plugin\System\Perfbase\Config;

use Perfbase\SDK\Config;
use Perfbase\SDK\FeatureFlags;

class ConfigResolver
{
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
            'api_url' => trim((string) ($params['api_url'] ?? 'https://receiver.perfbase.com')),
            'sample_rate' => $this->clampFloat((float) ($params['sample_rate'] ?? 0.1), 0.0, 1.0),
            'flags' => (int) ($params['flags'] ?? FeatureFlags::DefaultFlags),
            'timeout' => (int) ($params['timeout'] ?? 5),
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
            'api_url' => 'https://receiver.perfbase.com',
            'sample_rate' => 0.1,
            'flags' => FeatureFlags::DefaultFlags,
            'timeout' => 5,
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

        $timeout = (int) ($config['timeout'] ?? 0);

        if ($timeout <= 0) {
            $errors['timeout'] = 'Timeout must be greater than 0';
        }

        $flags = (int) ($config['flags'] ?? 0);

        if ($flags < 0 || $flags > FeatureFlags::AllFlags) {
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
        return Config::fromArray([
            'api_key' => (string) $config['api_key'],
            'api_url' => (string) $config['api_url'],
            'flags' => (int) $config['flags'],
            'timeout' => (int) $config['timeout'],
            'proxy' => trim((string) $config['proxy']) !== '' ? (string) $config['proxy'] : null,
        ]);
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
