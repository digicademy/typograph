<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2026 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>, Academy of Sciences and Literature | Mainz
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

declare(strict_types=1);

namespace Digicademy\TypoGraph\CustomResolver;

/**
 * Lookup for {@see CustomResolverInterface} implementations keyed by the
 * root field name they handle.
 *
 * Populated via a tagged-service iterator declared in
 * `Configuration/Services.yaml` of the TypoGraph extension, so consuming
 * extensions only need to register their resolver as a normal service.
 * autoconfigure-by-interface handles the tagging.
 */
class CustomResolverRegistry
{
    /** @var array<string, CustomResolverInterface> */
    private array $resolversByField = [];

    /**
     * @param iterable<CustomResolverInterface> $resolvers
     */
    public function __construct(iterable $resolvers = [])
    {
        foreach ($resolvers as $resolver) {
            $this->resolversByField[$resolver->getFieldName()] = $resolver;
        }
    }

    /**
     * @return CustomResolverInterface|null null when no resolver is
     *     registered for the given field — callers should fall back to
     *     the default tableMapping-based dispatch.
     */
    public function get(string $fieldName): ?CustomResolverInterface
    {
        return $this->resolversByField[$fieldName] ?? null;
    }
}
