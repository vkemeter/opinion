<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Backend\ToolbarItem;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Page\PageRenderer;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Dialog\DialogConfigurationBuilder;
use Vkemeter\Opinion\Domain\Source;

/**
 * Toolbar button in the backend that opens the feedback dialog.
 */
final class OpinionToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly AccessChecker $accessChecker,
        private readonly DialogConfigurationBuilder $dialogConfigurationBuilder,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->accessChecker->hasAccess($this->getBackendUser());
    }

    public function getItem(): string
    {
        $this->pageRenderer->addCssFile('EXT:opinion/Resources/Public/Css/opinion-dialog.css');

        $view = $this->backendViewFactory->create($this->request, ['vkemeter/opinion']);
        $view->assign('configuration', json_encode(
            $this->dialogConfigurationBuilder->build($this->getBackendUser(), Source::Backend),
            JSON_THROW_ON_ERROR,
        ));

        return $view->render('ToolbarItems/OpinionToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return false;
    }

    public function getDropDown(): string
    {
        return '';
    }

    /**
     * @return array<string, string>
     */
    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        return 15;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
