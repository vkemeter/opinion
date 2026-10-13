<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class FeedbackRepositoryTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function addStoresRecordAtRootLevel(): void
    {
        $uid = $this->get(FeedbackRepository::class)->add($this->createSubmission(), null);

        $record = $this->get(FeedbackRepository::class)->findByUid($uid);

        self::assertIsArray($record);
        self::assertSame(0, (int)$record['pid']);
        self::assertSame('frontend', $record['source']);
        self::assertSame('bug', $record['category']);
        self::assertSame('high', $record['severity']);
        self::assertSame(3, (int)$record['be_user']);
        self::assertSame('Ümlauts and <b>markup</b> stay as they are.', $record['message']);
        self::assertSame(['language' => 'de'], json_decode((string)$record['client_info'], true));
        self::assertSame(['typo3Version' => '14.3.0'], json_decode((string)$record['system_info'], true));
        self::assertSame(['pageUid' => 12], json_decode((string)$record['context'], true));
        self::assertSame('pending', $record['delivery_status']);
        self::assertSame(0, (int)$record['screenshot']);
        self::assertSame(1791561428, (int)$record['crdate']);
    }

    #[Test]
    public function addReferencesScreenshot(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $file = $this->get(ScreenshotStorage::class)->store((string)base64_decode(self::PNG_BASE64, true), 'png', new \DateTimeImmutable());

        $uid = $this->get(FeedbackRepository::class)->add($this->createSubmission(), $file);

        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'screenshot', $uid);
        self::assertCount(1, $references);
        self::assertSame($file->getUid(), $references[0]->getOriginalFile()->getUid());
        self::assertSame(1, (int)($this->get(FeedbackRepository::class)->findByUid($uid)['screenshot'] ?? 0));
    }

    #[Test]
    public function updateDeliveryStatusWritesStatusAndLog(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add($this->createSubmission(), null);

        $repository->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: Connection refused');

        $record = $repository->findByUid($uid);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertSame('email: Connection refused', $record['delivery_log'] ?? null);
    }

    #[Test]
    public function tableIsReadOnlyAdminOnlyRootLevel(): void
    {
        $ctrl = $GLOBALS['TCA'][FeedbackRepository::TABLE]['ctrl'];

        self::assertSame(1, $ctrl['rootLevel']);
        self::assertTrue($ctrl['adminOnly']);
        self::assertTrue($ctrl['readOnly']);
    }

    private function createSubmission(): Submission
    {
        return new Submission(
            message: 'Ümlauts and <b>markup</b> stay as they are.',
            category: Category::Bug,
            severity: Severity::High,
            source: Source::Frontend,
            backendUserUid: 3,
            createdAt: new \DateTimeImmutable('@1791561428'),
            clientInfo: ['language' => 'de'],
            systemInfo: ['typo3Version' => '14.3.0'],
            context: ['pageUid' => 12],
        );
    }
}
