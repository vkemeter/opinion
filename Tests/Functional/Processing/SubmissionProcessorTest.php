<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Processing;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\DeliveryChannelInterface;
use Vkemeter\Opinion\Delivery\DeliveryResult;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Screenshot;
use Vkemeter\Opinion\Domain\ScreenshotType;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;
use Vkemeter\Opinion\Processing\SubmissionProcessor;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class SubmissionProcessorTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function withoutDeliveryChannelsTheFeedbackStaysPending(): void
    {
        $result = $this->createProcessor([])->process($this->createSubmission(), $this->configuration());

        self::assertSame(DeliveryStatus::Pending, $result->status);
        self::assertSame('pending', $this->get(FeedbackRepository::class)->findByUid($result->feedbackUid)['delivery_status'] ?? null);
    }

    #[Test]
    public function successfulDeliveryMarksFeedbackAsSent(): void
    {
        $channel = $this->createChannel('test', static fn(): DeliveryResult => DeliveryResult::success('test', 'delivered'));

        $result = $this->createProcessor([$channel])->process($this->createSubmission(), $this->configuration());

        $record = $this->get(FeedbackRepository::class)->findByUid($result->feedbackUid);
        self::assertSame(DeliveryStatus::Sent, $result->status);
        self::assertSame('sent', $record['delivery_status'] ?? null);
        self::assertSame('test: OK delivered', $record['delivery_log'] ?? null);
    }

    #[Test]
    public function failingDeliveryKeepsFeedbackAndMarksItAsFailed(): void
    {
        $failing = $this->createChannel('mail', static fn(): DeliveryResult => throw new \RuntimeException('Connection refused', 1791562607));
        $working = $this->createChannel('test', static fn(): DeliveryResult => DeliveryResult::success('test'));

        $result = $this->createProcessor([$failing, $working])->process($this->createSubmission(), $this->configuration());

        $record = $this->get(FeedbackRepository::class)->findByUid($result->feedbackUid);
        self::assertSame(DeliveryStatus::Failed, $result->status);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertStringContainsString('mail: FAILED Connection refused', (string)($record['delivery_log'] ?? ''));
        self::assertStringContainsString('test: OK', (string)($record['delivery_log'] ?? ''));
    }

    #[Test]
    public function screenshotIsStoredReferencedAndPassedToTheChannels(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $receivedScreenshot = null;
        $channel = $this->createChannel('test', static function (Submission $submission, ?File $screenshot) use (&$receivedScreenshot): DeliveryResult {
            $receivedScreenshot = $screenshot;
            return DeliveryResult::success('test');
        });

        $result = $this->createProcessor([$channel])->process($this->createSubmission(withScreenshot: true), $this->configuration());

        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'screenshot', $result->feedbackUid);
        self::assertCount(1, $references);
        self::assertInstanceOf(File::class, $receivedScreenshot);
        self::assertSame($receivedScreenshot->getUid(), $references[0]->getOriginalFile()->getUid());
        self::assertFalse($receivedScreenshot->getStorage()->isPublic());
    }

    #[Test]
    public function screenshotWithoutStorageIsRejectedAndNothingIsStored(): void
    {
        try {
            $this->createProcessor([])->process($this->createSubmission(withScreenshot: true), $this->configuration());
            self::fail('Expected exception');
        } catch (ScreenshotStorageException) {
            self::assertNull($this->get(FeedbackRepository::class)->findByUid(1));
        }
    }

    /**
     * @param list<DeliveryChannelInterface> $channels
     */
    private function createProcessor(array $channels): SubmissionProcessor
    {
        return new SubmissionProcessor(
            $this->get(FeedbackRepository::class),
            $this->get(ScreenshotStorage::class),
            $channels,
            new NullLogger(),
        );
    }

    private function createChannel(string $identifier, \Closure $deliver): DeliveryChannelInterface
    {
        $channel = self::createStub(DeliveryChannelInterface::class);
        $channel->method('getIdentifier')->willReturn($identifier);
        $channel->method('deliver')->willReturnCallback($deliver);

        return $channel;
    }

    private function createSubmission(bool $withScreenshot = false): Submission
    {
        return new Submission(
            message: 'The teaser image is cropped.',
            category: Category::Bug,
            severity: Severity::Normal,
            source: Source::Frontend,
            backendUserUid: 1,
            createdAt: new \DateTimeImmutable('2026-10-09 12:00:00'),
            screenshot: $withScreenshot ? new Screenshot((string)base64_decode(self::PNG_BASE64, true), ScreenshotType::Png) : null,
        );
    }

    private function configuration(): OpinionConfiguration
    {
        return new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180);
    }
}
