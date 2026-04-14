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

namespace Digicademy\TypoGraph\Comparator;

/**
 * Interface for custom string comparison strategies.
 *
 * Implementing this interface allows external packages to inject
 * domain-specific sorting logic (e.g. locale-aware collation) into
 * TypoGraph's result set ordering without creating a hard dependency.
 *
 * The comparator is injected into the ResolverService via dependency
 * injection. Which field to compare is determined per query via the
 * `sortBy` GraphQL argument.
 */
interface ComparatorInterface
{
    /**
     * Compare two string values.
     *
     * Follows PHP's standard comparison contract for use with usort()
     * and similar sorting functions.
     *
     * @param string $a First string to compare
     * @param string $b Second string to compare
     * @return int Negative if $a < $b, zero if equal, positive if $a > $b
     */
    public function compare(string $a, string $b): int;
}
