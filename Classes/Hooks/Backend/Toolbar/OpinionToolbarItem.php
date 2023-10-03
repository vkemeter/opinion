<?php

declare(strict_types=1);

namespace Supseven\Opinion\Hooks\Backend\Toolbar;

use Supseven\Opinion\Service\OpinionService;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

class OpinionToolbarItem implements ToolbarItemInterface
{
    /** @var PageRenderer */
    private $pageRenderer;

    public function __construct()
    {
        $this->pageRenderer = GeneralUtility::makeInstance(PageRenderer::class);
        $this->pageRenderer->addCssFile('EXT:opinion/Resources/Public/Css/Styles.min.css');
        $this->pageRenderer->loadJavaScriptModule('@vkemeter/opinion/OpinionBe.js');
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
            'info' => [
                'backend' => true,
                'beUser'  => OpinionService::getBeUserName(),
            ],
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
