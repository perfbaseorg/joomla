<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Integration;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\Event\DispatcherInterface;
use Perfbase\Plugin\System\Perfbase\Extension\PerfbasePlugin;
use PHPUnit\Framework\TestCase;

class ServiceProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        Factory::$application = null;
        PluginHelper::$plugin = [];
    }

    public function test_service_provider_registers_plugin_interface_and_injects_joomla_state(): void
    {
        $application = new class {
        };

        Factory::$application = $application;
        PluginHelper::$plugin = [
            'params' => [
                'enabled' => '1',
                'api_key' => 'provider-test-key',
            ],
        ];

        $dispatcher = $this->createMock(DispatcherInterface::class);
        $container = new Container();
        $container->set(DispatcherInterface::class, $dispatcher);

        $provider = require dirname(__DIR__, 2) . '/services/provider.php';
        $provider->register($container);

        $plugin = $container->get(PluginInterface::class);

        self::assertInstanceOf(PerfbasePlugin::class, $plugin);

        $applicationProperty = new \ReflectionProperty(\Joomla\CMS\Plugin\CMSPlugin::class, 'application');
        $applicationProperty->setAccessible(true);
        self::assertSame($application, $applicationProperty->getValue($plugin));

        $paramsProperty = new \ReflectionProperty(\Joomla\CMS\Plugin\CMSPlugin::class, 'params');
        $paramsProperty->setAccessible(true);
        $params = $paramsProperty->getValue($plugin);

        self::assertTrue(method_exists($params, 'toArray'));
        self::assertSame(PluginHelper::$plugin['params'], $params->toArray());
    }

    public function test_service_provider_bootstraps_plugin_composer_autoloader(): void
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/Host/provider-autoload-smoke.php');
        exec($command, $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertSame('Provider autoload smoke test passed.', $output[0] ?? '');
    }
}
