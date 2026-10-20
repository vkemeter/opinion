<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Access;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\RateLimiter\RateLimiterFactoryInterface;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;

/**
 * Limits the submissions per backend user, so a single account cannot flood
 * the recipients with mails and screenshots.
 */
final readonly class SubmissionRateLimiter
{
    private const string INTERVAL = '1 hour';

    public function __construct(
        private RateLimiterFactoryInterface $rateLimiterFactory,
    ) {}

    /**
     * Counts a submission of the user.
     *
     * @return \DateTimeImmutable|null when the user may send again, null if this submission is accepted
     */
    public function consume(BackendUserAuthentication $backendUser, OpinionConfiguration $configuration): ?\DateTimeImmutable
    {
        if ($configuration->rateLimit === 0) {
            return null;
        }

        $limit = $this->rateLimiterFactory->createLimiter(
            ['id' => 'opinion-submit', 'policy' => 'sliding_window', 'limit' => $configuration->rateLimit, 'interval' => self::INTERVAL],
            'be-user-' . (int)($backendUser->user['uid'] ?? 0),
        )->consume();

        return $limit->isAccepted() ? null : $limit->getRetryAfter();
    }
}
