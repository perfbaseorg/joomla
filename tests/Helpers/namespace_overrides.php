<?php

namespace Perfbase\Plugin\System\Perfbase\Lifecycle;

if (!function_exists(__NAMESPACE__ . '\\mt_rand')) {
    function mt_rand(): int
    {
        if (isset($GLOBALS['__perfbase_test_mt_rand'])) {
            return (int) $GLOBALS['__perfbase_test_mt_rand'];
        }

        return \mt_rand();
    }
}

if (!function_exists(__NAMESPACE__ . '\\mt_getrandmax')) {
    function mt_getrandmax(): int
    {
        if (isset($GLOBALS['__perfbase_test_mt_getrandmax'])) {
            return (int) $GLOBALS['__perfbase_test_mt_getrandmax'];
        }

        return \mt_getrandmax();
    }
}

