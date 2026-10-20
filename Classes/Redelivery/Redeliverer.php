<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Redelivery;

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
    ) {}

    /**
     * @return ProcessingResult|null null when the record does not exist or cannot be rebuilt
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
            return null;
        }
        $screenshot = $this->fileRepository->findByRelation(FeedbackRepository::TABLE, 'screenshot', $feedbackUid)[0] ?? null;

        return $this->submissionProcessor->deliverStored(
            $feedbackUid,
            $submission,
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
        return DeliveryStatus::tryFrom((string)($record['delivery_status'] ?? '')) !== DeliveryStatus::Sent;
    }
}
