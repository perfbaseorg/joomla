<?php

namespace Perfbase\Plugin\System\Perfbase\Tests\Helpers;

use Mockery;
use Perfbase\SDK\Perfbase;
use Perfbase\SDK\SubmitResult;

class MockFactory
{
    /**
     * @param array<string, mixed> $methods
     */
    public static function createPerfbase(array $methods = []): \Mockery\MockInterface
    {
        $mock = Mockery::mock(Perfbase::class);

        $defaults = [
            'isExtensionAvailable' => true,
            'startTraceSpan' => null,
            'stopTraceSpan' => true,
            'setAttribute' => null,
            'submitTrace' => SubmitResult::success(),
        ];

        foreach (array_merge($defaults, $methods) as $method => $returnValue) {
            if ($returnValue === null) {
                $mock->shouldReceive($method)->byDefault()->andReturnNull();
                continue;
            }

            $mock->shouldReceive($method)->byDefault()->andReturn($returnValue);
        }

        return $mock;
    }
}
