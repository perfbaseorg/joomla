<?php

namespace Perfbase\Plugin\System\Perfbase\Lifecycle;

use Perfbase\Plugin\System\Perfbase\Support\ErrorHandler;
use Perfbase\SDK\Exception\PerfbaseException;
use Perfbase\SDK\Perfbase;

abstract class AbstractJoomlaProfiler
{
    use ErrorHandler;

    protected ?Perfbase $perfbase;

    /**
     * @var array<string, mixed>
     */
    protected array $config;

    /**
     * @var array<string, string>
     */
    protected array $attributes = [];

    protected string $spanName;

    protected bool $started = false;

    protected bool $stopped = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(string $spanName, ?Perfbase $perfbase, array $config)
    {
        $this->spanName = $spanName;
        $this->perfbase = $perfbase;
        $this->config = $config;
    }

    public function startProfiling(): void
    {
        if ($this->perfbase === null || $this->started || $this->stopped) {
            return;
        }

        try {
            if (!$this->passesSampleRateCheck() || !$this->shouldProfile()) {
                return;
            }

            $this->perfbase->startTraceSpan($this->spanName);
            $this->started = true;
            $this->setDefaultAttributes();
        } catch (\Throwable $e) {
            $this->handleProfilingError($e, $this->config, 'start');
        }
    }

    public function stopProfiling(): void
    {
        if ($this->perfbase === null || !$this->started || $this->stopped) {
            return;
        }

        $this->stopped = true;

        try {
            foreach ($this->attributes as $key => $value) {
                $this->perfbase->setAttribute($key, $value);
            }

            if (!$this->perfbase->stopTraceSpan($this->spanName)) {
                return;
            }

            if (!$this->shouldSubmitTrace()) {
                $this->perfbase->reset();
                return;
            }

            $result = $this->perfbase->submitTrace();

            if (!$result->isSuccess()) {
                $this->handleProfilingError(
                    new PerfbaseException(sprintf(
                        'Trace submission failed (%s): %s',
                        $result->getStatus(),
                        $result->getMessage()
                    )),
                    $this->config,
                    'submit'
                );
            }
        } catch (\Throwable $e) {
            $this->handleProfilingError($e, $this->config, 'stop');
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function getSpanName(): string
    {
        return $this->spanName;
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    protected function setAttribute(string $key, string $value): void
    {
        $this->attributes[$key] = $value;
    }

    /**
     * @param array<string, scalar|null> $attributes
     */
    protected function setAttributes(array $attributes): void
    {
        foreach ($attributes as $key => $value) {
            if ($value === null) {
                continue;
            }

            $this->setAttribute($key, (string) $value);
        }
    }

    protected function setDefaultAttributes(): void
    {
        $hostname = gethostname();

        $this->setAttributes([
            'hostname' => is_string($hostname) ? $hostname : '',
            'php_version' => phpversion() ?: '',
            'environment' => $this->resolveEnvironment(),
            'app_version' => $this->resolveAppVersion(),
        ]);
    }

    protected function passesSampleRateCheck(): bool
    {
        $sampleRate = $this->config['sample_rate'] ?? 0.1;

        if (!is_numeric($sampleRate)) {
            return false;
        }

        $sampleRate = (float) $sampleRate;

        if ($sampleRate <= 0.0) {
            return false;
        }

        if ($sampleRate >= 1.0) {
            return true;
        }

        return (mt_rand() / mt_getrandmax()) <= $sampleRate;
    }

    protected function resolveEnvironment(): string
    {
        $environment = trim((string) ($this->config['environment'] ?? ''));

        if ($environment !== '') {
            return $environment;
        }

        return 'production';
    }

    protected function resolveAppVersion(): string
    {
        return trim((string) ($this->config['app_version'] ?? ''));
    }

    protected function shouldSubmitTrace(): bool
    {
        return true;
    }

    /** @codeCoverageIgnore */
    abstract protected function shouldProfile(): bool;
}
