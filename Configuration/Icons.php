<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'opinion-feedback' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:opinion/Resources/Public/Icons/Extension.svg',
    ],
    'opinion-feedback-toolbar' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:opinion/Resources/Public/Icons/toolbar-item.svg',
    ],
];
