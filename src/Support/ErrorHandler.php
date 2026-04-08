<?php

namespace Perfbase\Plugin\System\Perfbase\Support;

trait ErrorHandler
{
    /**
     * @param array<string, mixed> $config
     */
    protected function handleProfilingError(\Throwable $e, array $config, string $context = ''): void
    {
        if (!empty($config['debug'])) {
            throw $e;
        }

        if ($config['log_errors'] ?? true) {
            $message = sprintf(
                'Perfbase Joomla profiling error in %s: %s',
                $context !== '' ? $context : 'unknown',
                $e->getMessage()
            );

            $this->logProfilingError($message);
        }
    }

    protected function logProfilingError(string $message): void
    {
        error_log($message);
    }
}
