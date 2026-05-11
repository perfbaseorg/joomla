<?php

namespace Perfbase\Plugin\System\Perfbase\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\DispatcherInterface;
use Perfbase\Plugin\System\Perfbase\Config\ConfigResolver;
use Perfbase\Plugin\System\Perfbase\Lifecycle\AbstractJoomlaProfiler;
use Perfbase\Plugin\System\Perfbase\Lifecycle\CliLifecycle;
use Perfbase\Plugin\System\Perfbase\Lifecycle\HttpRequestLifecycle;
use Perfbase\Plugin\System\Perfbase\Support\ErrorHandler;
use Perfbase\SDK\Perfbase;

class PerfbasePlugin extends CMSPlugin
{
    use ErrorHandler;

    /** @var bool */
    protected $autoloadLanguage = true;

    private ConfigResolver $configResolver;

    private ?Perfbase $perfbase;

    /**
     * @var array<string, mixed>
     */
    private array $config = [];

    private ?AbstractJoomlaProfiler $activeLifecycle = null;

    private bool $booted = false;

    private bool $shutdownRegistered = false;

    private bool $stopped = false;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        DispatcherInterface $subject,
        array $config = [],
        ?ConfigResolver $configResolver = null,
        ?Perfbase $perfbase = null
    ) {
        parent::__construct($subject, $config);

        $this->configResolver = $configResolver ?? new ConfigResolver();
        $this->perfbase = $perfbase;
    }

    public function onAfterInitialise(): void
    {
        $this->boot();

        if (!$this->configResolver->isEnabled($this->config) || $this->activeLifecycle !== null) {
            return;
        }

        try {
            $application = $this->getApplicationInstance();

            if ($application === null) {
                return;
            }

            $this->activeLifecycle = $this->createLifecycleForContext($application);
            $this->activeLifecycle->startProfiling();
            $this->registerShutdownFallback();
        } catch (\Throwable $e) {
            $this->handleProfilingError($e, $this->config, 'after_initialise');
        }
    }

    public function onAfterRoute(): void
    {
        if ($this->activeLifecycle instanceof HttpRequestLifecycle) {
            $this->activeLifecycle->addRoutingContext();
        }
    }

    public function onAfterDispatch(): void
    {
        if ($this->activeLifecycle instanceof HttpRequestLifecycle) {
            $this->activeLifecycle->addDispatchContext();
        }
    }

    public function onAfterRespond(): void
    {
        if ($this->activeLifecycle instanceof HttpRequestLifecycle) {
            $this->activeLifecycle->addFinalAttributes();
        }

        $this->stopActiveLifecycle();
    }

    public function onShutdownFallback(): void
    {
        if ($this->activeLifecycle instanceof HttpRequestLifecycle) {
            $this->activeLifecycle->addFinalAttributes();
        }

        $this->stopActiveLifecycle();
    }

    /**
     * @return array<string, mixed>
     */
    public function getResolvedConfig(): array
    {
        return $this->config;
    }

    public function getActiveLifecycle(): ?AbstractJoomlaProfiler
    {
        return $this->activeLifecycle;
    }

    public function getPerfbase(): ?Perfbase
    {
        return $this->perfbase;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;
        $this->config = $this->configResolver->resolve($this->readPluginParams());

        if (!$this->configResolver->isEnabled($this->config)) {
            return;
        }

        foreach ($this->configResolver->validate($this->config) as $field => $message) {
            $this->handleProfilingError(
                new \RuntimeException(sprintf('Invalid config for %s: %s', $field, $message)),
                $this->config,
                'config'
            );
        }

        if ($this->perfbase !== null) {
            return;
        }

        try {
            $this->perfbase = new Perfbase($this->configResolver->buildSdkConfig($this->config));
        } catch (\Throwable $e) {
            $this->perfbase = null;
            $this->handleProfilingError($e, $this->config, 'sdk_init');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readPluginParams(): array
    {
        if (isset($this->params) && method_exists($this->params, 'toArray')) {
            /** @var array<string, mixed> $params */
            $params = $this->params->toArray();

            return $params;
        }

        return [];
    }

    private function registerShutdownFallback(): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;
        register_shutdown_function([$this, 'onShutdownFallback']);
    }

    private function stopActiveLifecycle(): void
    {
        if ($this->stopped || $this->activeLifecycle === null) {
            return;
        }

        $this->stopped = true;
        $this->activeLifecycle->stopProfiling();
    }

    private function createLifecycleForContext(object $application): AbstractJoomlaProfiler
    {
        if (method_exists($application, 'isClient') && $application->isClient('cli')) {
            return new CliLifecycle($application, $this->perfbase, $this->config);
        }

        return new HttpRequestLifecycle($application, $this->perfbase, $this->config);
    }

    private function getApplicationInstance(): ?object
    {
        if (isset($this->application)) {
            return $this->application;
        }

        return null;
    }
}
