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
 *  the Free Software Foundation; either version 3 of the License, or
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

namespace Tests\Support\Helper;

use Codeception\Module;

/**
 * Custom assertion helpers for API test suites.
 *
 * Codeception's built-in actor does not expose PHPUnit assertions like
 * assertNotEmpty or assertArrayNotHasKey directly. This module provides
 * equivalents following Codeception's "see" naming convention so they
 * are available on the ApiTester actor.
 */
class ApiAssertions extends Module
{
    /**
     * Checks that a value is not empty.
     *
     * @param mixed $value
     */
    public function seeNotEmpty(mixed $value): void
    {
        $this->assertNotEmpty($value);
    }

    /**
     * Checks that an array does not contain the given key.
     *
     * @param int|string   $key
     * @param array<mixed> $array
     */
    public function seeArrayNotHasKey(int|string $key, array $array): void
    {
        $this->assertArrayNotHasKey($key, $array);
    }

    /**
     * Checks that a value is empty.
     *
     * @param mixed $value
     */
    public function seeEmpty(mixed $value, string $message = ''): void
    {
        $this->assertEmpty($value, $message);
    }

    /**
     * Checks that a haystack contains the given needle.
     *
     * @param mixed         $needle
     * @param iterable<mixed> $haystack
     */
    public function seeContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        $this->assertContains($needle, $haystack, $message);
    }

    /**
     * Checks that two values are equal.
     *
     * @param mixed $expected
     * @param mixed $actual
     */
    public function seeEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        $this->assertEquals($expected, $actual, $message);
    }
}
