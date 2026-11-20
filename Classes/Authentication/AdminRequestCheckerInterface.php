<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Authentication;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

interface AdminRequestCheckerInterface
{
    /**
     * The fully authenticated backend user of the request (MFA passed, IP mask
     * and lockSSL respected) with group data loaded, null without one.
     */
    public function getAuthenticatedBackendUser(ServerRequestInterface $request): ?BackendUserAuthentication;
}
