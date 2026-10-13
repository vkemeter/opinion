<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Cleanup;

use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;

/**
 * Deletes feedback records created before a given date together with their
 * screenshots. A record is only removed after its screenshots are gone.
 */
final readonly class FeedbackCleaner
{
    public function __construct(
        private FeedbackRepository $feedbackRepository,
        private ScreenshotStorage $screenshotStorage,
        private ResourceFactory $resourceFactory,
    ) {}

    public function cleanup(\DateTimeImmutable $createdBefore, bool $dryRun): CleanupResult
    {
        $records = 0;
        $screenshots = 0;
        $errors = [];

        foreach ($this->feedbackRepository->findUidsCreatedBefore($createdBefore->getTimestamp()) as $feedbackUid) {
            $fileUids = $this->feedbackRepository->findScreenshotFileUids($feedbackUid);
            if ($dryRun) {
                $records++;
                $screenshots += count($fileUids);
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
                $this->feedbackRepository->remove($feedbackUid);
                $records++;
            } catch (\Throwable $exception) {
                $errors[] = sprintf('Feedback %d: %s', $feedbackUid, $exception->getMessage());
            }
        }

        return new CleanupResult($records, $screenshots, $errors);
    }
}
