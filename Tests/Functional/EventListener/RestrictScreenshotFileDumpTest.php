<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceInterface;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\EventListener\RestrictScreenshotFileDump;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

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

    /**
     * Scenario: Editor opens the file dump URL
     */
    #[Test]
    public function listenerIsRegistered(): void
    {
        $services = array_column($this->get(ListenerProvider::class)->getAllListenerDefinitions()[ModifyFileDumpEvent::class] ?? [], 'service');

        self::assertContains(RestrictScreenshotFileDump::class, $services);
    }

    /**
     * Scenario: Editor opens the file dump URL
     */
    #[Test]
    public function screenshotIsAlwaysDenied(): void
    {
        $event = $this->dispatch($this->createScreenshot());

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    /**
     * Scenario: File dump URL with administrator session
     */
    #[Test]
    public function screenshotIsDeniedAlsoWithAdminSession(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);

        self::assertSame(403, $this->dispatch($this->createScreenshot())->getResponse()?->getStatusCode());
    }

    /**
     * Scenario: Video file dump URL
     */
    #[Test]
    public function videoIsDeniedAlsoWithAdminSession(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(1);
        $video = $this->get(ScreenshotStorage::class)->store("\x00\x00\x00\x18ftypisom", 'mp4', new \DateTimeImmutable());

        self::assertSame(403, $this->dispatch($video)->getResponse()?->getStatusCode());
    }

    /**
     * Scenario: File dump URL with administrator session
     */
    #[Test]
    public function processedScreenshotIsDenied(): void
    {
        $processedFile = $this->createScreenshot()->process('Image.Preview', ['width' => 1, 'height' => 1]);

        self::assertSame(403, $this->dispatch($processedFile)->getResponse()?->getStatusCode());
    }

    /**
     * Scenario: Other storages untouched
     */
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

        self::assertFalse($this->dispatch($file)->isPropagationStopped());
    }

    private function createScreenshot(): File
    {
        return $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());
    }

    private function dispatch(ResourceInterface $file): ModifyFileDumpEvent
    {
        $event = new ModifyFileDumpEvent($file, new ServerRequest('https://example.com/index.php?eID=dumpFile'));
        $this->get(RestrictScreenshotFileDump::class)($event);

        return $event;
    }

    private static function png(): string
    {
        return base64_decode(self::PNG_BASE64, true);
    }
}
