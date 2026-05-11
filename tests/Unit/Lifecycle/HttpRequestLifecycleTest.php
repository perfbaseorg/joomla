<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Lifecycle;

use Mockery;
use Perfbase\Plugin\System\Perfbase\Lifecycle\HttpRequestLifecycle;
use Perfbase\Plugin\System\Perfbase\Tests\Helpers\MockFactory;
use PHPUnit\Framework\TestCase;

class HttpRequestLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        $_SERVER = [];
        http_response_code(200);
    }

    public function test_should_profile_skips_admin_when_profile_admin_is_disabled(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/administrator/index.php';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'administrator';
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
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->never();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();

        self::assertFalse($lifecycle->isStarted());
    }

    public function test_sets_http_attributes_and_final_status_code(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/index.php?option=com_content&view=article&id=99';
        $_SERVER['HTTP_HOST'] = 'example.test';

        $app = new class {
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
                            'format' => 'html',
                        ];

                        return $values[$key] ?? $default;
                    }
                };
            }

            public function getIdentity(): object
            {
                return (object) ['id' => 42, 'guest' => false];
            }
        };

        $perfbase = MockFactory::createPerfbase();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'environment' => 'staging',
            'app_version' => '1.2.3',
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        $lifecycle->addRoutingContext();
        $lifecycle->addDispatchContext();
        http_response_code(204);
        $lifecycle->addFinalAttributes();

        self::assertSame('http', $lifecycle->getAttributes()['source']);
        self::assertSame('POST com_content:article:display', $lifecycle->getAttributes()['action']);
        self::assertSame('site', $lifecycle->getAttributes()['joomla.client']);
        self::assertSame('42', $lifecycle->getAttributes()['user_id']);
        self::assertSame('204', $lifecycle->getAttributes()['http_status_code']);
    }

    public function test_http_lifecycle_skips_submission_for_disallowed_status_code_by_default(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/missing';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->never();
        $perfbase->shouldReceive('reset')->once();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'profile_http_status_codes' => range(200, 299),
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        http_response_code(404);
        $lifecycle->addFinalAttributes();
        $lifecycle->stopProfiling();

        self::assertSame('404', $lifecycle->getAttributes()['http_status_code']);
    }

    public function test_http_lifecycle_submits_for_default_server_error_status_code(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/error';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(\Perfbase\SDK\SubmitResult::success());

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'profile_http_status_codes' => [...range(200, 299), ...range(500, 599)],
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        http_response_code(503);
        $lifecycle->addFinalAttributes();
        $lifecycle->stopProfiling();

        self::assertSame('503', $lifecycle->getAttributes()['http_status_code']);
    }

    public function test_http_lifecycle_submits_for_custom_allowed_status_code(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/missing';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(\Perfbase\SDK\SubmitResult::success());

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'profile_http_status_codes' => [200, 404],
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        http_response_code(404);
        $lifecycle->addFinalAttributes();
        $lifecycle->stopProfiling();

        self::assertSame('404', $lifecycle->getAttributes()['http_status_code']);
    }

    public function test_extension_unavailable_and_excluded_filters_skip_profiling(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/api/health';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'api';
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
        };

        $perfbase = MockFactory::createPerfbase(['isExtensionAvailable' => false]);
        $perfbase->shouldReceive('startTraceSpan')->never();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['*']],
            'exclude' => ['http' => ['/api/*']],
        ]);

        $lifecycle->startProfiling();

        self::assertFalse($lifecycle->isStarted());
    }

    public function test_http_lifecycle_supports_get_input_fallback_and_guest_users(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/products/123?foo=bar';
        $_SERVER['HTTP_HOST'] = 'secure.example.test';
        $_SERVER['HTTPS'] = 'on';

        $app = new class {
            public object $input;

            public function __construct()
            {
                $this->input = new class {
                    public function get(string $key, string $default = '', string $filter = 'cmd'): string
                    {
                        $values = [
                            'format' => 'json',
                        ];

                        return $values[$key] ?? $default;
                    }
                };
            }

            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getIdentity(): object
            {
                return (object) ['id' => 0, 'guest' => true];
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['GET /products/*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        $lifecycle->addDispatchContext();

        self::assertTrue($lifecycle->isStarted());
        self::assertSame('GET /products/{id}', $lifecycle->getAttributes()['action']);
        self::assertSame('https://secure.example.test/products/{id}', $lifecycle->getAttributes()['http_url']);
        self::assertSame('', $lifecycle->getAttributes()['user_id']);
        self::assertSame('json', $lifecycle->getAttributes()['joomla.format']);
    }

    public function test_http_lifecycle_defaults_when_application_has_minimal_context(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        $app = new class {
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['site', 'GET /']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        $lifecycle->addDispatchContext();
        $lifecycle->addFinalAttributes();

        self::assertTrue($lifecycle->isStarted());
        self::assertSame('site', $lifecycle->getAttributes()['joomla.client']);
        self::assertSame('/', $lifecycle->getAttributes()['http_url']);
        self::assertSame('', $lifecycle->getAttributes()['joomla.option']);
    }

    public function test_disabled_http_lifecycle_skips_profiling(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->never();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => false,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();

        self::assertFalse($lifecycle->isStarted());
    }

    public function test_http_lifecycle_handles_non_object_identity_and_input_without_getters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/health';

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }

            public function getInput(): object
            {
                return new class {
                };
            }

            public function getIdentity(): string
            {
                return 'anonymous';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['site']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();
        $lifecycle->addDispatchContext();

        self::assertTrue($lifecycle->isStarted());
        self::assertSame('', $lifecycle->getAttributes()['user_id']);
        self::assertSame('', $lifecycle->getAttributes()['joomla.option']);
        self::assertArrayNotHasKey('joomla.format', $lifecycle->getAttributes());
    }

    public function test_invalid_host_header_is_excluded_from_http_url(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/orders/123?foo=bar';
        $_SERVER['HTTP_HOST'] = "evil.test/attacker";

        $app = new class {
            public function isClient(string $name): bool
            {
                return $name === 'site';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();

        $lifecycle = new HttpRequestLifecycle($app, $perfbase, [
            'enabled' => true,
            'profile_admin' => false,
            'sample_rate' => 1.0,
            'include' => ['http' => ['GET /orders/*']],
            'exclude' => ['http' => []],
        ]);

        $lifecycle->startProfiling();

        self::assertSame('/orders/{id}', $lifecycle->getAttributes()['http_url']);
    }
}
