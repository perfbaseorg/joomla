<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Support;

use Perfbase\Plugin\System\Perfbase\Support\FilterMatcher;
use PHPUnit\Framework\TestCase;

class FilterMatcherTest extends TestCase
{
    public function test_matches_supports_wildcards_regex_and_globs(): void
    {
        self::assertTrue(FilterMatcher::matches(['com_content:article'], ['*']));
        self::assertTrue(FilterMatcher::matches(['com_content:article'], ['/com_content/']));
        self::assertTrue(FilterMatcher::matches(['cache:clean'], ['cache:*']));
        self::assertFalse(FilterMatcher::matches(['users.list'], ['orders.*']));
    }

    public function test_passes_filters_respects_include_and_exclude_lists(): void
    {
        $result = FilterMatcher::passesFilters(
            ['GET com_content:article', '/content/article'],
            ['http' => ['GET *']],
            ['http' => ['/content/*']],
            'http'
        );

        self::assertFalse($result);
    }

    public function test_passes_filters_returns_false_for_missing_includes_and_true_for_positive_match(): void
    {
        self::assertFalse(FilterMatcher::passesFilters(
            ['cache:clean'],
            ['console' => []],
            ['console' => []],
            'console'
        ));

        self::assertTrue(FilterMatcher::passesFilters(
            ['cache:clean'],
            ['console' => ['cache:*']],
            ['console' => []],
            'console'
        ));
    }

    public function test_matches_can_continue_after_regex_and_finish_false(): void
    {
        self::assertTrue(FilterMatcher::matches(['cache:clean'], ['/orders/', 'cache:*']));
        self::assertFalse(FilterMatcher::matches(['cache:clean'], ['/orders/', 'queue:*']));
    }

    public function test_matches_skips_invalid_regex_filters(): void
    {
        self::assertTrue(FilterMatcher::matches(['cache:clean'], ['/[invalid/', 'cache:*']));
        self::assertFalse(FilterMatcher::matches(['cache:clean'], ['/[invalid/']));
    }
}
