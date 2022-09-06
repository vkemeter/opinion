<?php

declare(strict_types=1);

use Psr\Log\LogLevel;
use Supseven\Opinion\Adminpanel\Modules\Opinion\Opinion;
use Supseven\Opinion\Adminpanel\Modules\OpinionModule;
use Supseven\Opinion\Controller\OpinionController;
use Supseven\Opinion\Hooks\Backend\Toolbar\OpinionToolbarItem;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Extbase\Utility\ExtensionUtility;

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

        $GLOBALS['TYPO3_CONF_VARS']['LOG']['Supseven']['Opinion']['writerConfiguration'] = [
            LogLevel::ERROR => [
                \TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
                    'logFile' => \TYPO3\CMS\Core\Core\Environment::getVarPath() . '/log/typo3_opinion.log',
                ],
            ],
        ];

        $iconRegistry = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Imaging\IconRegistry::class);
        if (!$iconRegistry->isRegistered('actions-document-opinion')) {
            $iconRegistry->registerIcon(
                'actions-document-opinion',
                \TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider::class,
                ['source' => 'EXT:opinion/Resources/Public/Icons/actions-document-opinion.svg']
            );
        }

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
