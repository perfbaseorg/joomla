<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Support;

use Perfbase\Plugin\System\Perfbase\Support\SpanNaming;
use PHPUnit\Framework\TestCase;

class SpanNamingTest extends TestCase
{
    public function test_http_action_prefers_route_identifier(): void
    {
        $action = SpanNaming::httpAction('GET', 'com_content', 'article', 'display', '/articles/123');

        self::assertSame('GET com_content:article:display', $action);
    }

    public function test_http_fallback_sanitizes_numeric_and_uuid_segments(): void
    {
        $action = SpanNaming::httpAction('POST', null, null, null, '/users/123/orders/550e8400-e29b-41d4-a716-446655440000');
        $span = SpanNaming::httpSpanName('POST', null, null, null, '/users/123/orders/550e8400-e29b-41d4-a716-446655440000');

        self::assertSame('POST /users/{id}/orders/{id}', $action);
        self::assertSame('http_POST_users_id_orders_id', $span);
    }

    public function test_cli_action_uses_first_non_option_token(): void
    {
        $action = SpanNaming::cliAction(['joomla.php', '--env=dev', 'cache:clean']);
        $span = SpanNaming::cliSpanName(['joomla.php', '--env=dev', 'cache:clean']);

        self::assertSame('cache:clean', $action);
        self::assertSame('console_cache_clean', $span);
    }

    public function test_cli_action_falls_back_to_application_name_then_unknown(): void
    {
        self::assertSame('joomla', SpanNaming::cliAction(['joomla.php', '--env=dev'], 'joomla'));
        self::assertSame('unknown', SpanNaming::cliAction(['joomla.php', '--env=dev']));
    }

    public function test_root_paths_are_normalized_consistently(): void
    {
        self::assertSame('/', SpanNaming::sanitizePath('/'));
        self::assertSame('/', SpanNaming::sanitizePath(''));
        self::assertSame('/products/{id}', SpanNaming::sanitizePath('/products/abcdefabcdefabcdefabcdef'));
        self::assertSame('/orders/{id}', SpanNaming::sanitizePath('https://example.test//orders///123?foo=bar'));
        self::assertSame('/blog/posts', SpanNaming::sanitizePath('/blog/posts'));
        self::assertSame('http_GET_root', SpanNaming::httpSpanName('GET', null, null, null, '/'));
    }

    public function test_span_names_use_sdk_safe_characters_and_length(): void
    {
        $span = SpanNaming::cliSpanName(['joomla.php', 'very:long command/name with spaces and symbols that should be trimmed']);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $span);
        self::assertLessThanOrEqual(64, strlen($span));
    }
}
