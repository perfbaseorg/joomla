<?php

namespace Perfbase\Plugin\System\Perfbase\Support;

class SpanNaming
{
    public static function httpAction(
        string $method,
        ?string $option,
        ?string $view,
        ?string $task,
        string $path
    ): string {
        $method = strtoupper($method);
        $route = self::routeIdentifier($option, $view, $task);

        if ($route !== '') {
            return sprintf('%s %s', $method, $route);
        }

        return sprintf('%s %s', $method, self::sanitizePath($path));
    }

    public static function httpSpanName(
        string $method,
        ?string $option,
        ?string $view,
        ?string $task,
        string $path
    ): string {
        return 'http';
    }

    /**
     * @param array<int, string> $argv
     */
    public static function cliAction(array $argv, ?string $applicationName = null): string
    {
        foreach ($argv as $index => $arg) {
            if ($index === 0) {
                continue;
            }

            if ($arg === '' || str_starts_with($arg, '-')) {
                continue;
            }

            return $arg;
        }

        if (is_string($applicationName) && $applicationName !== '') {
            return $applicationName;
        }

        return 'unknown';
    }

    /**
     * @param array<int, string> $argv
     */
    public static function cliSpanName(array $argv, ?string $applicationName = null): string
    {
        return 'artisan';
    }

    public static function sanitizePath(string $path): string
    {
        $rawPath = parse_url($path, PHP_URL_PATH);
        $rawPath = is_string($rawPath) ? $rawPath : $path;
        $rawPath = preg_replace('#/+#', '/', $rawPath) ?? '/';

        if ($rawPath === '') {
            return '/';
        }

        $segments = explode('/', trim($rawPath, '/'));
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $normalized[] = self::sanitizeSegment(rawurldecode($segment));
        }

        return '/' . implode('/', $normalized);
    }

    private static function routeIdentifier(?string $option, ?string $view, ?string $task): string
    {
        $parts = [];

        foreach ([$option, $view, $task] as $part) {
            if (is_string($part) && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode(':', $parts);
    }

    private static function sanitizeSegment(string $segment): string
    {
        if (preg_match('/^\d+$/', $segment) === 1) {
            return '{id}';
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $segment) === 1) {
            return '{id}';
        }

        if (preg_match('/^[0-9a-f]{24,}$/i', $segment) === 1) {
            return '{id}';
        }

        return $segment;
    }

    private static function normalizeIdentifier(string $identifier): string
    {
        $identifier = strtolower($identifier);
        $identifier = str_replace('{id}', 'id', $identifier);
        $identifier = preg_replace('/[^a-z0-9]+/', '_', $identifier) ?? 'unknown';
        $identifier = trim($identifier, '_');

        $identifier = $identifier !== '' ? $identifier : 'unknown';

        return $identifier;
    }

    private static function normalizeSpanName(string $spanName): string
    {
        $spanName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $spanName) ?? 'unknown';
        $spanName = preg_replace('/_+/', '_', $spanName) ?? 'unknown';
        $spanName = trim($spanName, '_');

        return substr($spanName !== '' ? $spanName : 'unknown', 0, 64);
    }
}
