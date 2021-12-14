<?php

declare(strict_types=1);

namespace Supseven\Opinion\Adminpanel\Modules;

use TYPO3\CMS\Adminpanel\ModuleApi\AbstractModule;
use TYPO3\CMS\Adminpanel\ModuleApi\ResourceProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\ShortInfoProviderInterface;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class OpinionModule extends AbstractModule implements ShortInfoProviderInterface, ResourceProviderInterface
{
    /**
     * @inheritdoc
     */
    public function getIconIdentifier(): string
    {
        return 'actions-document-info';
    }

    /**
     * @inheritdoc
     */
    public function getIdentifier(): string
    {
        return 'opinion';
    }

    /**
     * @inheritdoc
     */
    public function getLabel(): string
    {
        return $this->getLanguageService()->sL(
            'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:module.label'
        );
    }

    /**
     * @inheritdoc
     */
    public function getShortInfo(): string
    {
        return $this->getLanguageService()->sL(
            'LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:module.shortinfo'
        );
    }

    /**
     * @return array
     */
    public function getJavaScriptFiles(): array
    {
        return ['EXT:opinion/Resources/Public/JavaScript/Opinion.js'];
    }

    /**
     * Returns a string array with css files that will be rendered after the module
     *
     * Example: return ['EXT:adminpanel/Resources/Public/JavaScript/Modules/Edit.css'];
     *
     * @return array
     */
    public function getCssFiles(): array
    {
        return ['EXT:opinion/Resources/Public/Css/Styles.min.css'];
    }

    /**
     * @return TimeTracker
     */
    protected function getTimeTracker(): TimeTracker
    {
        return GeneralUtility::makeInstance(TimeTracker::class);
    }
}
