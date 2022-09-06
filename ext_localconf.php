<?php

declare(strict_types=1);

use Supseven\Opinion\Hooks\Backend\Toolbar\OpinionToolbarItem;
use Supseven\Opinion\Adminpanel\Modules\OpinionModule;
use Supseven\Opinion\Adminpanel\Modules\Opinion\Opinion;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;
use Supseven\Opinion\Controller\OpinionController;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') || die('Access denied.');

call_user_func(
    function ($extKey): void {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['toolbarItems'][1638100815] = OpinionToolbarItem::class;

        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules'][$extKey] = [
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

        ExtensionUtility::configurePlugin(
            'Opinion',
            'Opinion',
            [OpinionController::class => 'opinion'],
            [OpinionController::class => 'opinion']
        );

        ExtensionManagementUtility::addTypoScriptSetup(
            '
            config.debug = 1
            config.admPanel = 1
        '
        );

        ExtensionManagementUtility::addUserTSConfig(
            '
            admPanel.enable.all = 0
            admPanel.enable.opinion = 1
        '
        );

        ExtensionManagementUtility::addTypoScriptSetup(
            '
            opinion = PAGE
            opinion {
                typeNum = 1634212115
                config {
                    disableAllHeaderCode = 1
                    xhtml_cleaning = 0
                    admPanel = 0
                    additionalHeaders {
                        10 {
                            header = Content-Type: application/json
                            replace = 1
                        }
                    }
                    no_cache = 1
                    debug = 0
                }

                10 = USER
                10 {
                    userFunc = TYPO3\CMS\Extbase\Core\Bootstrap->run
                    extensionName = Opinion
                    pluginName = Opinion
                    vendorName = Supseven
                    controller = Opinion
                    action = opinion
                }
            }
        '
        );
    },
    'opinion'
);
