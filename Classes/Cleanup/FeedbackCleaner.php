<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Cleanup;

use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;

/**
 * Deletes feedback records together with their screenshots and conversation, filtered by
 * age and processing status. A record is only removed after its screenshots are gone.
 */
final readonly class FeedbackCleaner
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
        private ScreenshotStorage $screenshotStorage,
        private ResourceFactory $resourceFactory,
        private ConversationRepository $conversationRepository,
    ) {}

    /**
     * @param \DateTimeImmutable|null $createdBefore null = any age
     * @param list<string> $statuses only these processing statuses, [] = all
     */
    public function cleanup(?\DateTimeImmutable $createdBefore, bool $dryRun, array $statuses = []): CleanupResult
    {
        $records = 0;
        $screenshots = 0;
        $messages = 0;
        $errors = [];

        foreach ($this->feedbackRepository->findUidsForCleanup($createdBefore?->getTimestamp(), $statuses) as $feedbackUid) {
            $fileUids = $this->feedbackRepository->findScreenshotFileUids($feedbackUid);
            if ($dryRun) {
                $records++;
                $screenshots += count($fileUids);
                $messages += $this->conversationRepository->countByFeedback($feedbackUid);
                continue;
            }

            try {
                foreach ($fileUids as $fileUid) {
                    try {
                        $this->screenshotStorage->delete($this->resourceFactory->getFileObject($fileUid));
                    } catch (FileDoesNotExistException) {
                        // Already gone, the reference is removed with the record
                    }
                    $screenshots++;
                }
                $messages += $this->conversationRepository->removeByFeedback($feedbackUid);
                $this->feedbackRepository->remove($feedbackUid);
                $records++;
            } catch (\Throwable $exception) {
                $errors[] = sprintf('Feedback %d: %s', $feedbackUid, $exception->getMessage());
            }
        }

        return new CleanupResult($records, $screenshots, $messages, $errors);
    }
}
