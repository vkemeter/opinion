<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Authentication\Mfa\MfaRequiredException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendBackendUserAuthentication;

/**
 * Resolves the fully authenticated backend user of a request, e.g. to decide
 * who may read a screenshot.
 *
 * The file dump (eID "dumpFile") runs before the frontend authenticates the
 * backend user, so the session is resolved here the same way
 * \TYPO3\CMS\Frontend\Middleware\BackendUserAuthenticator does it, without
 * registering the user globally.
 */
#[AsAlias(AdminRequestCheckerInterface::class)]
final readonly class AdminRequestChecker implements AdminRequestCheckerInterface
{
    public function getAuthenticatedBackendUser(ServerRequestInterface $request): ?BackendUserAuthentication
    {
        $globalBackendUser = $GLOBALS['BE_USER'] ?? null;
        if ($globalBackendUser instanceof BackendUserAuthentication && is_array($globalBackendUser->user)) {
            return $globalBackendUser;
        }

        if (!isset($request->getCookieParams()[BackendUserAuthentication::getCookieName()])) {
            return null;
        }

        $backendUser = GeneralUtility::makeInstance(FrontendBackendUserAuthentication::class);
        try {
            $backendUser->start($request);
        } catch (MfaRequiredException) {
            return null;
        }

        if ((int)($backendUser->user['uid'] ?? 0) <= 0) {
            return null;
        }
        // User TSconfig decides whether a non-admin may still use the feedback
        $backendUser->fetchGroupData();

        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }
        $ipMask = trim((string)($GLOBALS['TYPO3_CONF_VARS']['BE']['IPmaskList'] ?? ''));
        if ($ipMask !== '' && !GeneralUtility::cmpIP($normalizedParams->getRemoteAddress(), $ipMask)) {
            return null;
        }
        if ((bool)($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] ?? false) && !$normalizedParams->isHttps()) {
            return null;
        }

        return $backendUser->backendCheckLogin($request) ? $backendUser : null;
    }
}
