<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit;

use Mockery;
use Perfbase\Plugin\System\Perfbase\Config\ConfigResolver;
use Perfbase\Plugin\System\Perfbase\Lifecycle\HttpRequestLifecycle;
use Perfbase\Plugin\System\Perfbase\Tests\Helpers\MockFactory;
use Perfbase\SDK\Exception\PerfbaseException;
use Perfbase\SDK\FeatureFlags;
use PHPUnit\Framework\TestCase;

class RegressionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        unset($_SERVER['REQUEST_URI'], $_SERVER['HTTP_HOST'], $_SERVER['HTTPS']);
    }

    public function test_default_flags_are_the_sdk_defaults_and_validate_cleanly(): void
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve(['enabled' => '1', 'api_key' => 'key']);

        self::assertSame(FeatureFlags::DefaultFlags, $config['flags']);
        self::assertSame([], $resolver->validate($config));
        self::assertArrayNotHasKey('flags', $resolver->validate(['flags' => FeatureFlags::TrackCpuTime | FeatureFlags::TrackPdo] + $config));
        self::assertArrayHasKey('flags', $resolver->validate(['flags' => 1 << 24] + $config));
    }

    public function test_legacy_shipped_flags_value_moves_to_the_current_default(): void
    {
        $resolver = new ConfigResolver();

        self::assertSame(FeatureFlags::DefaultFlags, $resolver->resolve(['flags' => '8145'])['flags']);
        self::assertSame(FeatureFlags::TrackPdo, $resolver->resolve(['flags' => (string) FeatureFlags::TrackPdo])['flags']);
        self::assertSame(0, $resolver->resolve(['flags' => '0'])['flags']);
    }

    public function test_string_and_blank_form_values_produce_a_valid_sdk_config(): void
    {
        $resolver = new ConfigResolver();
        $config = $resolver->resolve([
            'enabled' => '1',
            'api_key' => ' key ',
            'api_url' => '',
            'proxy' => '  ',
            'timeout' => '',
            'flags' => '',
        ]);

        self::assertSame([], $resolver->validate($config));

        $sdkConfig = $resolver->buildSdkConfig($config);

        self::assertSame('key', $sdkConfig->getApiKey());
        self::assertSame('https://ingress.perfbase.cloud', $sdkConfig->getApiUrl());
        self::assertNull($sdkConfig->getProxy());
        self::assertSame(5, $sdkConfig->getTimeout());
        self::assertSame(FeatureFlags::DefaultFlags, $sdkConfig->getFlags());

        $config = $resolver->resolve(['api_key' => 'key', 'timeout' => '12', 'flags' => '2048']);
        $sdkConfig = $resolver->buildSdkConfig($config);

        self::assertSame(12, $sdkConfig->getTimeout());
        self::assertSame(FeatureFlags::TrackPdo, $sdkConfig->getFlags());
    }

    public function test_http_url_keeps_query_string_and_redacts_credentials(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php?method=example&token=secret';
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['HTTPS'] = 'on';

        $lifecycle = $this->startedHttpLifecycle();

        self::assertSame(
            'https://example.test/index.php?method=example&token=REDACTED',
            $lifecycle->getAttributes()['http_url']
        );
        self::assertStringNotContainsString('?', $lifecycle->getAttributes()['action']);
    }

    public function test_http_url_redacts_joomla_form_token_parameter_names(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php?option=com_users&task=user.logout&0123456789abcdef0123456789ABCDEF=1';
        $_SERVER['HTTP_HOST'] = 'example.test';

        $lifecycle = $this->startedHttpLifecycle();

        self::assertSame(
            'http://example.test/index.php?option=com_users&task=user.logout&REDACTED=REDACTED',
            $lifecycle->getAttributes()['http_url']
        );
    }

    public function test_submit_trace_exception_does_not_escape(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('submitTrace')->once()->andThrow(new PerfbaseException('Extension returned empty trace data'));

        $logged = [];
        $lifecycle = new class(new class {
        }, $perfbase, [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ], $logged) extends HttpRequestLifecycle {
            /** @var array<int, string> */
            private array $logged;

            /**
             * @param array<string, mixed> $config
             * @param array<int, string> $logged
             */
            public function __construct(object $application, $perfbase, array $config, array &$logged)
            {
                $this->logged = &$logged;
                parent::__construct($application, $perfbase, $config);
            }

            protected function logProfilingError(string $message): void
            {
                $this->logged[] = $message;
            }
        };

        $lifecycle->startProfiling();
        $lifecycle->stopProfiling();

        self::assertSame(['Perfbase Joomla profiling error in stop: Extension returned empty trace data'], $logged);
    }

    public function test_manifest_and_update_metadata_admit_joomla_four_four_five_and_six(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = simplexml_load_file($root . '/perfbase.xml');
        $updates = simplexml_load_file($root . '/updates/perfbase.xml');

        self::assertNotFalse($manifest);
        self::assertNotFalse($updates);

        foreach ([(string) $manifest->targetplatform['version'], (string) $updates->update->targetplatform['version']] as $pattern) {
            $regex = '/^' . $pattern . '/';

            foreach (['4.4', '5.0', '5.4', '6.0', '6.1'] as $version) {
                self::assertMatchesRegularExpression($regex, $version);
            }

            foreach (['4.3', '7.0'] as $version) {
                self::assertDoesNotMatchRegularExpression($regex, $version);
            }
        }
    }

    private function startedHttpLifecycle(): HttpRequestLifecycle
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();

        $lifecycle = new HttpRequestLifecycle(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        }, $perfbase, [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();

        return $lifecycle;
    }
}
