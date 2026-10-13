<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Authentication\AdminRequestCheckerInterface;
use Vkemeter\Opinion\EventListener\RestrictScreenshotFileDump;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;
use Vkemeter\Opinion\Storage\ScreenshotStorageResolver;

final class RestrictScreenshotFileDumpTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(ScreenshotStorageInstaller::class)->install();
    }

    #[Test]
    public function listenerIsRegistered(): void
    {
        $services = array_column($this->get(ListenerProvider::class)->getAllListenerDefinitions()[ModifyFileDumpEvent::class] ?? [], 'service');

        self::assertContains(RestrictScreenshotFileDump::class, $services);
    }

    #[Test]
    public function screenshotIsDeniedWithoutAdminSession(): void
    {
        $event = $this->dispatch($this->createScreenshot(), false);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function screenshotIsServedWithAdminSession(): void
    {
        self::assertFalse($this->dispatch($this->createScreenshot(), true)->isPropagationStopped());
    }

    #[Test]
    public function processedScreenshotIsDeniedWithoutAdminSession(): void
    {
        $processedFile = $this->createScreenshot()->process('Image.Preview', ['width' => 1, 'height' => 1]);

        self::assertSame(403, $this->dispatch($processedFile, false)->getResponse()?->getStatusCode());
    }

    #[Test]
    public function filesOfOtherStoragesAreNotTouched(): void
    {
        GeneralUtility::mkdir_deep($this->instancePath . '/fileadmin/');
        $storageRepository = $this->get(StorageRepository::class);
        $storage = $storageRepository->findByUid($storageRepository->createLocalStorage('fileadmin', 'fileadmin/', 'relative'));
        self::assertNotNull($storage);
        $temporaryFile = GeneralUtility::tempnam('opinion_test_', '.png');
        file_put_contents($temporaryFile, self::png());
        $file = $storage->addFile($temporaryFile, $storage->getRootLevelFolder(false), 'public.png');

        self::assertFalse($this->dispatch($file, false)->isPropagationStopped());
    }

    private function createScreenshot(): File
    {
        return $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());
    }

    private function dispatch(\TYPO3\CMS\Core\Resource\ResourceInterface $file, bool $isAdmin): ModifyFileDumpEvent
    {
        $adminRequestChecker = self::createStub(AdminRequestCheckerInterface::class);
        $adminRequestChecker->method('isAdminRequest')->willReturn($isAdmin);
        $event = new ModifyFileDumpEvent($file, new ServerRequest('https://example.com/index.php?eID=dumpFile'));

        (new RestrictScreenshotFileDump($this->get(ScreenshotStorageResolver::class), $adminRequestChecker))($event);

        return $event;
    }

    private static function png(): string
    {
        return (string)base64_decode(self::PNG_BASE64, true);
    }
}
