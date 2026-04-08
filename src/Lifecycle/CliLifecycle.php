<?php

namespace Perfbase\Plugin\System\Perfbase\Lifecycle;

use Perfbase\Plugin\System\Perfbase\Support\FilterMatcher;
use Perfbase\Plugin\System\Perfbase\Support\SpanNaming;
use Perfbase\SDK\Perfbase;

class CliLifecycle extends AbstractJoomlaProfiler
{
    private object $application;

    private string $command;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(object $application, ?Perfbase $perfbase, array $config)
    {
        $this->application = $application;
        $this->command = SpanNaming::cliAction($this->argv(), $this->applicationName());

        parent::__construct(
            SpanNaming::cliSpanName($this->argv(), $this->applicationName()),
            $perfbase,
            $config
        );
    }

    protected function shouldProfile(): bool
    {
        if (($this->config['enabled'] ?? false) !== true) {
            return false;
        }

        if ($this->perfbase === null || !$this->perfbase->isExtensionAvailable()) {
            return false;
        }

        return FilterMatcher::passesFilters(
            [$this->command],
            $this->config['include'] ?? [],
            $this->config['exclude'] ?? [],
            'console'
        );
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'console',
            'action' => $this->command,
            'cli.command' => $this->command,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function argv(): array
    {
        $argv = $_SERVER['argv'] ?? [];

        if (!is_array($argv)) {
            return [];
        }

        return array_values(array_map('strval', $argv));
    }

    private function applicationName(): ?string
    {
        if (method_exists($this->application, 'getName')) {
            return (string) $this->application->getName();
        }

        return null;
    }
}
