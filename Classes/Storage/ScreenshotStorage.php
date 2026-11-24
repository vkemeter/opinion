<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Uid\Uuid;
use TYPO3\CMS\Core\Resource\Enum\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;

/**
 * Stores screenshots in the non-public storage as YYYY/MM/<uuid>.<extension>.
 */
final readonly class ScreenshotStorage
{
    public function __construct(
        private ScreenshotStorageResolver $resolver,
    ) {}

    public function store(string $content, string $fileExtension, \DateTimeImmutable $date): File
    {
        $storage = $this->getStorage();
        $temporaryFile = GeneralUtility::tempnam('opinion_');
        if (GeneralUtility::writeFileToTypo3tempDir($temporaryFile, $content) !== null) {
            throw new ScreenshotStorageException('Temporary screenshot file could not be written.', 1791561468);
        }

        try {
            // Storing happens on behalf of the system, independent of the file mounts of the sender
            return $this->asSystem($storage, fn(): File => $storage->addFile(
                $temporaryFile,
                $this->getTargetFolder($storage, $date),
                Uuid::v4()->toRfc4122() . '.' . $fileExtension,
                DuplicationBehavior::RENAME,
            ));
        } catch (\Throwable $exception) {
            // FAL and the local driver throw various exceptions (permissions, full disk); all mean "not stored"
            throw new ScreenshotStorageException('The screenshot could not be written into the storage: ' . $exception->getMessage(), 1795412871, $exception);
        } finally {
            if (is_file($temporaryFile)) {
                GeneralUtility::unlink_tempfile($temporaryFile);
            }
        }
    }

    public function delete(File $file): void
    {
        $this->asSystem($file->getStorage(), static fn(): bool => $file->getStorage()->deleteFile($file));
    }

    /**
     * Content of the file, read on behalf of the system (the sending editor has no file mount on the storage).
     */
    public function getContents(File $file): string
    {
        return $this->asSystem($file->getStorage(), static fn(): string => $file->getContents());
    }

    /**
     * Inline response with the file; access is checked by the caller (feedback module).
     */
    public function stream(File $file): ResponseInterface
    {
        return $this->asSystem($file->getStorage(), static fn(): ResponseInterface => $file->getStorage()->streamFile($file));
    }

    public function isAvailable(): bool
    {
        $storage = $this->resolver->findStorage();

        return $storage !== null && !$storage->isPublic() && $storage->isOnline() && $storage->isWritable();
    }

    private function getStorage(): ResourceStorage
    {
        $storage = $this->resolver->findStorage();
        if ($storage === null || !$this->isAvailable()) {
            throw new ScreenshotStorageException('The non-public screenshot storage is missing, public, offline or not writable. Run "vendor/bin/typo3 extension:setup".', 1791561469);
        }

        return $storage;
    }

    private function getTargetFolder(ResourceStorage $storage, \DateTimeImmutable $date): Folder
    {
        $folder = $storage->getRootLevelFolder(false);
        foreach ([$date->format('Y'), $date->format('m')] as $name) {
            $folder = $folder->hasFolder($name) ? $folder->getSubfolder($name) : $folder->createFolder($name);
        }

        return $folder;
    }

    /**
     * Runs the action with the permission checks of the storage disabled and restores them afterwards.
     *
     * @template T
     * @param \Closure(): T $action
     * @return T
     */
    private function asSystem(ResourceStorage $storage, \Closure $action): mixed
    {
        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            return $action();
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }
    }
}
