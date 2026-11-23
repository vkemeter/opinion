<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Backend\ToolbarItem;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Dialog\DialogConfigurationBuilder;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Repository\ConversationRepository;

/**
 * Toolbar button in the backend that opens the feedback dialog. It also carries the number of
 * feedback entries with unread messages, which toolbar-item.js shows on the module menu entry.
 * shortcut: the count is the state of the last backend load or toolbar refresh, add polling only if users miss answers
 */
final class OpinionToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    public function __construct(
        private readonly AccessChecker $accessChecker,
        private readonly DialogConfigurationBuilder $dialogConfigurationBuilder,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly ConversationRepository $conversationRepository,
        private readonly ModuleProvider $moduleProvider,
        private readonly LanguageServiceFactory $languageServiceFactory,
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

        $backendUser = $this->getBackendUser();
        $configuration = $this->dialogConfigurationBuilder->build($backendUser, Source::Backend);
        $unread = 0;
        if ($this->moduleProvider->accessGranted('opinion_feedback', $backendUser)) {
            $userUid = (int)($backendUser->user['uid'] ?? 0);
            $unread = count($this->conversationRepository->findUnreadFeedbackUids($userUid, $backendUser->isAdmin() ? null : $userUid));
        }

        $view = $this->backendViewFactory->create($this->request, ['vkemeter/opinion']);
        $view->assignMultiple([
            'configuration' => json_encode($configuration, JSON_THROW_ON_ERROR),
            'unread' => $unread,
            'unreadLabel' => sprintf($this->languageServiceFactory->createFromUserPreferences($backendUser)->sL('opinion.messages:toolbar.unread'), $unread),
        ]);

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
