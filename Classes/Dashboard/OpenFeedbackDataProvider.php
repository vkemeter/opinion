<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Repository\FeedbackFilter;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Number of feedback that is new or in progress.
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

        return $this->feedbackRepository->countAll($senderUid, new FeedbackFilter(status: FeedbackStatus::New))
            + $this->feedbackRepository->countAll($senderUid, new FeedbackFilter(status: FeedbackStatus::InProgress));
    }
}
