<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\EventDispatcher\ListenerProvider;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\Event\ModifyFileDumpEvent;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Authentication\AdminRequestCheckerInterface;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\EventListener\RestrictScreenshotFileDump;
use Vkemeter\Opinion\Repository\FeedbackRepository;
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
    public function screenshotIsDeniedWithoutSession(): void
    {
        $event = $this->dispatch($this->createScreenshot(), null);

        self::assertTrue($event->isPropagationStopped());
        self::assertSame(403, $event->getResponse()?->getStatusCode());
    }

    #[Test]
    public function screenshotIsServedWithAdminSession(): void
    {
        self::assertFalse($this->dispatch($this->createScreenshot(), $this->createBackendUser(1, true))->isPropagationStopped());
    }

    #[Test]
    public function screenshotIsServedToTheSenderOnly(): void
    {
        $screenshot = $this->createScreenshot(senderUid: 3);

        self::assertFalse($this->dispatch($screenshot, $this->createBackendUser(3, false))->isPropagationStopped(), 'sender');
        self::assertSame(403, $this->dispatch($screenshot, $this->createBackendUser(5, false))->getResponse()?->getStatusCode(), 'other editor');
        self::assertSame(403, $this->dispatch($screenshot, $this->createBackendUser(3, false, mayUseFeedback: false))->getResponse()?->getStatusCode(), 'sender without access');
    }

    #[Test]
    public function processedScreenshotIsDeniedWithoutSession(): void
    {
        $processedFile = $this->createScreenshot()->process('Image.Preview', ['width' => 1, 'height' => 1]);

        self::assertSame(403, $this->dispatch($processedFile, null)->getResponse()?->getStatusCode());
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

        self::assertFalse($this->dispatch($file, null)->isPropagationStopped());
    }

    /**
     * A screenshot stored with a feedback of the given sender.
     */
    private function createScreenshot(int $senderUid = 1): File
    {
        $screenshot = $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());
        $this->get(FeedbackRepository::class)->add(new Submission('Teaser', Category::Bug, Severity::Low, Source::Backend, $senderUid, new \DateTimeImmutable()), $screenshot);

        return $screenshot;
    }

    private function createBackendUser(int $uid, bool $isAdmin, bool $mayUseFeedback = true): BackendUserAuthentication
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => $uid];
        $backendUser->method('isAdmin')->willReturn($isAdmin);
        $backendUser->method('getTSConfig')->willReturn($mayUseFeedback ? ['options.' => ['opinion.' => ['enable' => '1']]] : []);

        return $backendUser;
    }

    private function dispatch(\TYPO3\CMS\Core\Resource\ResourceInterface $file, ?BackendUserAuthentication $backendUser): ModifyFileDumpEvent
    {
        $adminRequestChecker = self::createStub(AdminRequestCheckerInterface::class);
        $adminRequestChecker->method('getAuthenticatedBackendUser')->willReturn($backendUser);
        $event = new ModifyFileDumpEvent($file, new ServerRequest('https://example.com/index.php?eID=dumpFile'));

        (new RestrictScreenshotFileDump(
            $this->get(ScreenshotStorageResolver::class),
            $adminRequestChecker,
            $this->get(AccessChecker::class),
            $this->get(FeedbackRepository::class),
        ))($event);

        return $event;
    }

    private static function png(): string
    {
        return base64_decode(self::PNG_BASE64, true);
    }
}
