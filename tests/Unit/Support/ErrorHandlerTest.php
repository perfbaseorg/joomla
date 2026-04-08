<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Support;

use Perfbase\Plugin\System\Perfbase\Support\ErrorHandler;
use PHPUnit\Framework\TestCase;

class ErrorHandlerTest extends TestCase
{
    public function test_debug_mode_rethrows(): void
    {
        $handler = new class {
            use ErrorHandler;

            public function trigger(\Throwable $e, array $config): void
            {
                $this->handleProfilingError($e, $config, 'test');
            }
        };

        $this->expectException(\RuntimeException::class);

        $handler->trigger(new \RuntimeException('boom'), ['debug' => true, 'log_errors' => true]);
    }

    public function test_production_mode_logs_when_enabled(): void
    {
        $handler = new class {
            use ErrorHandler;

            public string $message = '';

            public function trigger(\Throwable $e, array $config): void
            {
                $this->handleProfilingError($e, $config, 'test');
            }

            protected function logProfilingError(string $message): void
            {
                $this->message = $message;
            }
        };

        $handler->trigger(new \RuntimeException('boom'), ['debug' => false, 'log_errors' => true]);

        self::assertStringContainsString('boom', $handler->message);
        self::assertStringContainsString('test', $handler->message);
    }
}
