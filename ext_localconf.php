<?php

declare(strict_types=1);

use Digicademy\TypoGraph\Controller\EndpointController;
use TYPO3\CMS\Core\Cache\Backend\SimpleFileBackend;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

defined('TYPO3') or die();

// Cache configuration
//
// We are using the simple file cache for maximum compatibility with different
// setups but you can override this with a cache of your choice in the
// additional.php configuration file, as long as it is compatible with the
// PhpCapableBackendInterface.
//
// @see https://docs.typo3.org/permalink/t3coreapi:caching-backend-simple-file
// https://docs.typo3.org/permalink/t3coreapi:caching-frontend-php
// @see https://docs.typo3.org/permalink/t3coreapi:caching-configuration-cache
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typograph_schema_cache']
    ??= [];
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']['typograph_schema_cache']['backend']
    ??= SimpleFileBackend::class;
