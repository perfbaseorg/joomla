<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Lifecycle;

use Mockery;
use Perfbase\Plugin\System\Perfbase\Lifecycle\CliLifecycle;
use Perfbase\Plugin\System\Perfbase\Tests\Helpers\MockFactory;
use PHPUnit\Framework\TestCase;

class CliLifecycleTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        unset($_SERVER['argv']);
    }

    public function test_cli_filters_use_derived_command_name(): void
    {
        $_SERVER['argv'] = ['joomla.php', '--env=prod', 'cache:clean'];

        $app = new class {
            public function getName(): string
            {
                return 'joomla';
            }
        };

        $perfbase = MockFactory::createPerfbase();

        $lifecycle = new CliLifecycle($app, $perfbase, [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => ['console' => ['cache:*']],
            'exclude' => ['console' => []],
        ]);

        $lifecycle->startProfiling();

        self::assertTrue($lifecycle->isStarted());
        self::assertSame('cache:clean', $lifecycle->getAttributes()['action']);
        self::assertSame('console', $lifecycle->getAttributes()['source']);
    }

    public function test_cli_filters_can_skip_and_command_can_fall_back_to_application_name(): void
    {
        $_SERVER['argv'] = ['joomla.php', '--env=prod'];

        $app = new class {
            public function getName(): string
            {
                return 'joomla';
            }
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->never();

        $lifecycle = new CliLifecycle($app, $perfbase, [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => ['console' => ['cache:*']],
            'exclude' => ['console' => []],
        ]);

        $lifecycle->startProfiling();

        self::assertFalse($lifecycle->isStarted());
    }

    public function test_cli_uses_unknown_when_no_command_or_application_name_exists(): void
    {
        $_SERVER['argv'] = 'not-an-array';

        $app = new class {
        };

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();

        $lifecycle = new CliLifecycle($app, $perfbase, [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => ['console' => ['*']],
            'exclude' => ['console' => []],
        ]);

        $lifecycle->startProfiling();

        self::assertSame('unknown', $lifecycle->getAttributes()['action']);
        self::assertSame('console_unknown', $lifecycle->getSpanName());
    }

    public function test_cli_disabled_or_missing_extension_skips_start(): void
    {
        $_SERVER['argv'] = ['joomla.php', 'cache:clean'];

        $app = new class {
            public function getName(): string
            {
                return 'joomla';
            }
        };

        $disabledPerfbase = MockFactory::createPerfbase();
        $disabledPerfbase->shouldReceive('startTraceSpan')->never();

        $disabledLifecycle = new CliLifecycle($app, $disabledPerfbase, [
            'enabled' => false,
            'sample_rate' => 1.0,
            'include' => ['console' => ['*']],
            'exclude' => ['console' => []],
        ]);

        $disabledLifecycle->startProfiling();
        self::assertFalse($disabledLifecycle->isStarted());

        $missingExtensionPerfbase = MockFactory::createPerfbase(['isExtensionAvailable' => false]);
        $missingExtensionPerfbase->shouldReceive('startTraceSpan')->never();

        $missingExtensionLifecycle = new CliLifecycle($app, $missingExtensionPerfbase, [
            'enabled' => true,
            'sample_rate' => 1.0,
            'include' => ['console' => ['*']],
            'exclude' => ['console' => []],
        ]);

        $missingExtensionLifecycle->startProfiling();
        self::assertFalse($missingExtensionLifecycle->isStarted());
    }
}
