<?php

use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

(static function (): void {
    ExtensionUtility::registerPlugin(
        // extension name, matching the PHP namespaces (but without the vendor)
        'typograph',
        // arbitrary, but unique plugin name (not visible in the backend)
        'Endpoint',
        // plugin title, as visible in the drop-down in the backend, use "LLL:" for localization
        'TypoGraph GraphQL Endpoint',
        // icon
        'EXT:typograph/Resources/Public/Icons/typograph_icon.svg',
    );
})();
