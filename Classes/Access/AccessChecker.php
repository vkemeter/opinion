<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Access;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;

/**
 * Admins may always send feedback, other backend users only with
 * user TSconfig "options.opinion.enable = 1".
 */
final readonly class AccessChecker
{
    public function __construct(
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function hasAccess(?BackendUserAuthentication $backendUser): bool
    {
        if ($backendUser === null || !is_array($backendUser->user) || !$this->configurationProvider->getConfiguration()->enabled) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        return (bool)($backendUser->getTSConfig()['options.']['opinion.']['enable'] ?? false);
    }

    /**
     * Feedback needs an address to answer to. Users without one see the
     * trigger, but are told to add it first.
     */
    public function hasValidEmail(?BackendUserAuthentication $backendUser): bool
    {
        return GeneralUtility::validEmail((string)($backendUser?->user['email'] ?? ''));
    }
}
