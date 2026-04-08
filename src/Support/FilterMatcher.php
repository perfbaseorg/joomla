<?php

namespace Perfbase\Plugin\System\Perfbase\Support;

class FilterMatcher
{
    /**
     * @param array<string> $components
     * @param array<string> $filters
     */
    public static function matches(array $components, array $filters): bool
    {
        foreach ($filters as $filter) {
            if ($filter === '*' || $filter === '.*') {
                return true;
            }

            if (preg_match('/^\/.*\/$/', $filter) === 1) {
                if (@preg_match($filter, '') === false) {
                    continue;
                }

                foreach ($components as $component) {
                    if (preg_match($filter, $component) === 1) {
                        return true;
                    }
                }

                continue;
            }

            foreach ($components as $component) {
                if (fnmatch($filter, $component)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string> $components
     * @param array<string, array<string>> $includeConfig
     * @param array<string, array<string>> $excludeConfig
     */
    public static function passesFilters(
        array $components,
        array $includeConfig,
        array $excludeConfig,
        string $key
    ): bool {
        $includes = $includeConfig[$key] ?? [];

        if ($includes === []) {
            return false;
        }

        if (!self::matches($components, $includes)) {
            return false;
        }

        $excludes = $excludeConfig[$key] ?? [];

        return $excludes === [] || !self::matches($components, $excludes);
    }
}
