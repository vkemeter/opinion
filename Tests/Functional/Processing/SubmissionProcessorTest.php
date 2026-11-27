<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Processing;

use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\DeliveryChannelInterface;
use Vkemeter\Opinion\Delivery\DeliveryResult;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Screenshot;
use Vkemeter\Opinion\Domain\ScreenshotType;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Domain\Video;
use Vkemeter\Opinion\Domain\VideoType;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;
use Vkemeter\Opinion\Factory\StoredSubmissionFactory;
use Vkemeter\Opinion\Processing\SubmissionProcessor;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;
use Vkemeter\Opinion\Tests\Functional\Fixtures\CollectingLogger;

final class SubmissionProcessorTest extends FunctionalTestCase
{
    private const string WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\x82\x84webm\x42\x87rest";
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    /**
     * Scenario: All channels disabled
     */
    #[Test]
    public function withoutDeliveryChannelsTheFeedbackIsSkipped(): void
    {
        $result = $this->createProcessor([])->process($this->createSubmission(), $this->configuration());

        $record = $this->get(FeedbackRepository::class)->findByUid($result->feedbackUid);
        self::assertSame(DeliveryStatus::Skipped, $result->status);
        self::assertSame('skipped', $record['delivery_status'] ?? null);
        self::assertSame('no delivery channel enabled', $record['delivery_log'] ?? null);
    }

    /**
     * Scenario: Email disabled
     */
    #[Test]
    public function disabledChannelsDoNotRun(): void
    {
        $email = $this->createChannel('email', static fn(): DeliveryResult => throw new \LogicException('email must not run', 1795000001));
        $jira = $this->createChannel('jira', static fn(): DeliveryResult => DeliveryResult::success('jira'));

        $result = $this->createProcessor([$email, $jira])->process($this->createSubmission(), $this->configuration(disabledDeliveryChannels: ['email']));
        self::assertSame(DeliveryStatus::Sent, $result->status);
        self::assertSame(['jira'], array_map(static fn(DeliveryResult $result): string => $result->channel, $result->deliveryResults));

        $result = $this->createProcessor([$email, $jira])->process($this->createSubmission(), $this->configuration(disabledDeliveryChannels: ['email', 'jira']));
        self::assertSame(DeliveryStatus::Skipped, $result->status);
    }

    /**
     * Scenario: Unknown identifier
     */
    #[Test]
    public function unknownDisabledChannelIsLoggedAndAllChannelsRun(): void
    {
        $logger = new CollectingLogger();
        $ran = [];
        $email = $this->createChannel('email', static function () use (&$ran): DeliveryResult {
            $ran[] = 'email';
            return DeliveryResult::success('email');
        });
        $jira = $this->createChannel('jira', static function () use (&$ran): DeliveryResult {
            $ran[] = 'jira';
            return DeliveryResult::success('jira');
        });

        $result = new SubmissionProcessor($this->get(FeedbackRepository::class), $this->get(ScreenshotStorage::class), [$email, $jira], $logger, $this->get(ClockInterface::class))
            ->process($this->createSubmission(), $this->configuration(disabledDeliveryChannels: ['emial']));

        self::assertSame(DeliveryStatus::Sent, $result->status);
        self::assertSame(['email', 'jira'], $ran, 'every channel runs');
        $warnings = $logger->messages('warning');
        self::assertCount(1, $warnings);
        self::assertStringContainsString('emial', $warnings[0], 'the warning names the identifier');
    }

    /**
     * Scenario: Custom channel disabled
     */
    #[Test]
    public function disabledCustomChannelDoesNotRunAndTheEmailChannelStillDoes(): void
    {
        $email = $this->createChannel('email', static fn(): DeliveryResult => DeliveryResult::success('email'));
        $jira = $this->createChannel('jira', static fn(): DeliveryResult => throw new \LogicException('jira must not run', 1795000002));

        $result = $this->createProcessor([$email, $jira])->process($this->createSubmission(), $this->configuration(disabledDeliveryChannels: ['jira']));

        self::assertSame(DeliveryStatus::Sent, $result->status);
        self::assertSame(['email'], array_map(static fn(DeliveryResult $result): string => $result->channel, $result->deliveryResults));
        self::assertStringStartsWith('email: OK', (string)($this->get(FeedbackRepository::class)->findByUid($result->feedbackUid)['delivery_log'] ?? ''));
        self::assertStringNotContainsString('jira', (string)($this->get(FeedbackRepository::class)->findByUid($result->feedbackUid)['delivery_log'] ?? ''));
    }

    /**
     * Requirement: Stored before delivery (an already stored screenshot is deleted when the record cannot be written)
     */
    #[Test]
    public function screenshotAndVideoAreDeletedAgainWhenTheRecordCannotBeWritten(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $realConnectionPool = $this->get(ConnectionPool::class);
        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturnCallback(
            static fn(string $table): Connection => $table === FeedbackRepository::TABLE
                ? throw new \RuntimeException('Server has gone away', 1795000003)
                : $realConnectionPool->getConnectionForTable($table),
        );
        $processor = new SubmissionProcessor(new FeedbackRepository($connectionPool, $this->get(ConfigurationProviderInterface::class)), $this->get(ScreenshotStorage::class), [], new NullLogger(), $this->get(ClockInterface::class));
        // A month no other test of this class writes into
        $folder = Environment::getVarPath() . '/opinion/2027/03';

        foreach ([true, false] as $withScreenshot) {
            try {
                $processor->process($this->createSubmission(withScreenshot: $withScreenshot, withVideo: !$withScreenshot, createdAt: '2027-03-01 12:00:00'), $this->configuration());
                self::fail('Expected exception');
            } catch (\RuntimeException $exception) {
                self::assertSame(1795000003, $exception->getCode());
            }
            self::assertSame([], glob($folder . '/*'), $withScreenshot ? 'screenshot left behind' : 'video left behind');
        }
        self::assertNull($this->get(FeedbackRepository::class)->findByUid(1));
    }

    /**
     * Requirement: Delivery status (sent when every channel succeeded)
     */
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

    /**
     * Scenario: Throwing channel
     */
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

    /**
     * Requirement: Delivery status (a failure to write the status does not turn the submission into an error)
     */
    #[Test]
    public function failingStatusUpdateAfterDeliveryStillReturnsTheResult(): void
    {
        $database = new \ArrayObject(['down' => false]);
        $realConnectionPool = $this->get(ConnectionPool::class);
        $connectionPool = self::createStub(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willReturnCallback(
            static function (string $table) use ($database, $realConnectionPool): Connection {
                if ($database['down'] === true) {
                    throw new \RuntimeException('Server has gone away', 1792483201);
                }
                return $realConnectionPool->getConnectionForTable($table);
            },
        );
        $channel = $this->createChannel('test', static function () use ($database): DeliveryResult {
            $database['down'] = true;
            return DeliveryResult::success('test');
        });

        $result = new SubmissionProcessor(new FeedbackRepository($connectionPool, $this->get(ConfigurationProviderInterface::class)), $this->get(ScreenshotStorage::class), [$channel], new NullLogger(), $this->get(ClockInterface::class))
            ->process($this->createSubmission(), $this->configuration());

        self::assertSame(DeliveryStatus::Sent, $result->status);
        self::assertSame('pending', $this->get(FeedbackRepository::class)->findByUid($result->feedbackUid)['delivery_status'] ?? null);
    }

    /**
     * Requirement: Stored before delivery
     */
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

    /**
     * Scenario: Custom channel
     * Scenario: Module changed during recording
     */
    #[Test]
    public function videoIsStoredReferencedAndPassedToTheChannelsWithTheEndContext(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $received = null;
        $channel = $this->createChannel('test', static function (Submission $submission, ?File $screenshot) use (&$received): DeliveryResult {
            $received = [$submission, $screenshot];
            return DeliveryResult::success('test');
        });
        $submission = new Submission(
            message: 'The menu closes too early.',
            category: 'bug',
            severity: Severity::Normal,
            source: Source::Backend,
            backendUserUid: 1,
            createdAt: new \DateTimeImmutable('2026-10-09 12:00:00'),
            context: ['module' => 'web_layout'],
            video: new Video(self::WEBM, VideoType::Webm),
            contextAtEnd: ['module' => 'web_list'],
            backendUrl: 'https://example.com/typo3',
        );

        $result = $this->createProcessor([$channel])->process($submission, $this->configuration());

        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'video', $result->feedbackUid);
        self::assertCount(1, $references);
        self::assertSame('webm', $references[0]->getOriginalFile()->getExtension());
        self::assertSame(self::WEBM, $references[0]->getOriginalFile()->getContents());
        self::assertIsArray($received);
        [$delivered, $screenshot] = $received;
        self::assertNull($screenshot);
        self::assertSame($result->feedbackUid, $delivered->feedbackUid);
        self::assertSame($references[0]->getOriginalFile()->getUid(), $delivered->videoFile?->getUid());
        $record = $this->get(FeedbackRepository::class)->findByUid($result->feedbackUid);
        self::assertSame(1, (int)($record['video'] ?? 0));
        self::assertSame('https://example.com/typo3', $record['backend_url'] ?? null);
        self::assertSame(['module' => 'web_list'], StoredSubmissionFactory::decode($record['context_at_end'] ?? null));
    }

    /**
     * Scenario: Screenshot storage missing
     */
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
            $this->get(ClockInterface::class),
        );
    }

    private function createChannel(string $identifier, \Closure $deliver): DeliveryChannelInterface
    {
        $channel = self::createStub(DeliveryChannelInterface::class);
        $channel->method('getIdentifier')->willReturn($identifier);
        $channel->method('deliver')->willReturnCallback($deliver);

        return $channel;
    }

    private function createSubmission(bool $withScreenshot = false, bool $withVideo = false, string $createdAt = '2026-10-09 12:00:00'): Submission
    {
        return new Submission(
            message: 'The teaser image is cropped.',
            category: 'bug',
            severity: Severity::Normal,
            source: Source::Frontend,
            backendUserUid: 1,
            createdAt: new \DateTimeImmutable($createdAt),
            screenshot: $withScreenshot ? new Screenshot(base64_decode(self::PNG_BASE64, true), ScreenshotType::Png) : null,
            video: $withVideo ? new Video(self::WEBM, VideoType::Webm) : null,
        );
    }

    /**
     * @param list<string> $disabledDeliveryChannels
     */
    private function configuration(array $disabledDeliveryChannels = []): OpinionConfiguration
    {
        return new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180, disabledDeliveryChannels: $disabledDeliveryChannels);
    }
}
