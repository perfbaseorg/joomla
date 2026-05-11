<?php

declare(strict_types=1);

define('_JEXEC', 1);

if (class_exists(\Perfbase\SDK\FeatureFlags::class, false)) {
    throw new RuntimeException('SDK classes were loaded before the service provider smoke test started.');
}

if (!interface_exists(\Joomla\Event\DispatcherInterface::class)) {
    eval('namespace Joomla\Event; interface DispatcherInterface {}');
}

if (!interface_exists(\Joomla\CMS\Extension\PluginInterface::class)) {
    eval('namespace Joomla\CMS\Extension; interface PluginInterface {}');
}

if (!class_exists(\Joomla\CMS\Factory::class)) {
    eval('namespace Joomla\CMS; class Factory { public static $application = null; public static function getApplication() { return self::$application; } }');
}

if (!class_exists(\Joomla\CMS\Plugin\PluginHelper::class)) {
    eval('namespace Joomla\CMS\Plugin; class PluginHelper { public static array $plugin = []; public static function getPlugin(string $group, string $name) { return self::$plugin; } }');
}

if (!class_exists(\Joomla\CMS\Plugin\CMSPlugin::class)) {
    eval('namespace Joomla\CMS\Plugin; class CMSPlugin implements \Joomla\CMS\Extension\PluginInterface { protected $application; protected $params; protected $autoloadLanguage = false; public function __construct($subject = null, array $config = []) { $params = isset($config["params"]) && is_array($config["params"]) ? $config["params"] : $config; $this->params = new class($params) { private array $config; public function __construct(array $config) { $this->config = $config; } public function toArray(): array { return $this->config; } }; } public function setApplication($application): void { $this->application = $application; } }');
}

if (!class_exists(\Joomla\DI\Container::class)) {
    eval('namespace Joomla\DI; class Container { private array $entries = []; public function set(string $id, $value): void { $this->entries[$id] = $value; } public function get(string $id) { if (!array_key_exists($id, $this->entries)) { return null; } $value = $this->entries[$id]; return $value instanceof \Closure ? $value($this) : $value; } }');
}

if (!interface_exists(\Joomla\DI\ServiceProviderInterface::class)) {
    eval('namespace Joomla\DI; interface ServiceProviderInterface { public function register(\Joomla\DI\Container $container): void; }');
}

\Joomla\CMS\Factory::$application = new class {
};
\Joomla\CMS\Plugin\PluginHelper::$plugin = [
    'params' => [
        'enabled' => '0',
    ],
];

$provider = require dirname(__DIR__, 2) . '/services/provider.php';

if (!$provider instanceof \Joomla\DI\ServiceProviderInterface) {
    throw new RuntimeException('Provider did not return a Joomla service provider.');
}

$container = new \Joomla\DI\Container();
$container->set(\Joomla\Event\DispatcherInterface::class, new class implements \Joomla\Event\DispatcherInterface {
});
$provider->register($container);

$plugin = $container->get(\Joomla\CMS\Extension\PluginInterface::class);

if (!$plugin instanceof \Perfbase\Plugin\System\Perfbase\Extension\PerfbasePlugin) {
    throw new RuntimeException('Provider did not autoload and create the Perfbase plugin.');
}

if (!class_exists(\Perfbase\SDK\FeatureFlags::class)) {
    throw new RuntimeException('Provider did not register the plugin Composer autoloader.');
}

fwrite(STDOUT, "Provider autoload smoke test passed.\n");
