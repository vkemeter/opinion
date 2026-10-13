<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

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

        // Storing happens on behalf of the system, independent of the file mounts of the sender
        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            $file = $storage->addFile(
                $temporaryFile,
                $this->getTargetFolder($storage, $date),
                Uuid::v4()->toRfc4122() . '.' . $fileExtension,
                DuplicationBehavior::RENAME,
            );
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
            if (is_file($temporaryFile)) {
                GeneralUtility::unlink_tempfile($temporaryFile);
            }
        }

        return $file;
    }

    public function delete(File $file): void
    {
        $storage = $file->getStorage();
        $evaluatePermissions = $storage->getEvaluatePermissions();
        $storage->setEvaluatePermissions(false);

        try {
            $storage->deleteFile($file);
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }
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
}
