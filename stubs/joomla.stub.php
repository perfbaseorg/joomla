<?php

namespace Joomla\Event;

interface DispatcherInterface
{
}

namespace Joomla\CMS\Extension;

interface PluginInterface
{
}

namespace Joomla\CMS\Plugin;

use Joomla\Event\DispatcherInterface;

class CMSPlugin
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
