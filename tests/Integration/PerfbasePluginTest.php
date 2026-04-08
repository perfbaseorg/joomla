<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Integration;

use Joomla\Event\DispatcherInterface;
use Mockery;
use Perfbase\Plugin\System\Perfbase\Config\ConfigResolver;
use Perfbase\Plugin\System\Perfbase\Extension\PerfbasePlugin;
use Perfbase\Plugin\System\Perfbase\Tests\Helpers\MockFactory;
use PHPUnit\Framework\TestCase;

class PerfbasePluginTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        $_SERVER = [];
        http_response_code(200);
    }

    public function test_disabled_plugin_does_not_start_a_lifecycle(): void
    {
        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve(['enabled' => false]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, null) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        });

        $plugin->onAfterInitialise();

        self::assertNull($plugin->getActiveLifecycle());
    }

    public function test_http_request_runs_start_and_stop_once(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php?option=com_content&view=article';

        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(\Perfbase\SDK\SubmitResult::success());

        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'sample_rate' => 1.0,
                    'include_http' => '*',
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, $perfbase) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getInput(): object
            {
                return new class {
                    public function getCmd(string $key, string $default = ''): string
                    {
                        $values = [
                            'option' => 'com_content',
                            'view' => 'article',
                            'task' => 'display',
                        ];

                        return $values[$key] ?? $default;
                    }
                };
            }

            public function getIdentity(): object
            {
                return (object) ['id' => 7, 'guest' => false];
            }
        });

        $plugin->onAfterInitialise();
        $plugin->onAfterRoute();
        $plugin->onAfterDispatch();
        $plugin->onAfterRespond();
        $plugin->onShutdownFallback();

        self::assertNotNull($plugin->getActiveLifecycle());
        self::assertTrue($plugin->getActiveLifecycle()->isStarted());
    }

    public function test_cli_request_uses_cli_lifecycle_and_shutdown_cleanup(): void
    {
        $_SERVER['argv'] = ['joomla.php', 'cache:clean'];

        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(\Perfbase\SDK\SubmitResult::success());

        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'sample_rate' => 1.0,
                    'include_console' => '*',
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, $perfbase) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'cli';
            }

            public function getName(): string
            {
                return 'joomla';
            }
        });

        $plugin->onAfterInitialise();
        $plugin->onShutdownFallback();

        self::assertNotNull($plugin->getActiveLifecycle());
        self::assertSame('console.cache.clean', $plugin->getActiveLifecycle()->getSpanName());
    }

    public function test_plugin_safely_skips_when_application_is_missing(): void
    {
        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                ]);
            }
        };

        $plugin = new PerfbasePlugin($dispatcher, [], $resolver, MockFactory::createPerfbase());
        $plugin->onAfterInitialise();
        $plugin->onAfterRoute();
        $plugin->onAfterDispatch();
        $plugin->onAfterRespond();

        self::assertNull($plugin->getActiveLifecycle());
    }

    public function test_invalid_sdk_config_degrades_gracefully(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'api_url' => 'not-a-valid-url',
                    'sample_rate' => 1.0,
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, null) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getInput(): object
            {
                return new class {
                    public function getCmd(string $key, string $default = ''): string
                    {
                        return '';
                    }
                };
            }
        });

        $plugin->onAfterInitialise();

        self::assertNull($plugin->getPerfbase());
        self::assertNotNull($plugin->getActiveLifecycle());
        self::assertFalse($plugin->getActiveLifecycle()->isStarted());
    }

    public function test_repeated_initialise_and_shutdown_are_idempotent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(\Perfbase\SDK\SubmitResult::success());

        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'sample_rate' => 1.0,
                    'include_http' => '*',
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, $perfbase) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getInput(): object
            {
                return new class {
                    public function getCmd(string $key, string $default = ''): string
                    {
                        return '';
                    }
                };
            }
        });

        $plugin->onAfterInitialise();
        $plugin->onAfterInitialise();
        $plugin->onAfterRespond();
        $plugin->onShutdownFallback();
        $plugin->onShutdownFallback();

        self::assertSame([], array_diff(['enabled', 'api_key'], array_keys($plugin->getResolvedConfig())));
        self::assertSame($perfbase, $plugin->getPerfbase());
    }

    public function test_initialise_rethrows_in_debug_mode_when_context_detection_fails(): void
    {
        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'debug' => true,
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, MockFactory::createPerfbase()) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                throw new \RuntimeException('Unable to resolve client.');
            }
        });

        $this->expectException(\RuntimeException::class);

        $plugin->onAfterInitialise();
    }

    public function test_boot_uses_empty_params_when_cms_params_do_not_expose_to_array(): void
    {
        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return $params;
            }
        };

        $plugin = new class($dispatcher, [], $resolver, MockFactory::createPerfbase()) extends PerfbasePlugin {
            public function injectRawParams(mixed $params): void
            {
                $this->params = $params;
            }
        };

        $plugin->injectRawParams((object) ['enabled' => true]);
        $plugin->onAfterInitialise();

        self::assertSame([], $plugin->getResolvedConfig());
    }

    public function test_register_shutdown_fallback_is_idempotent(): void
    {
        $plugin = new PerfbasePlugin(
            Mockery::mock(DispatcherInterface::class),
            [],
            new ConfigResolver(),
            MockFactory::createPerfbase()
        );

        $method = new \ReflectionMethod(PerfbasePlugin::class, 'registerShutdownFallback');
        $method->setAccessible(true);
        $method->invoke($plugin);
        $method->invoke($plugin);

        $property = new \ReflectionProperty(PerfbasePlugin::class, 'shutdownRegistered');
        $property->setAccessible(true);

        self::assertTrue($property->getValue($plugin));
    }

    public function test_shutdown_fallback_submits_after_partial_http_enrichment(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/index.php?option=com_content&view=article';

        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(\Perfbase\SDK\SubmitResult::success());

        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'sample_rate' => 1.0,
                    'include_http' => '*',
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, $perfbase) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getInput(): object
            {
                return new class {
                    public function getCmd(string $key, string $default = ''): string
                    {
                        $values = [
                            'option' => 'com_content',
                            'view' => 'article',
                            'task' => 'display',
                        ];

                        return $values[$key] ?? $default;
                    }
                };
            }
        });

        $plugin->onAfterInitialise();
        $plugin->onAfterRoute();
        $plugin->onShutdownFallback();

        self::assertNotNull($plugin->getActiveLifecycle());
        self::assertSame('GET com_content:article:display', $plugin->getActiveLifecycle()->getAttributes()['action']);
    }

    public function test_start_profiling_exception_degrades_gracefully_in_production_mode(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $dispatcher = Mockery::mock(DispatcherInterface::class);
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('isExtensionAvailable')->once()->andThrow(new \RuntimeException('Extension probe failed.'));
        $perfbase->shouldReceive('startTraceSpan')->never();

        $resolver = new class extends ConfigResolver {
            public function resolve(array $params = []): array
            {
                return parent::resolve([
                    'enabled' => true,
                    'api_key' => 'test-key',
                    'sample_rate' => 1.0,
                    'include_http' => '*',
                ]);
            }
        };

        $plugin = new class($dispatcher, [], $resolver, $perfbase) extends PerfbasePlugin {
            public function injectApplication(object $application): void
            {
                $this->application = $application;
            }
        };

        $plugin->injectApplication(new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getInput(): object
            {
                return new class {
                    public function getCmd(string $key, string $default = ''): string
                    {
                        return '';
                    }
                };
            }
        });

        $plugin->onAfterInitialise();

        self::assertNotNull($plugin->getActiveLifecycle());
        self::assertFalse($plugin->getActiveLifecycle()->isStarted());
    }
}
