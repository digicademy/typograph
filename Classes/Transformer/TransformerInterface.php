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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Contract for post-fetch field value transformers.
 *
 * Transformers are resolved by name via the {@see TransformerRegistry}
 * and are invoked by the resolver for any field that is mapped to a
 * transform name under the `typograph.fieldTransforms` site configuration
 * key. The transformer receives the raw value as returned by the database
 * layer and returns the post-processed value that will be sent to the
 * GraphQL consumer.
 */
interface TransformerInterface
{
    /**
     * Transform a raw field value into its post-processed form.
     *
     * Implementations should return the input unchanged when the value is
     * of a type they do not know how to handle (e.g. `null` or a numeric
     * value for an HTML-oriented transformer). Throwing for unexpected
     * inputs is discouraged because it would abort the entire GraphQL
     * response; prefer logging and returning the input as-is.
     *
     * @param mixed $value The raw value as returned by the database layer.
     * @param ServerRequestInterface $request The current PSR-7 request.
     *     Provided so that transformers can read request attributes set
     *     by earlier middlewares — notably `site`, `language`, and
     *     `routing` — which TYPO3's link resolution relies on.
     * @return mixed The transformed value.
     */
    public function transform(mixed $value, ServerRequestInterface $request): mixed;
}
