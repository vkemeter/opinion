<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Vkemeter\Opinion\Access\AccessChecker;

/**
 * Which feedback the current backend user may see in the dashboard widgets, with the rules of the module.
 * shortcut: the rule also lives in FeedbackModuleController, move the controller onto this class when it changes next
 */
final readonly class FeedbackScope
{
    public const string MODULE = 'opinion_feedback';

    public function __construct(
        private ModuleProvider $moduleProvider,
        private AccessChecker $accessChecker,
    ) {}

    /**
     * @return int|false|null null = all feedback (administrators), int = own feedback of this sender, false = none
     */
    public function getSenderFilter(): int|false|null
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return null;
        }
        if (!$this->moduleProvider->accessGranted(self::MODULE, $backendUser) || !$this->accessChecker->hasAccess($backendUser)) {
            return false;
        }

        return $this->getUserUid();
    }

    public function getUserUid(): int
    {
        return (int)($this->getBackendUser()?->user['uid'] ?? 0);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication && is_array($backendUser->user) ? $backendUser : null;
    }
}
