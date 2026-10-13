<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Collector;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\IpAnonymizationUtility;

/**
 * Collects the TYPO3 and sender details on the server; nothing is taken
 * from the request body.
 */
final readonly class SystemInfoCollector
{
    public function __construct(
        private Typo3Version $typo3Version,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function collect(BackendUserAuthentication $backendUser, ServerRequestInterface $request, \DateTimeImmutable $submittedAt): array
    {
        $user = is_array($backendUser->user) ? $backendUser->user : [];
        $normalizedParams = $request->getAttribute('normalizedParams');
        $remoteAddress = $normalizedParams instanceof NormalizedParams
            ? $normalizedParams->getRemoteAddress()
            : (string)($request->getServerParams()['REMOTE_ADDR'] ?? '');

        return [
            'typo3Version' => $this->typo3Version->getVersion(),
            'applicationContext' => (string)Environment::getContext(),
            'phpVersion' => PHP_VERSION,
            'backendUser' => [
                'uid' => (int)($user['uid'] ?? 0),
                'username' => (string)($user['username'] ?? ''),
                'realName' => (string)($user['realName'] ?? ''),
                'email' => (string)($user['email'] ?? ''),
                'isAdmin' => $backendUser->isAdmin(),
            ],
            'submittedAt' => $submittedAt->format(\DateTimeInterface::ATOM),
            'ipAddress' => $remoteAddress !== '' ? IpAnonymizationUtility::anonymizeIp($remoteAddress) : '',
            'requestUserAgent' => mb_substr($request->getHeaderLine('User-Agent'), 0, 512),
        ];
    }
}
