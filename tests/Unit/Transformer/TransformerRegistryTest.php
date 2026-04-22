<?php

declare(strict_types=1);

namespace Tests\Unit\Transformer;

use Codeception\Test\Unit;
use Digicademy\TypoGraph\Transformer\TransformerInterface;
use Digicademy\TypoGraph\Transformer\TransformerRegistry;
use Psr\Http\Message\ServerRequestInterface;

class TransformerRegistryTest extends Unit
{
    public function testGetReturnsRegisteredTransformerForKnownName(): void
    {
        $transformer = $this->stubTransformer();
        $registry = new TransformerRegistry(['example' => $transformer]);

        verify($registry->get('example'))->same($transformer);
    }

    public function testGetReturnsNullForUnknownName(): void
    {
        $registry = new TransformerRegistry(['example' => $this->stubTransformer()]);

        verify($registry->get('missing'))->null();
    }

    public function testGetReturnsNullForEmptyRegistry(): void
    {
        $registry = new TransformerRegistry([]);

        verify($registry->get('anything'))->null();
    }

    public function testHasReturnsTrueForKnownName(): void
    {
        $registry = new TransformerRegistry(['example' => $this->stubTransformer()]);

        verify($registry->has('example'))->true();
    }

    public function testHasReturnsFalseForUnknownName(): void
    {
        $registry = new TransformerRegistry(['example' => $this->stubTransformer()]);

        verify($registry->has('missing'))->false();
    }

    /**
     * Build a throwaway anonymous implementation of TransformerInterface so
     * we can assert identity without any mocking framework involvement.
     */
    private function stubTransformer(): TransformerInterface
    {
        return new class () implements TransformerInterface {
            public function transform(mixed $value, ServerRequestInterface $request): mixed
            {
                return $value;
            }
        };
    }
}
