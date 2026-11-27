<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Redelivery;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Resource\FileRepository;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Factory\StoredSubmissionFactory;
use Vkemeter\Opinion\Processing\ProcessingResult;
use Vkemeter\Opinion\Processing\SubmissionProcessor;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Delivers stored feedback again, used by opinion:redeliver and the admin module.
 */
final readonly class Redeliverer
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
        private FileRepository $fileRepository,
        private StoredSubmissionFactory $storedSubmissionFactory,
        private SubmissionProcessor $submissionProcessor,
        #[Channel('opinion')]
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {}

    /**
     * @return ProcessingResult|null null when the record does not exist
     */
    public function redeliver(int $feedbackUid, OpinionConfiguration $configuration): ?ProcessingResult
    {
        $record = $this->feedbackRepository->findByUid($feedbackUid);
        if ($record === null) {
            return null;
        }

        try {
            $submission = $this->storedSubmissionFactory->createFromRecord($record);
        } catch (\ValueError $exception) {
            $this->logger->error('Feedback {uid} cannot be delivered again: {message}', ['uid' => $feedbackUid, 'message' => $exception->getMessage()]);
            // Counted as attempt, so redeliverMaxAttempts ends the retries of a record that can never be delivered
            $log = ltrim((string)($record['delivery_log'] ?? '') . "\n" . sprintf('[%s] cannot be delivered again: %s', $this->clock->now()->format('Y-m-d H:i'), $exception->getMessage()));
            $this->feedbackRepository->updateDeliveryStatus($feedbackUid, DeliveryStatus::Failed, $log);

            return new ProcessingResult($feedbackUid, DeliveryStatus::Failed, []);
        }
        $screenshot = $this->fileRepository->findByRelation(FeedbackRepository::TABLE, 'screenshot', $feedbackUid)[0] ?? null;
        $video = $this->fileRepository->findByRelation(FeedbackRepository::TABLE, 'video', $feedbackUid)[0] ?? null;

        return $this->submissionProcessor->deliverStored(
            $feedbackUid,
            $submission->withStoredFeedback($feedbackUid, $video?->getOriginalFile()),
            $screenshot?->getOriginalFile(),
            $configuration,
            (string)($record['delivery_log'] ?? ''),
        );
    }

    /**
     * Whether a record still waits for a successful delivery.
     *
     * @param array<string, mixed> $record
     */
    public function isUndelivered(array $record): bool
    {
        return !in_array(DeliveryStatus::tryFrom((string)($record['delivery_status'] ?? '')), [DeliveryStatus::Sent, DeliveryStatus::Skipped], true);
    }
}
