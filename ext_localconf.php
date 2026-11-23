<?php

declare(strict_types=1);

$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1791563000] = 'EXT:opinion/Resources/Private/Templates/Email/';

$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules']['opinion'] = [
    'module' => \Vkemeter\Opinion\Adminpanel\OpinionModule::class,
    'after' => ['info'],
    'submodules' => [
        'opinion_feedback' => [
            'module' => \Vkemeter\Opinion\Adminpanel\FeedbackSubModule::class,
        ],
    ],
];

// Shown only while the current page has open frontend feedback
$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules']['opinion_page'] = [
    'module' => \Vkemeter\Opinion\Adminpanel\PageFeedbackModule::class,
    'after' => ['opinion'],
    'submodules' => [
        'opinion_page_list' => [
            'module' => \Vkemeter\Opinion\Adminpanel\PageFeedbackSubModule::class,
        ],
    ],
];
