<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

class UpdateMetadataContractTest extends TestCase
{
    public function test_update_metadata_matches_manifest_and_release_artifact_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $manifest = simplexml_load_file($root . '/perfbase.xml');
        $updates = simplexml_load_file($root . '/updates/perfbase.xml');

        self::assertNotFalse($manifest);
        self::assertNotFalse($updates);

        $update = $updates->update[0];
        $version = (string) $update->version;

        self::assertSame('Perfbase', (string) $update->name);
        self::assertSame('perfbase', (string) $update->element);
        self::assertSame('plugin', (string) $update->type);
        self::assertSame('system', (string) $update->folder);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        self::assertSame('8.1', (string) $update->php_minimum);
        self::assertSame((string) $manifest->targetplatform['name'], (string) $update->targetplatform['name']);
        self::assertSame((string) $manifest->targetplatform['version'], (string) $update->targetplatform['version']);
        self::assertSame('full', (string) $update->downloads->downloadurl['type']);
        self::assertSame('zip', (string) $update->downloads->downloadurl['format']);
        self::assertSame(
            sprintf(
                'https://github.com/perfbaseorg/joomla/releases/download/v%s/perfbase-joomla-%s.zip',
                $version,
                $version
            ),
            (string) $update->downloads->downloadurl
        );
    }
}
