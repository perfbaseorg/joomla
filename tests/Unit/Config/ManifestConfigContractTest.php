<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Config;

use Perfbase\Plugin\System\Perfbase\Config\ConfigResolver;
use PHPUnit\Framework\TestCase;

class ManifestConfigContractTest extends TestCase
{
    public function test_manifest_fields_match_resolver_public_config_contract(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/perfbase.xml');

        self::assertNotFalse($xml);

        $fields = [];

        foreach ($xml->config->fields->fieldset as $fieldset) {
            foreach ($fieldset->field as $field) {
                $fields[(string) $field['name']] = isset($field['default']) ? (string) $field['default'] : '';
            }
        }

        self::assertSame([
            'enabled',
            'api_key',
            'api_url',
            'sample_rate',
            'timeout',
            'proxy',
            'flags',
            'profile_admin',
            'profile_http_status_codes',
            'environment',
            'app_version',
            'include_http',
            'exclude_http',
            'include_console',
            'exclude_console',
            'debug',
            'log_errors',
        ], array_keys($fields));

        $resolver = new ConfigResolver();
        $defaults = $resolver->getDefaults();

        self::assertSame('joomla', (string) $xml->targetplatform['name']);
        self::assertSame('((4\.4)|(5\.[0-9]+))', (string) $xml->targetplatform['version']);

        self::assertSame('0', $fields['enabled']);
        self::assertSame('', $fields['api_key']);
        self::assertSame($defaults['api_url'], $fields['api_url']);
        self::assertSame((string) $defaults['sample_rate'], $fields['sample_rate']);
        self::assertSame((string) $defaults['timeout'], $fields['timeout']);
        self::assertSame('', $fields['proxy']);
        self::assertSame((string) $defaults['flags'], $fields['flags']);
        self::assertSame('0', $fields['profile_admin']);
        self::assertSame('200-299,500-599', $fields['profile_http_status_codes']);
        self::assertSame([...range(200, 299), ...range(500, 599)], $defaults['profile_http_status_codes']);
        self::assertSame($defaults['environment'], $fields['environment']);
        self::assertSame($defaults['app_version'], $fields['app_version']);
        self::assertSame('*', $fields['include_http']);
        self::assertSame('', $fields['exclude_http']);
        self::assertSame('*', $fields['include_console']);
        self::assertSame('', $fields['exclude_console']);
        self::assertSame('0', $fields['debug']);
        self::assertSame('1', $fields['log_errors']);
    }
}
