<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Adminpanel;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Adminpanel\ModuleApi\AbstractModule;
use TYPO3\CMS\Adminpanel\ModuleApi\ResourceProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\ShortInfoProviderInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use Vkemeter\Opinion\Access\AccessChecker;

/**
 * "Feedback" tab of the admin panel. The admin panel renders only modules with
 * submodules and short info as tabs, the content is in FeedbackSubModule.
 */
#[Autoconfigure(public: true)]
final class OpinionModule extends AbstractModule implements ShortInfoProviderInterface, ResourceProviderInterface
{
    public function __construct(
        private readonly AccessChecker $accessChecker,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    public function getIdentifier(): string
    {
        return 'opinion';
    }

    public function getLabel(): string
    {
        return $this->languageServiceFactory->createFromUserPreferences($this->getBackendUserOrNull())->sL('opinion.messages:adminpanel.label');
    }

    public function getIconIdentifier(): string
    {
        return 'opinion-feedback-toolbar';
    }

    public function getShortInfo(): string
    {
        return '';
    }

    /**
     * Shown to everybody who may send feedback, independent of admPanel.enable.*
     */
    public function isEnabled(): bool
    {
        return $this->accessChecker->hasAccess($this->getBackendUserOrNull());
    }

    /**
     * @return list<string>
     */
    public function getJavaScriptFiles(): array
    {
        return ['EXT:opinion/Resources/Public/JavaScript/adminpanel-module.js'];
    }

    /**
     * @return list<string>
     */
    public function getCssFiles(): array
    {
        return ['EXT:opinion/Resources/Public/Css/opinion-dialog.css'];
    }

    private function getBackendUserOrNull(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
