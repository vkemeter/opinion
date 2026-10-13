<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

final readonly class ScreenshotStorageResolver
{
    public function __construct(
        private StorageRepository $storageRepository,
        private ScreenshotStorageLocation $location,
    ) {}

    public function findStorage(): ?ResourceStorage
    {
        foreach ($this->storageRepository->findByStorageType('Local') as $storage) {
            if ($this->location->matches($storage->getConfiguration())) {
                return $storage;
            }
        }

        return null;
    }
}
