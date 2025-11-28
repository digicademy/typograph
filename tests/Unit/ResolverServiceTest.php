<?php

namespace Tests\Unit;

use Codeception\Stub;
use Codeception\Test\Unit;
use Codeception\Verify\Verify;
use Digicademy\TypoGraph\Service\ResolverService;

class ResolverServiceTest extends Unit
{
    /**
     * @see https://github.com/Codeception/Verify
     * @see https://docs.phpunit.de/en/12.0/assertions.html
     */
    public function testInvoke(): void
    {
        Stub::make('ResolveInfo');
        $resolverInfoValue = [
            [
                'id' => true,
                'nested' => [
                    'nested1' => true,
                    'nested2' => true,
                ],
            ],
        ];
        $service = new ResolverService();
        verify($service())->isString();
    }
}
