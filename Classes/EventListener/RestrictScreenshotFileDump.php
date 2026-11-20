<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Authentication\AdminRequestCheckerInterface;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorageResolver;

/**
 * The dumpFile URL of a non-public file is only protected by its HMAC. For
 * screenshots, and their processed variants, the session of an admin or of
 * the sender of the feedback, who still may use the feedback, is required.
 */
#[AsEventListener('opinion/restrict-screenshot-file-dump')]
final readonly class RestrictScreenshotFileDump
{
    public function __construct(
        private ScreenshotStorageResolver $storageResolver,
        private AdminRequestCheckerInterface $adminRequestChecker,
        private AccessChecker $accessChecker,
        private FeedbackRepository $feedbackRepository,
    ) {}

    public function __invoke(ModifyFileDumpEvent $event): void
    {
        $file = $event->getFile();
        $originalFile = match (true) {
            $file instanceof File => $file,
            $file instanceof ProcessedFile, $file instanceof FileReference => $file->getOriginalFile(),
            default => null,
        };
        if ($originalFile === null) {
            return;
        }

        $screenshotStorage = $this->storageResolver->findStorage();
        if ($screenshotStorage === null || $originalFile->getStorage()->getUid() !== $screenshotStorage->getUid()) {
            return;
        }

        if (!$this->mayRead($this->adminRequestChecker->getAuthenticatedBackendUser($event->getRequest()), $originalFile)) {
            $event->setResponse(new Response(statusCode: 403));
        }
    }

    private function mayRead(?BackendUserAuthentication $backendUser, File $screenshot): bool
    {
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $userUid = (int)($backendUser->user['uid'] ?? 0);

        return $userUid > 0
            && $this->accessChecker->hasAccess($backendUser)
            && $this->feedbackRepository->findSenderUidOfScreenshot($screenshot->getUid()) === $userUid;
    }
}
