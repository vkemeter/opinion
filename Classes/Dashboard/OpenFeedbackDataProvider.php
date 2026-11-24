<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Number of open feedback (status not closed).
 */
final readonly class OpenFeedbackDataProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private FeedbackScope $scope,
        private FeedbackRepository $feedbackRepository,
    ) {}

    public function getNumber(): int
    {
        $senderUid = $this->scope->getSenderFilter();
        if ($senderUid === false) {
            return 0;
        }

        return $this->feedbackRepository->countOpen($senderUid);
    }
}
