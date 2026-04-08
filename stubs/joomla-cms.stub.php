<?php

/**
 * Minimal stubs for Joomla CMS classes that are not distributed as
 * standalone Composer packages.  Framework packages (joomla/di,
 * joomla/event, etc.) are installed from Packagist instead.
 *
 * Used by the CI host-smoke job so the plugin can be loaded against
 * real Joomla DI/Event interfaces while the CMS-only types are
 * satisfied by these lightweight stubs.
 */

namespace Joomla\CMS\Extension;

interface PluginInterface
{
}

namespace Joomla\CMS\Plugin;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\Event\DispatcherInterface;

class CMSPlugin implements PluginInterface
{
    protected object $application;

    protected object $params;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(DispatcherInterface $subject, array $config = [])
    {
    }

    public function setApplication(object $application): void
    {
        $this->application = $application;
    }
}

class PluginHelper
{
    /**
     * @return object
     */
    public static function getPlugin(string $type, string $element = ''): object
    {
        return (object) [];
    }
}

namespace Joomla\CMS;

class Factory
{
    /**
     * @return object
     */
    public static function getApplication(): object
    {
        throw new \RuntimeException('Factory::getApplication() is not available in stub context.');
    }
}
