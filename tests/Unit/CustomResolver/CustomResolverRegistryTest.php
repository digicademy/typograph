<?php

declare(strict_types=1);

namespace Tests\Unit\CustomResolver;

use Codeception\Test\Unit;
use Digicademy\TypoGraph\CustomResolver\CustomResolverInterface;
use Digicademy\TypoGraph\CustomResolver\CustomResolverRegistry;
use GraphQL\Type\Definition\ResolveInfo;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Exercises the indexing contract of {@see CustomResolverRegistry}: each
 * registered resolver must be retrievable by the exact field name it
 * advertises, and unknown field names must return null rather than
 * throwing (so {@see ResolverService::resolve()} can fall back to
 * tableMapping-based dispatch).
 */
final class CustomResolverRegistryTest extends Unit
{
    public function testGetByFieldNameReturnsMatchingResolver(): void
    {
        $alpha = $this->makeResolver('alphaField');
        $beta = $this->makeResolver('betaField');
        $registry = new CustomResolverRegistry([$alpha, $beta]);

        $this->assertSame($alpha, $registry->get('alphaField'));
        $this->assertSame($beta, $registry->get('betaField'));
    }

    public function testGetReturnsNullForUnknownField(): void
    {
        $registry = new CustomResolverRegistry([]);
        $this->assertNull($registry->get('anythingUnregistered'));
    }

    public function testAcceptsEmptyIterable(): void
    {
        $registry = new CustomResolverRegistry();
        $this->assertNull($registry->get('anything'));
    }

    public function testLastRegisteredWinsOnFieldNameCollision(): void
    {
        // Two resolvers for the same field name: the second one overwrites
        // the first. Documents the deterministic (if crude) tie-break
        // behaviour so downstream code can rely on it.
        $first = $this->makeResolver('clash', 'first');
        $second = $this->makeResolver('clash', 'second');
        $registry = new CustomResolverRegistry([$first, $second]);

        $this->assertSame($second, $registry->get('clash'));
    }

    private function makeResolver(string $fieldName, string $label = ''): CustomResolverInterface
    {
        return new class($fieldName, $label) implements CustomResolverInterface {
            public function __construct(
                private readonly string $fieldName,
                private readonly string $label,
            ) {}

            public function getFieldName(): string
            {
                return $this->fieldName;
            }

            public function resolve(array $args, ResolveInfo $info, ?ServerRequestInterface $request): mixed
            {
                return $this->label;
            }
        };
    }
}
