<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Redelivery;

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
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Factory\StoredSubmissionFactory;
use Vkemeter\Opinion\Processing\SubmissionProcessor;
use Vkemeter\Opinion\Redelivery\Redeliverer;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class RedelivererTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function redeliverPassesTheStoredSubmissionAndScreenshotAndAppendsTheLog(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $createdAt = new \DateTimeImmutable('2026-10-09 12:00:00');
        $screenshot = $this->get(ScreenshotStorage::class)->store((string)base64_decode(self::PNG_BASE64, true), 'png', $createdAt);
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission(
            message: 'The teaser image is cropped.',
            category: Category::Content,
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
        self::assertSame(Category::Content, $submission->category);
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

    #[Test]
    public function unknownFeedbackIsNotDelivered(): void
    {
        self::assertNull($this->createRedeliverer([])->redeliver(999, new OpinionConfiguration(true, [], '', 180)));
    }

    /**
     * @param list<DeliveryChannelInterface> $channels
     */
    private function createRedeliverer(array $channels): Redeliverer
    {
        $repository = $this->get(FeedbackRepository::class);

        return new Redeliverer(
            $repository,
            $this->get(FileRepository::class),
            new StoredSubmissionFactory(),
            new SubmissionProcessor($repository, $this->get(ScreenshotStorage::class), $channels, new NullLogger()),
            new NullLogger(),
        );
    }
}
