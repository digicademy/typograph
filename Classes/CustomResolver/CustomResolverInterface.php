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

use GraphQL\Type\Definition\ResolveInfo;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Contract for a handler that resolves one computed GraphQL root field
 * which has no direct table mapping in `typograph.tableMapping`.
 *
 * Register an implementation as a DI service in `Configuration/Services.yaml`
 * of another extension. The `_instanceof` rule in the `Services.yaml` file of
 * the TypoGraph extension auto-tags every implementation so the
 * {@see CustomResolverRegistry} picks it up without explicit wiring.
 */
interface CustomResolverInterface
{
    /**
     * Name of the GraphQL Query root field this resolver handles
     * (e.g. `professorshipDevelopmentReports`). Must be unique; the
     * first registered resolver for a given field wins.
     */
    public function getFieldName(): string;

    /**
     * Produce the resolved value for the root field. The return type
     * must match the GraphQL schema declaration for the field; nested
     * fields are then resolved normally by typograph's `ResolverService`
     * as it walks the returned structure.
     *
     * @param array<string, mixed> $args GraphQL arguments.
     */
    public function resolve(array $args, ResolveInfo $info, ?ServerRequestInterface $request): mixed;
}
