<?php

namespace Perfbase\Plugin\System\Perfbase\Lifecycle;

use Perfbase\Plugin\System\Perfbase\Support\FilterMatcher;
use Perfbase\Plugin\System\Perfbase\Support\SpanNaming;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\Utils\EnvironmentUtils;

class HttpRequestLifecycle extends AbstractJoomlaProfiler
{
    private object $application;

    private string $method;

    private string $path;

    private string $client;

    private string $option;

    private string $view;

    private string $task;

    private ?int $responseStatusCode = null;

    public function __construct(object $application, ?Perfbase $perfbase, array $config)
    {
        $this->application = $application;
        $this->method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->path = SpanNaming::sanitizePath((string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $this->client = $this->resolveClient();
        $this->option = $this->getInputValue('option');
        $this->view = $this->getInputValue('view');
        $this->task = $this->getInputValue('task');

        parent::__construct(
            SpanNaming::httpSpanName(
                $this->method,
                $this->option,
                $this->view,
                $this->task,
                $this->path
            ),
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

        if ($this->isAdminClient() && !($this->config['profile_admin'] ?? false)) {
            return false;
        }

        return FilterMatcher::passesFilters(
            $this->filterCandidates(),
            $this->config['include'] ?? [],
            $this->config['exclude'] ?? [],
            'http'
        );
    }

    protected function setDefaultAttributes(): void
    {
        parent::setDefaultAttributes();

        $this->setAttributes([
            'source' => 'http',
            'action' => SpanNaming::httpAction(
                $this->method,
                $this->option,
                $this->view,
                $this->task,
                $this->path
            ),
            'http_method' => $this->method,
            'http_url' => $this->buildSanitizedUrl(),
            'user_ip' => EnvironmentUtils::getUserIp() ?? '',
            'user_agent' => EnvironmentUtils::getUserUserAgent() ?? '',
            'user_id' => $this->resolveUserId(),
            'joomla.client' => $this->client,
            'joomla.option' => $this->option,
            'joomla.view' => $this->view,
            'joomla.task' => $this->task,
        ]);
    }

    public function addRoutingContext(): void
    {
        $this->setAttributes([
            'action' => SpanNaming::httpAction($this->method, $this->option, $this->view, $this->task, $this->path),
            'joomla.option' => $this->option,
            'joomla.view' => $this->view,
            'joomla.task' => $this->task,
        ]);
    }

    public function addDispatchContext(): void
    {
        $format = $this->getInputValue('format');

        if ($format !== '') {
            $this->setAttribute('joomla.format', $format);
        }
    }

    public function addFinalAttributes(): void
    {
        $statusCode = http_response_code();

        if (is_int($statusCode) && $statusCode > 0) {
            $this->responseStatusCode = $statusCode;
            $this->setAttribute('http_status_code', (string) $statusCode);
        }
    }

    protected function shouldSubmitTrace(): bool
    {
        if ($this->responseStatusCode === null) {
            return true;
        }

        return in_array(
            $this->responseStatusCode,
            $this->config['profile_http_status_codes'] ?? [...range(200, 299), ...range(500, 599)],
            true
        );
    }

    /**
     * @return array<string>
     */
    private function filterCandidates(): array
    {
        $action = SpanNaming::httpAction($this->method, $this->option, $this->view, $this->task, $this->path);

        return array_values(array_filter([
            $this->client,
            $action,
            $this->path,
            $this->option,
            $this->view,
            $this->task,
            $this->routeKey($this->option, $this->view, $this->task),
        ]));
    }

    private function resolveClient(): string
    {
        foreach (['site', 'administrator', 'api', 'cli'] as $client) {
            if (method_exists($this->application, 'isClient') && $this->application->isClient($client)) {
                return $client;
            }
        }

        return 'site';
    }

    private function isAdminClient(): bool
    {
        return method_exists($this->application, 'isClient') && $this->application->isClient('administrator');
    }

    private function buildSanitizedUrl(): string
    {
        $host = $this->sanitizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        if ($host !== '') {
            return sprintf('%s://%s%s', $scheme, $host, $this->path);
        }

        return $this->path;
    }

    private function resolveUserId(): string
    {
        if (!method_exists($this->application, 'getIdentity')) {
            return '';
        }

        $identity = $this->application->getIdentity();

        if (!is_object($identity)) {
            return '';
        }

        $id = $identity->id ?? null;
        $guest = $identity->guest ?? false;

        if ($guest || $id === null || (string) $id === '' || (string) $id === '0') {
            return '';
        }

        return (string) $id;
    }

    private function getInputValue(string $key): string
    {
        $input = $this->getInputObject();

        if ($input === null) {
            return '';
        }

        if (method_exists($input, 'getCmd')) {
            return (string) $input->getCmd($key, '');
        }

        if (method_exists($input, 'get')) {
            return (string) $input->get($key, '', 'cmd');
        }

        return '';
    }

    private function getInputObject(): ?object
    {
        if (property_exists($this->application, 'input') && is_object($this->application->input)) {
            return $this->application->input;
        }

        if (method_exists($this->application, 'getInput')) {
            $input = $this->application->getInput();

            if (is_object($input)) {
                return $input;
            }
        }

        return null;
    }

    private function sanitizeHost(string $host): string
    {
        $host = trim($host);

        if ($host === '') {
            return '';
        }

        if (preg_match('/^(?:[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?|\[[0-9a-f:.]+\])(?::\d{1,5})?$/i', $host) !== 1) {
            return '';
        }

        return strtolower($host);
    }

    private function routeKey(string $option, string $view, string $task): string
    {
        return implode(':', array_values(array_filter([$option, $view, $task])));
    }
}
