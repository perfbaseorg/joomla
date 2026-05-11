<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Lifecycle;

use Mockery;
use Perfbase\Plugin\System\Perfbase\Lifecycle\AbstractJoomlaProfiler;
use Perfbase\Plugin\System\Perfbase\Tests\Helpers\MockFactory;
use Perfbase\SDK\SubmitResult;
use PHPUnit\Framework\TestCase;

class AbstractJoomlaProfilerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        unset($GLOBALS['__perfbase_test_mt_rand'], $GLOBALS['__perfbase_test_mt_getrandmax']);
    }

    public function test_start_and_stop_profile_submits_trace_once(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once()->with('test.span');
        $perfbase->shouldReceive('stopTraceSpan')->once()->with('test.span')->andReturn(true);
        $perfbase->shouldReceive('setAttribute')->atLeast()->once();
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(SubmitResult::success());

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profiler->startProfiling();
        $profiler->stopProfiling();

        self::assertTrue($profiler->isStarted());
    }

    public function test_sample_rate_zero_skips_start(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->never();

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 0.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profiler->startProfiling();

        self::assertFalse($profiler->isStarted());
    }

    public function test_stop_is_idempotent(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(SubmitResult::success());

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profiler->startProfiling();
        $profiler->stopProfiling();
        $profiler->stopProfiling();

        self::assertTrue($profiler->isStarted());
    }

    public function test_submission_failure_is_logged_in_production_mode(): void
    {
        $perfbase = MockFactory::createPerfbase([
            'submitTrace' => SubmitResult::permanentFailure(500, 'bad request'),
        ]);
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 1.0, 'log_errors' => true]) extends AbstractJoomlaProfiler {
            public array $messages = [];

            protected function shouldProfile(): bool
            {
                return true;
            }

            protected function logProfilingError(string $message): void
            {
                $this->messages[] = $message;
            }
        };

        $profiler->startProfiling();
        $profiler->stopProfiling();

        self::assertCount(1, $profiler->messages);
        self::assertStringContainsString('Trace submission failed', $profiler->messages[0]);
    }

    public function test_submission_failure_rethrows_in_debug_mode(): void
    {
        $perfbase = MockFactory::createPerfbase([
            'submitTrace' => SubmitResult::retryableFailure(503, 'temporarily unavailable'),
        ]);
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 1.0, 'debug' => true]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profiler->startProfiling();

        $this->expectException(\Perfbase\SDK\Exception\PerfbaseException::class);

        $profiler->stopProfiling();
    }

    public function test_null_perfbase_and_should_profile_false_are_safe_noops(): void
    {
        $profilerWithoutPerfbase = new class('test.span', null, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profilerWithoutPerfbase->startProfiling();
        $profilerWithoutPerfbase->stopProfiling();

        self::assertFalse($profilerWithoutPerfbase->isStarted());

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->never();

        $profilerSkipped = new class('test.span', $perfbase, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return false;
            }
        };

        $profilerSkipped->startProfiling();

        self::assertFalse($profilerSkipped->isStarted());
    }

    public function test_invalid_sample_rate_and_failed_stop_are_handled_safely(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->never();

        $invalidSampleProfiler = new class('test.span', $perfbase, ['sample_rate' => 'invalid']) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $invalidSampleProfiler->startProfiling();
        self::assertFalse($invalidSampleProfiler->isStarted());

        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(false);
        $perfbase->shouldReceive('submitTrace')->never();

        $failedStopProfiler = new class('test.span', $perfbase, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $failedStopProfiler->startProfiling();
        $failedStopProfiler->stopProfiling();

        self::assertTrue($failedStopProfiler->isStarted());
    }

    public function test_stop_resets_without_submitting_when_trace_should_be_dropped(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->never();
        $perfbase->shouldReceive('reset')->once();

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }

            protected function shouldSubmitTrace(): bool
            {
                return false;
            }
        };

        $profiler->startProfiling();
        $profiler->stopProfiling();

        self::assertTrue($profiler->isStarted());
    }

    public function test_start_is_idempotent_and_cannot_restart_after_stop(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $perfbase->shouldReceive('startTraceSpan')->once();
        $perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
        $perfbase->shouldReceive('submitTrace')->once()->andReturn(SubmitResult::success());

        $profiler = new class('test.span', $perfbase, ['sample_rate' => 1.0]) extends AbstractJoomlaProfiler {
            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profiler->startProfiling();
        $profiler->startProfiling();
        $profiler->stopProfiling();
        $profiler->startProfiling();

        self::assertTrue($profiler->isStarted());
    }

    public function test_set_attributes_skips_nulls_and_fractional_sampling_uses_random_threshold(): void
    {
        $perfbase = MockFactory::createPerfbase();
        $profiler = new class('test.span', $perfbase, ['sample_rate' => 0.5]) extends AbstractJoomlaProfiler {
            public function exposeSetAttributes(array $attributes): void
            {
                $this->setAttributes($attributes);
            }

            public function exposePassesSampleRateCheck(): bool
            {
                return $this->passesSampleRateCheck();
            }

            protected function shouldProfile(): bool
            {
                return true;
            }
        };

        $profiler->exposeSetAttributes([
            'kept' => 'value',
            'skipped' => null,
        ]);

        self::assertSame(['kept' => 'value'], $profiler->getAttributes());

        $GLOBALS['__perfbase_test_mt_rand'] = 0;
        $GLOBALS['__perfbase_test_mt_getrandmax'] = 100;
        self::assertTrue($profiler->exposePassesSampleRateCheck());

        $GLOBALS['__perfbase_test_mt_rand'] = 100;
        $GLOBALS['__perfbase_test_mt_getrandmax'] = 100;
        self::assertFalse($profiler->exposePassesSampleRateCheck());
    }
}
