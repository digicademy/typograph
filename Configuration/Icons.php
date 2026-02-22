<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\BitmapIconProvider;

return [
    // Icon identifier
    'tx-typograph-bitmapicon' => [
        'provider' => BitmapIconProvider::class,
        // The source bitmap file
        'source' => 'EXT:my_extension/Resources/Public/Icons/Extension.png',
    ],
];
