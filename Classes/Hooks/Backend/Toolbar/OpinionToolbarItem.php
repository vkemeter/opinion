<?php

namespace Supseven\Opinion\Hooks\Backend\Toolbar;

use Supseven\Theme\Backend\FormDataProvider\General;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

class OpinionToolbarItem implements ToolbarItemInterface
{

    public function __construct()
    {
        $pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $pageRenderer->addJsFile('EXT:opinion/Resources/Public/JavaScript/Opinion.js');
        $pageRenderer->loadRequireJsModule('TYPO3/CMS/Opinion/OpinionBe');
    }

    public function checkAccess(): bool
    {
        return true;
    }

    public function getItem(): string
    {
        return $this->getTemplate('ToolbarItem.html')->render();
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        $view = $this->getTemplate('Dropdown.html');
        $view->assignMultiple([
            'info' => [],
        ]);
        return $view->render();
    }

    public function getAdditionalAttributes(): array
    {
        return [
            'class' => 'tx-opinion-toolbaritem',
        ];
    }

    public function getIndex(): int
    {
        return 1;
    }

    private function getTemplate(string $fileName): StandaloneView
    {
        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplateRootPaths(['EXT:opinion/Resources/Private/Templates/']);
        $view->setTemplate($fileName);

        return $view;
    }
}
