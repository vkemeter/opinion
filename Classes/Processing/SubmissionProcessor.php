<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Processing;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TYPO3\CMS\Core\Log\Channel;
use TYPO3\CMS\Core\Resource\File;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\DeliveryChannelInterface;
use Vkemeter\Opinion\Delivery\DeliveryResult;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;

/**
 * Stores a submission first and delivers it afterwards, so a failing
 * delivery channel never loses feedback.
 */
final readonly class SubmissionProcessor
{
    /**
     * @param iterable<DeliveryChannelInterface> $deliveryChannels
     */
    public function __construct(
        private FeedbackRepository $feedbackRepository,
        private ScreenshotStorage $screenshotStorage,
        #[AutowireIterator('opinion.delivery_channel')]
        private iterable $deliveryChannels,
        #[Channel('opinion')]
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws \Vkemeter\Opinion\Exception\ScreenshotStorageException when the screenshot cannot be stored
     */
    public function process(Submission $submission, OpinionConfiguration $configuration): ProcessingResult
    {
        $screenshot = $submission->screenshot !== null
            ? $this->screenshotStorage->store($submission->screenshot->content, $submission->screenshot->type->getFileExtension(), $submission->createdAt)
            : null;

        try {
            $feedbackUid = $this->feedbackRepository->add($submission, $screenshot);
        } catch (\Throwable $exception) {
            if ($screenshot instanceof File) {
                $this->screenshotStorage->delete($screenshot);
            }
            throw $exception;
        }

        return $this->deliverStored($feedbackUid, $submission, $screenshot, $configuration);
    }

    /**
     * Delivers an already stored feedback through all channels and records the result.
     *
     * @param string|null $previousLog log of the earlier attempts when delivering again; the new results are appended with a timestamp
     */
    public function deliverStored(int $feedbackUid, Submission $submission, ?File $screenshot, OpinionConfiguration $configuration, ?string $previousLog = null): ProcessingResult
    {
        $results = [];
        foreach ($this->deliveryChannels as $channel) {
            $results[] = $this->deliver($channel, $submission, $screenshot, $configuration, $feedbackUid);
        }

        $status = $this->determineStatus($results);
        $log = implode("\n", array_map(
            static fn(DeliveryResult $result): string => sprintf('%s: %s %s', $result->channel, $result->success ? 'OK' : 'FAILED', $result->message),
            $results,
        ));
        if ($previousLog !== null) {
            $log = ltrim($previousLog . "\n" . sprintf('[%s] ', date('Y-m-d H:i')) . ($log !== '' ? $log : 'no delivery channel'));
        }
        try {
            $this->feedbackRepository->updateDeliveryStatus($feedbackUid, $status, $log);
        } catch (\Throwable $exception) {
            // The feedback is stored and delivered already; an error here would make the user send it again
            $this->logger->error('Delivery status {status} of feedback {uid} could not be stored: {message}', [
                'uid' => $feedbackUid,
                'status' => $status->value,
                'message' => $exception->getMessage(),
            ]);
        }

        return new ProcessingResult($feedbackUid, $status, $results);
    }

    private function deliver(DeliveryChannelInterface $channel, Submission $submission, ?File $screenshot, OpinionConfiguration $configuration, int $feedbackUid): DeliveryResult
    {
        try {
            $result = $channel->deliver($submission, $screenshot, $configuration);
        } catch (\Throwable $exception) {
            $result = DeliveryResult::failure($channel->getIdentifier(), $exception->getMessage());
        }

        if (!$result->success) {
            $this->logger->error('Delivery of feedback {uid} via {channel} failed: {message}', [
                'uid' => $feedbackUid,
                'channel' => $result->channel,
                'message' => $result->message,
            ]);
        }

        return $result;
    }

    /**
     * @param list<DeliveryResult> $results
     */
    private function determineStatus(array $results): DeliveryStatus
    {
        if ($results === []) {
            return DeliveryStatus::Pending;
        }
        foreach ($results as $result) {
            if (!$result->success) {
                return DeliveryStatus::Failed;
            }
        }

        return DeliveryStatus::Sent;
    }
}
