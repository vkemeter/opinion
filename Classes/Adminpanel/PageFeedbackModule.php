<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Adminpanel;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Adminpanel\ModuleApi\AbstractModule;
use TYPO3\CMS\Adminpanel\ModuleApi\ShortInfoProviderInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\PageArguments;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * "Feedback on this page" in the admin panel bar, only while the page has open frontend feedback.
 * The admin panel renders at the end of the request; the page is read from the global request,
 * which the frontend request handler sets with the routing (isEnabled() and the short info get no request).
 */
#[Autoconfigure(public: true)]
final class PageFeedbackModule extends AbstractModule implements ShortInfoProviderInterface
{
    public function __construct(
        private readonly AccessChecker $accessChecker,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly FeedbackRepository $feedbackRepository,
    ) {}

    public function getIdentifier(): string
    {
        return 'opinion_page';
    }

    public function getLabel(): string
    {
        return $this->languageServiceFactory->createFromUserPreferences($this->getBackendUserOrNull())->sL('LLL:EXT:opinion/Resources/Private/Language/locallang.xlf:adminpanel.pageFeedback.label');
    }

    public function getIconIdentifier(): string
    {
        return 'opinion-feedback-toolbar';
    }

    public function getShortInfo(): string
    {
        return (string)$this->countOpenFeedback();
    }

    public function isEnabled(): bool
    {
        // TYPO3 13 asks before the page is resolved; then the entry is removed in the browser when the page has no open feedback
        return $this->accessChecker->hasAccess($this->getBackendUserOrNull()) && (self::getCurrentPageUid() === 0 || $this->countOpenFeedback() > 0);
    }

    public static function getCurrentPageUid(): int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $routing = $request instanceof ServerRequestInterface ? $request->getAttribute('routing') : null;

        return $routing instanceof PageArguments ? $routing->getPageId() : 0;
    }

    private function countOpenFeedback(): int
    {
        $pageUid = self::getCurrentPageUid();

        return $pageUid > 0 ? $this->feedbackRepository->countOpenByPage($pageUid, Source::Frontend) : 0;
    }

    private function getBackendUserOrNull(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
