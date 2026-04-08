<?php

define('_JEXEC', 1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/Helpers/namespace_overrides.php';

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
    eval('namespace Joomla\CMS\Plugin; class CMSPlugin implements \Joomla\CMS\Extension\PluginInterface { protected $application; protected $params; public function __construct($subject = null, array $config = []) { $params = isset($config["params"]) && is_array($config["params"]) ? $config["params"] : $config; $this->params = new class($params) { private array $config; public function __construct(array $config) { $this->config = $config; } public function toArray(): array { return $this->config; } }; } public function setApplication($application): void { $this->application = $application; } }');
}

if (!class_exists(\Joomla\DI\Container::class)) {
    eval('namespace Joomla\DI; class Container { private array $entries = []; public function set(string $id, $value): void { $this->entries[$id] = $value; } public function get(string $id) { if (!array_key_exists($id, $this->entries)) { return null; } $value = $this->entries[$id]; return $value instanceof \Closure ? $value($this) : $value; } }');
}

if (!interface_exists(\Joomla\DI\ServiceProviderInterface::class)) {
    eval('namespace Joomla\DI; interface ServiceProviderInterface { public function register(\Joomla\DI\Container $container): void; }');
}
