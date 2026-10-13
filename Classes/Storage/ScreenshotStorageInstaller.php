<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;

final readonly class ScreenshotStorageInstaller
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ScreenshotStorageLocation $location,
        private ScreenshotStorageResolver $resolver,
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Creates the non-public screenshot storage unless it exists already.
     *
     * @return int uid of the storage
     */
    public function install(): int
    {
        $existingStorage = $this->resolver->findStorage();
        if ($existingStorage !== null) {
            return $existingStorage->getUid();
        }

        $absolutePath = $this->location->getAbsolutePath();
        GeneralUtility::mkdir_deep($absolutePath);
        if (!is_dir($absolutePath) || !is_writable($absolutePath)) {
            throw new ScreenshotStorageException(sprintf('Directory "%s" could not be created or is not writable.', $absolutePath), 1791561467);
        }

        $storageUid = $this->storageRepository->createLocalStorage(
            ScreenshotStorageLocation::STORAGE_NAME,
            $this->location->getPathRelativeToPublicPath(),
            'relative',
            'Screenshots of editor feedback (EXT:opinion). Not publicly accessible.',
        );
        // createLocalStorage() always creates a public storage. It stays browsable: FAL refuses
        // folder reads on non-browsable storages, and only admins have access without a file mount.
        $this->connectionPool->getConnectionForTable('sys_file_storage')->update(
            'sys_file_storage',
            ['is_public' => 0],
            ['uid' => $storageUid],
        );
        $this->storageRepository->flush();

        return $storageUid;
    }
}
