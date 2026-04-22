<?php

declare(strict_types=1);

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

namespace Digicademy\TypoGraph\Transformer;

/**
 * Lookup for transformer implementations by the short name used in site
 * configuration (e.g. `typolinks`).
 *
 * The map is populated via the DI container in
 * `Configuration/Services.yaml`. To expose an additional transformer
 * to site configuration, implement {@see TransformerInterface} and add
 * an entry under the registry's `$transformers` constructor argument.
 */
final class TransformerRegistry
{
    /**
     * @param array<string, TransformerInterface> $transformers Map of
     *     transform name (as used in `typograph.fieldTransforms`) to
     *     the transformer instance that implements it.
     */
    public function __construct(
        private readonly array $transformers = []
    ) {}

    public function get(string $name): ?TransformerInterface
    {
        return $this->transformers[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->transformers[$name]);
    }
}
