<?php

declare(strict_types=1);

use Psr\Log\LogLevel;
use Supseven\Opinion\Adminpanel\Modules\Opinion\Opinion;
use Supseven\Opinion\Adminpanel\Modules\OpinionModule;
use Supseven\Opinion\Hooks\Backend\Toolbar\OpinionToolbarItem;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

$GLOBALS['TYPO3_CONF_VARS']['BE']['toolbarItems'][1638100815] = OpinionToolbarItem::class;

$GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules']['opinion'] = [
    'module'     => OpinionModule::class,
    'after'      => ['cache'],
    'submodules' => [
        'general' => [
            'module' => Opinion::class,
        ],
    ],
];

$GLOBALS['TYPO3_CONF_VARS']['MAIL']['layoutRootPaths'][1636015092] = 'EXT:opinion/Resources/Private/Layouts/';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['partialRootPaths'][1636015092] = 'EXT:opinion/Resources/Private/Partials/';
$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1636015092] = 'EXT:opinion/Resources/Private/Templates/';

$GLOBALS['TYPO3_CONF_VARS']['LOG']['Supseven']['Opinion']['writerConfiguration'] = [
    LogLevel::ERROR => [
        FileWriter::class => [
            'logFile' => Environment::getVarPath() . '/log/typo3_opinion.log',
        ],
    ],
];

ExtensionManagementUtility::addTypoScriptSetup('
    config.debug = 1
    config.admPanel = 1
');

ExtensionManagementUtility::addUserTSConfig('admPanel.opinion = 1');
