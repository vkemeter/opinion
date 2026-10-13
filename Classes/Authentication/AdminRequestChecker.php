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
 * Tells whether a request carries the session of a fully authenticated admin.
 *
 * The file dump (eID "dumpFile") runs before the frontend authenticates the
 * backend user, so the session is resolved here the same way
 * \TYPO3\CMS\Frontend\Middleware\BackendUserAuthenticator does it, without
 * registering the user globally.
 */
#[AsAlias(AdminRequestCheckerInterface::class)]
final readonly class AdminRequestChecker implements AdminRequestCheckerInterface
{
    public function isAdminRequest(ServerRequestInterface $request): bool
    {
        $globalBackendUser = $GLOBALS['BE_USER'] ?? null;
        if ($globalBackendUser instanceof BackendUserAuthentication && is_array($globalBackendUser->user)) {
            return $globalBackendUser->isAdmin();
        }

        if (!isset($request->getCookieParams()[BackendUserAuthentication::getCookieName()])) {
            return false;
        }

        $backendUser = GeneralUtility::makeInstance(FrontendBackendUserAuthentication::class);
        try {
            $backendUser->start($request);
        } catch (MfaRequiredException) {
            return false;
        }

        if ((int)($backendUser->user['uid'] ?? 0) <= 0 || !$backendUser->isAdmin()) {
            return false;
        }

        $normalizedParams = $request->getAttribute('normalizedParams');
        if (!$normalizedParams instanceof NormalizedParams) {
            $normalizedParams = NormalizedParams::createFromRequest($request);
        }
        $ipMask = trim((string)($GLOBALS['TYPO3_CONF_VARS']['BE']['IPmaskList'] ?? ''));
        if ($ipMask !== '' && !GeneralUtility::cmpIP($normalizedParams->getRemoteAddress(), $ipMask)) {
            return false;
        }
        if ((bool)($GLOBALS['TYPO3_CONF_VARS']['BE']['lockSSL'] ?? false) && !$normalizedParams->isHttps()) {
            return false;
        }

        return $backendUser->backendCheckLogin($request);
    }
}
