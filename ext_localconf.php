<?php

declare(strict_types=1);

defined('TYPO3_MODE') || die('Access denied.');

call_user_func(
    function ($extKey): void {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['toolbarItems'][1638100815] = \Supseven\Opinion\Hooks\Backend\Toolbar\OpinionToolbarItem::class;

        $GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules'][$extKey] = [
            'module'     => \Supseven\Opinion\Adminpanel\Modules\OpinionModule::class,
            'after'      => ['cache'],
            'submodules' => [
                'general' => [
                    'module' => Supseven\Opinion\Adminpanel\Modules\Opinion\Opinion::class,
                ],
            ],
        ];

        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['layoutRootPaths'][1636015092] = 'EXT:opinion/Resources/Private/Layouts/';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['partialRootPaths'][1636015092] = 'EXT:opinion/Resources/Private/Partials/';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths'][1636015092] = 'EXT:opinion/Resources/Private/Templates/';

        TYPO3\CMS\Extbase\Utility\ExtensionUtility::configurePlugin(
            'Supseven.' . $extKey,
            'Opinion',
            [\Supseven\Opinion\Controller\OpinionController::class => 'opinion'],
            [\Supseven\Opinion\Controller\OpinionController::class => 'opinion']
        );

        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
            '
            config.debug = 1
            config.admPanel = 1
        '
        );

        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addUserTSConfig(
            '
            admPanel.enable.all = 0
            admPanel.enable.opinion = 1
        '
        );

        \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addTypoScriptSetup(
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
