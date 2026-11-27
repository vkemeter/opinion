<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Redelivery;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\DeliveryChannelInterface;
use Vkemeter\Opinion\Delivery\DeliveryResult;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Factory\StoredSubmissionFactory;
use Vkemeter\Opinion\Processing\SubmissionProcessor;
use Vkemeter\Opinion\Redelivery\Redeliverer;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;
use Vkemeter\Opinion\Tests\Functional\Fixtures\CollectingLogger;

final class RedelivererTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    /**
     * Scenario: Retry after outage
     */
    #[Test]
    public function redeliverPassesTheStoredSubmissionAndScreenshotAndAppendsTheLog(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $createdAt = new \DateTimeImmutable('2026-10-09 12:00:00');
        $screenshot = $this->get(ScreenshotStorage::class)->store(base64_decode(self::PNG_BASE64, true), 'png', $createdAt);
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission(
            message: 'The teaser image is cropped.',
            category: 'content',
            severity: Severity::High,
            source: Source::Backend,
            backendUserUid: 3,
            createdAt: $createdAt,
            clientInfo: ['language' => 'de'],
            systemInfo: ['typo3Version' => '14.3.0'],
            context: ['module' => 'web_layout'],
        ), $screenshot);
        $repository->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'test: FAILED Connection refused');

        $received = [];
        $channel = self::createStub(DeliveryChannelInterface::class);
        $channel->method('getIdentifier')->willReturn('test');
        $channel->method('deliver')->willReturnCallback(static function (Submission $submission, ?File $file) use (&$received): DeliveryResult {
            $received = [$submission, $file];
            return DeliveryResult::success('test', 'delivered');
        });

        $result = $this->createRedeliverer([$channel])->redeliver($uid, new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180));

        self::assertSame(DeliveryStatus::Sent, $result?->status);
        [$submission, $file] = $received;
        self::assertInstanceOf(Submission::class, $submission);
        self::assertSame('The teaser image is cropped.', $submission->message);
        self::assertSame('content', $submission->category);
        self::assertSame(Severity::High, $submission->severity);
        self::assertSame(Source::Backend, $submission->source);
        self::assertSame(3, $submission->backendUserUid);
        self::assertSame($createdAt->getTimestamp(), $submission->createdAt->getTimestamp());
        self::assertSame(['language' => 'de'], $submission->clientInfo);
        self::assertSame(['module' => 'web_layout'], $submission->context);
        self::assertInstanceOf(File::class, $file);
        self::assertSame($screenshot->getUid(), $file->getUid());

        $record = $repository->findByUid($uid);
        self::assertSame('sent', $record['delivery_status'] ?? null);
        self::assertSame(2, (int)($record['delivery_attempts'] ?? 0));
        self::assertMatchesRegularExpression('/^test: FAILED Connection refused\n\[[\d: -]{16}\] test: OK delivered$/', (string)($record['delivery_log'] ?? ''));
    }

    /**
     * Scenario: Redelivery from the CLI
     */
    #[Test]
    public function redeliverPassesTheVideoTheUidAndTheStoredBackendUrl(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $createdAt = new \DateTimeImmutable('2026-10-09 12:00:00');
        $video = $this->get(ScreenshotStorage::class)->store("\x00\x00\x00\x18ftypisom", 'mp4', $createdAt);
        $uid = $this->get(FeedbackRepository::class)->add(new Submission(
            message: 'The menu closes too early.',
            category: 'bug',
            severity: Severity::Normal,
            source: Source::Backend,
            backendUserUid: 3,
            createdAt: $createdAt,
            context: ['module' => 'web_layout'],
            contextAtEnd: ['module' => 'web_list'],
            backendUrl: 'https://example.com/typo3',
        ), null, $video);

        $received = null;
        $channel = self::createStub(DeliveryChannelInterface::class);
        $channel->method('getIdentifier')->willReturn('test');
        $channel->method('deliver')->willReturnCallback(static function (Submission $submission) use (&$received): DeliveryResult {
            $received = $submission;
            return DeliveryResult::success('test');
        });

        $this->createRedeliverer([$channel])->redeliver($uid, new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180));

        self::assertInstanceOf(Submission::class, $received);
        self::assertSame($uid, $received->feedbackUid);
        self::assertSame('https://example.com/typo3', $received->backendUrl);
        self::assertSame(['module' => 'web_list'], $received->contextAtEnd);
        self::assertSame($video->getUid(), $received->videoFile?->getUid());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function corruptFieldProvider(): iterable
    {
        yield 'unknown severity' => ['severity', 'urgent'];
        yield 'unknown source' => ['source', 'cli'];
    }

    /**
     * Scenario: Corrupt severity
     */
    #[Test]
    #[DataProvider('corruptFieldProvider')]
    public function feedbackThatCannotBeRebuiltCountsAsFailedAttempt(string $field, string $value): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission(
            message: 'The teaser image is cropped.',
            category: 'content',
            severity: Severity::High,
            source: Source::Backend,
            backendUserUid: 3,
            createdAt: new \DateTimeImmutable('2026-10-09 12:00:00'),
        ), null);
        $repository->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'test: FAILED Connection refused');
        $this->get(ConnectionPool::class)->getConnectionForTable(FeedbackRepository::TABLE)->update(FeedbackRepository::TABLE, [$field => $value], ['uid' => $uid]);

        $delivered = [];
        $channel = $this->createCapturingChannel($delivered);
        $logger = new CollectingLogger();

        $result = $this->createRedeliverer([$channel], $logger)->redeliver($uid, new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180));

        self::assertSame(DeliveryStatus::Failed, $result?->status);
        self::assertSame([], $delivered, 'not delivered');
        self::assertCount(1, $logger->messages('error'), 'an error is logged');
        self::assertStringContainsString((string)$uid, $logger->messages('error')[0]);
        $record = $repository->findByUid($uid);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertSame(2, (int)($record['delivery_attempts'] ?? 0));
        self::assertMatchesRegularExpression('/^test: FAILED Connection refused\n\[[\d: -]{16}\] cannot be delivered again: .*' . $value . '/', (string)($record['delivery_log'] ?? ''));
    }

    /**
     * Scenario: Corrupt category
     * Scenario: Removed category on redelivery
     */
    #[Test]
    public function categoryThatIsNotConfiguredIsDeliveredAsStored(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission('The teaser image is cropped.', 'feature', Severity::High, Source::Backend, 3, new \DateTimeImmutable('2026-10-09 12:00:00')), null);
        $repository->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'test: FAILED Connection refused');

        $delivered = [];
        $channel = $this->createCapturingChannel($delivered);

        $result = $this->createRedeliverer([$channel])->redeliver($uid, new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180));

        self::assertSame(DeliveryStatus::Sent, $result?->status);
        self::assertSame(['feature'], array_map(static fn(Submission $submission): string => $submission->category, $delivered));
    }

    /**
     * Scenario: Unknown feedback
     */
    #[Test]
    public function unknownFeedbackIsNotDelivered(): void
    {
        self::assertNull($this->createRedeliverer([])->redeliver(999, new OpinionConfiguration(true, [], '', 180)));
    }

    /**
     * @param list<Submission> $delivered receives every delivered submission
     */
    private function createCapturingChannel(array &$delivered): DeliveryChannelInterface
    {
        $channel = self::createStub(DeliveryChannelInterface::class);
        $channel->method('getIdentifier')->willReturn('test');
        $channel->method('deliver')->willReturnCallback(static function (Submission $submission) use (&$delivered): DeliveryResult {
            $delivered[] = $submission;
            return DeliveryResult::success('test', 'OK');
        });

        return $channel;
    }

    /**
     * @param list<DeliveryChannelInterface> $channels
     */
    private function createRedeliverer(array $channels, ?LoggerInterface $logger = null): Redeliverer
    {
        $repository = $this->get(FeedbackRepository::class);

        return new Redeliverer(
            $repository,
            $this->get(FileRepository::class),
            new StoredSubmissionFactory(),
            new SubmissionProcessor($repository, $this->get(ScreenshotStorage::class), $channels, new NullLogger(), $this->get(ClockInterface::class)),
            $logger ?? new NullLogger(),
            $this->get(ClockInterface::class),
        );
    }
}
