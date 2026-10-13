<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Package\Event\PackageInitializationEvent;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

#[AsEventListener('opinion/create-screenshot-storage')]
final readonly class CreateScreenshotStorage
{
    public function __construct(
        private ScreenshotStorageInstaller $installer,
    ) {}

    public function __invoke(PackageInitializationEvent $event): void
    {
        if ($event->getExtensionKey() !== 'opinion') {
            return;
        }

        $event->addStorageEntry(self::class, $this->installer->install());
    }
}
