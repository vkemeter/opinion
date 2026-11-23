<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Repository\ConversationRepository;

/**
 * Number of feedback entries with messages the current user has not read; 0 while the conversation is off.
 */
final readonly class UnreadConversationsDataProvider implements NumberWithIconDataProviderInterface
{
    public function __construct(
        private FeedbackScope $scope,
        private ConversationRepository $conversationRepository,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function getNumber(): int
    {
        $senderUid = $this->scope->getSenderFilter();
        if ($senderUid === false || !$this->configurationProvider->getConfiguration()->conversationEnabled) {
            return 0;
        }

        return count($this->conversationRepository->findUnreadFeedbackUids($this->scope->getUserUid(), $senderUid));
    }
}
