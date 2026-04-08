<?php

declare(strict_types=1);

use Joomla\CMS\Extension\PluginInterface;
use Joomla\DI\ServiceProviderInterface;
use Perfbase\Plugin\System\Perfbase\Config\ConfigResolver;
use Perfbase\Plugin\System\Perfbase\Extension\PerfbasePlugin;
use Perfbase\Plugin\System\Perfbase\Tests\Helpers\MockFactory;
use Perfbase\SDK\SubmitResult;

$hostAutoload = $argv[1] ?? '';
$pluginAutoload = $argv[2] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';
$mode = $argv[3] ?? 'http';

if ($hostAutoload === '' || !is_file($hostAutoload)) {
    fwrite(STDERR, "Missing Joomla host autoload path.\n");
    exit(1);
}

if (!is_file($pluginAutoload)) {
    fwrite(STDERR, "Missing plugin autoload path.\n");
    exit(1);
}

define('_JEXEC', 1);

require $hostAutoload;
require $pluginAutoload;

if (!class_exists(PerfbasePlugin::class)) {
    throw new RuntimeException('PerfbasePlugin failed to autoload against the Joomla host packages.');
}

$provider = require dirname(__DIR__, 2) . '/services/provider.php';

if (!$provider instanceof ServiceProviderInterface) {
    throw new RuntimeException('Service provider did not load as a Joomla service provider.');
}

$dispatcher = Mockery::mock(\Joomla\Event\DispatcherInterface::class);
$perfbase = MockFactory::createPerfbase();
$perfbase->shouldReceive('startTraceSpan')->once();
$perfbase->shouldReceive('stopTraceSpan')->once()->andReturn(true);
$perfbase->shouldReceive('submitTrace')->once()->andReturn(SubmitResult::success());

$resolver = new class extends ConfigResolver {
    public function resolve(array $params = []): array
    {
        return parent::resolve([
            'enabled' => true,
            'api_key' => 'host-smoke-key',
            'sample_rate' => 1.0,
            'include_http' => '*',
            'include_console' => '*',
        ]);
    }
};

$plugin = new PerfbasePlugin($dispatcher, [], $resolver, $perfbase);

if ($mode === 'cli') {
    $_SERVER['argv'] = ['joomla.php', 'cache:clean'];

    $plugin->setApplication(new class {
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
} else {
    $plugin->setApplication(new class {
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
            return (object) ['id' => 9, 'guest' => false];
        }
    });

    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/index.php?option=com_content&view=article&id=9';

    $plugin->onAfterInitialise();
    $plugin->onAfterRoute();
    $plugin->onAfterDispatch();
    $plugin->onAfterRespond();
}

if (!$plugin instanceof PluginInterface) {
    throw new RuntimeException('PerfbasePlugin is not recognized as a Joomla plugin instance.');
}

if ($plugin->getActiveLifecycle() === null || !$plugin->getActiveLifecycle()->isStarted()) {
    throw new RuntimeException('PerfbasePlugin did not start under Joomla host packages.');
}

if ($mode === 'cli' && $plugin->getActiveLifecycle()->getSpanName() !== 'console.cache.clean') {
    throw new RuntimeException('CLI host smoke test did not resolve the expected console span name.');
}

Mockery::close();

fwrite(STDOUT, "Joomla host smoke test passed.\n");
