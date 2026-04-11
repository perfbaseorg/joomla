<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Config;

use Perfbase\Plugin\System\Perfbase\Config\ConfigResolver;
use Perfbase\SDK\FeatureFlags;
use PHPUnit\Framework\TestCase;

class ConfigResolverTest extends TestCase
{
    public function test_resolve_supports_array_filter_input_and_boolean_defaults(): void
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve([
            'enabled' => 'not-a-bool',
            'log_errors' => '',
            'include_http' => ['*', ' api/* '],
            'exclude_console' => [' ', 'cache:warm'],
        ]);

        self::assertFalse($config['enabled']);
        self::assertTrue($config['log_errors']);
        self::assertSame(['*', 'api/*'], $config['include']['http']);
        self::assertSame(['cache:warm'], $config['exclude']['console']);
    }

    public function test_resolve_clamps_sample_rate_into_supported_range(): void
    {
        $resolver = new ConfigResolver();

        self::assertSame(1.0, $resolver->resolve(['sample_rate' => 2.0])['sample_rate']);
        self::assertSame(0.0, $resolver->resolve(['sample_rate' => -1.0])['sample_rate']);
    }

    public function test_resolve_returns_expected_defaults(): void
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve();

        self::assertFalse($config['enabled']);
        self::assertSame('https://ingress.perfbase.cloud', $config['api_url']);
        self::assertSame(0.1, $config['sample_rate']);
        self::assertSame(['*'], $config['include']['http']);
        self::assertSame(['*'], $config['include']['console']);
        self::assertSame([], $config['exclude']['http']);
        self::assertSame(FeatureFlags::DefaultFlags, $config['flags']);
    }

    public function test_resolve_normalizes_flat_params_to_nested_filter_config(): void
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve([
            'enabled' => '1',
            'profile_admin' => '1',
            'include_http' => "site\ncom_content:*",
            'exclude_http' => "/secret/*\n/health",
            'include_console' => "cache:clean\nqueue:*",
            'exclude_console' => "cache:warm",
        ]);

        self::assertTrue($config['enabled']);
        self::assertTrue($config['profile_admin']);
        self::assertSame(['site', 'com_content:*'], $config['include']['http']);
        self::assertSame(['/secret/*', '/health'], $config['exclude']['http']);
        self::assertSame(['cache:clean', 'queue:*'], $config['include']['console']);
        self::assertSame(['cache:warm'], $config['exclude']['console']);
    }

    public function test_validate_returns_errors_for_invalid_values(): void
    {
        $resolver = new ConfigResolver();

        $errors = $resolver->validate([
            'enabled' => true,
            'api_key' => '',
            'api_url' => 'not-a-url',
            'sample_rate' => 1.5,
            'timeout' => 0,
            'flags' => FeatureFlags::AllFlags + 1,
        ]);

        self::assertArrayHasKey('api_key', $errors);
        self::assertArrayHasKey('api_url', $errors);
        self::assertArrayHasKey('sample_rate', $errors);
        self::assertArrayHasKey('timeout', $errors);
        self::assertArrayHasKey('flags', $errors);
    }

    public function test_validate_returns_errors_for_invalid_regex_filters(): void
    {
        $resolver = new ConfigResolver();

        $errors = $resolver->validate([
            'enabled' => true,
            'api_key' => 'test-key',
            'api_url' => 'https://ingress.perfbase.cloud',
            'sample_rate' => 0.5,
            'timeout' => 5,
            'flags' => FeatureFlags::DefaultFlags,
            'include' => [
                'http' => ['/[invalid/'],
                'console' => [],
            ],
            'exclude' => [
                'http' => [],
                'console' => ['/console(/'],
            ],
        ]);

        self::assertArrayHasKey('include.http.0', $errors);
        self::assertArrayHasKey('exclude.console.0', $errors);
    }

    public function test_build_sdk_config_maps_proxy_to_null_and_is_enabled_requires_api_key(): void
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve([
            'enabled' => '1',
            'api_key' => 'test-key',
            'proxy' => '',
            'timeout' => 7,
        ]);

        $sdkConfig = $resolver->buildSdkConfig($config);

        self::assertTrue($resolver->isEnabled($config));
        self::assertSame('test-key', $this->readSdkConfigValue($sdkConfig, 'api_key'));
        self::assertSame('https://ingress.perfbase.cloud', $this->readSdkConfigValue($sdkConfig, 'api_url'));
        self::assertNull($this->readSdkConfigValue($sdkConfig, 'proxy'));
        self::assertSame(7, $this->readSdkConfigValue($sdkConfig, 'timeout'));
        self::assertFalse($resolver->isEnabled(['enabled' => true, 'api_key' => '']));
    }

    private function readSdkConfigValue(object $sdkConfig, string $property): mixed
    {
        $reflection = new \ReflectionProperty($sdkConfig, $property);
        $reflection->setAccessible(true);

        return $reflection->getValue($sdkConfig);
    }
}
