<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2024 Frodo Podschwadek <frodo.podschwadek@adwmainz.de>
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

use Codeception\{
    Module,
    TestInterface
};
use Psr\Container\ContainerInterface;
use TYPO3\CMS\Core\Core\{
    Bootstrap,
    SystemEnvironmentBuilder
};

class Typo3Module extends Module
{
    protected ContainerInterface $container;

    // HOOK: before test
    public function _before(TestInterface $test)
    {
        // Set up the TYPO3 environment and bootstrap the DI container.
        // (Otherwise, all the TYPO3-specific classes will throw exceptions.)
        // Path: packages/typograph/tests/Support/Helper -> project root
        $classLoader = require dirname(__DIR__, 5) . '/vendor/autoload.php';
        SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
        $container = Bootstrap::init($classLoader);
    }
}
