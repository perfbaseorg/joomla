<?php

defined('_JEXEC') or die;

if (is_file(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Perfbase\Plugin\System\Perfbase\Extension\PerfbasePlugin;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container): PluginInterface {
                $plugin = new PerfbasePlugin(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'perfbase')
                );

                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
