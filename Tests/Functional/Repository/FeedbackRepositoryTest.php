<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackFilter;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class FeedbackRepositoryTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    /**
     * Requirement: Record location and access
     * Scenario: New submission
     */
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
        self::assertSame('new', $record['status']);
        self::assertSame(0, (int)$record['screenshot']);
        self::assertSame(1791561428, (int)$record['crdate']);
    }

    /**
     * Requirement: Stored before delivery
     */
    #[Test]
    public function addReferencesScreenshot(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $file = $this->get(ScreenshotStorage::class)->store(base64_decode(self::PNG_BASE64, true), 'png', new \DateTimeImmutable());

        $uid = $this->get(FeedbackRepository::class)->add($this->createSubmission(), $file);

        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'screenshot', $uid);
        self::assertCount(1, $references);
        self::assertSame($file->getUid(), $references[0]->getOriginalFile()->getUid());
        self::assertSame(1, (int)($this->get(FeedbackRepository::class)->findByUid($uid)['screenshot'] ?? 0));
    }

    /**
     * Requirement: Video files
     */
    #[Test]
    public function addReferencesVideo(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $file = $this->get(ScreenshotStorage::class)->store("\x00\x00\x00\x18ftypisom", 'mp4', new \DateTimeImmutable());

        $uid = $this->get(FeedbackRepository::class)->add($this->createSubmission(), null, $file);

        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'video', $uid);
        self::assertCount(1, $references);
        self::assertSame($file->getUid(), $references[0]->getOriginalFile()->getUid());
        self::assertSame([], $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'screenshot', $uid));
    }

    /**
     * Scenario: Own list
     */
    #[Test]
    public function queriesCanBeRestrictedToOneSender(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $foreign = $repository->add($this->createSubmission(), null);
        $own = $repository->add(new Submission('Own', 'bug', Severity::Low, Source::Backend, 7, new \DateTimeImmutable()), null);

        self::assertSame(2, $repository->countAll());
        self::assertSame(1, $repository->countAll(7));
        self::assertSame([$own], array_map(static fn(array $record): int => (int)$record['uid'], $repository->findPage(50, 0, 7)));
        self::assertNull($repository->findByUid($foreign, 7));
        self::assertNotNull($repository->findByUid($own, 7));
        self::assertNotNull($repository->findByUid($foreign));
    }

    /**
     * Scenario: Open bugs
     * Scenario: Search by page
     * Scenario: Editor searches
     */
    #[Test]
    public function listCanBeFilteredAndSearched(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $repository = $this->get(FeedbackRepository::class);
        $teaser = $repository->add(new Submission('Teaser is cropped', 'bug', Severity::High, Source::Frontend, 1, new \DateTimeImmutable(), ['url' => 'https://example.com/produkte/über'], [], ['pageTitle' => 'Produkte']), null);
        $idea = $repository->add(new Submission('Add a 100% better search', 'idea', Severity::Low, Source::Backend, 3, new \DateTimeImmutable()), null);
        $closed = $repository->add(new Submission('Old bug', 'bug', Severity::Low, Source::Backend, 3, new \DateTimeImmutable()), null);
        $repository->updateStatus($closed, 'closed');
        $repository->updateDeliveryStatus($closed, DeliveryStatus::Failed, '');

        $find = static fn(FeedbackFilter $filter, ?int $senderUid = null): array => array_map(
            static fn(array $record): int => (int)$record['uid'],
            $repository->findPage(50, 0, $senderUid, $filter),
        );

        self::assertEqualsCanonicalizing([$teaser, $closed], $find(new FeedbackFilter(category: 'bug')));
        self::assertSame([$closed], $find(new FeedbackFilter(status: 'closed')));
        self::assertSame([$closed], $find(new FeedbackFilter(category: 'bug', severity: Severity::Low)));
        self::assertSame([$closed], $find(new FeedbackFilter(deliveryStatus: DeliveryStatus::Failed)));
        self::assertSame([$teaser], $find(new FeedbackFilter(source: Source::Frontend)));
        self::assertSame(2, $repository->countAll(null, new FeedbackFilter(category: 'bug')));

        self::assertSame([$teaser], $find(new FeedbackFilter(search: 'cropped')), 'message');
        self::assertSame([$teaser], $find(new FeedbackFilter(search: '/produkte/über')), 'URL with escaped slashes and umlaut');
        self::assertSame([$teaser], $find(new FeedbackFilter(search: 'Produkte')), 'page title');
        self::assertEqualsCanonicalizing([$idea, $closed], $find(new FeedbackFilter(search: 'Rita')), 'sender name');
        self::assertSame([$idea], $find(new FeedbackFilter(search: '100%')), 'wildcard escaped');
        self::assertSame([], $find(new FeedbackFilter(search: '_')), 'wildcard escaped');

        self::assertSame([$closed], $find(new FeedbackFilter(search: 'bug'), 3), 'sender restriction kept');
        self::assertSame(1, $repository->countAll(3, new FeedbackFilter(category: 'bug')));
    }

    /**
     * Requirement: Administrators change the status (does not change the delivery status)
     */
    #[Test]
    public function updateStatusLeavesTheDeliveryAlone(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add($this->createSubmission(), null);
        $repository->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: Connection refused');

        $repository->updateStatus($uid, 'closed');

        $record = $repository->findByUid($uid);
        self::assertSame('closed', $record['status'] ?? null);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertSame(1, (int)($record['delivery_attempts'] ?? 0));
    }

    /**
     * Requirement: Delivery status (every attempt increases the counter)
     */
    #[Test]
    public function updateDeliveryStatusWritesStatusAndLog(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add($this->createSubmission(), null);

        $repository->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: Connection refused');

        $record = $repository->findByUid($uid);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertSame('email: Connection refused', $record['delivery_log'] ?? null);
        self::assertSame(1, (int)($record['delivery_attempts'] ?? 0));

        $repository->updateDeliveryStatus($uid, DeliveryStatus::Sent, 'email: OK');
        self::assertSame(2, (int)($repository->findByUid($uid)['delivery_attempts'] ?? 0));
    }

    /**
     * Scenario: Attempt limit
     * Scenario: Young record
     */
    #[Test]
    public function findUndeliveredUidsHonoursStatusAgeAndAttempts(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $pending = $repository->add($this->createSubmission(), null);
        $failedTwice = $repository->add($this->createSubmission(), null);
        $repository->updateDeliveryStatus($failedTwice, DeliveryStatus::Failed, '');
        $repository->updateDeliveryStatus($failedTwice, DeliveryStatus::Failed, '');
        $sent = $repository->add($this->createSubmission(), null);
        $repository->updateDeliveryStatus($sent, DeliveryStatus::Sent, '');

        self::assertSame([$pending, $failedTwice], $repository->findUndeliveredUids(1791561429, 0));
        self::assertSame([$pending], $repository->findUndeliveredUids(1791561429, 2));
        self::assertSame([], $repository->findUndeliveredUids(1791561428, 0), 'created at 1791561428, not before');
    }

    /**
     * Scenario: Several statuses
     * Scenario: All feedback
     */
    #[Test]
    public function findUidsForCleanupHonoursAgeAndStatus(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $oldClosed = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Old closed', '-40 days'), null);
        $repository->updateStatus($oldClosed, 'closed');
        $oldNew = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Old new', '-40 days'), null);
        $nowClosed = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Closed now', 'now'), null);
        $repository->updateStatus($nowClosed, 'closed');
        $nowInProgress = $repository->add($this->createPageSubmission(12, Source::Frontend, 'In progress now', 'now'), null);
        $repository->updateStatus($nowInProgress, 'in_progress');
        $tenDaysAgo = new \DateTimeImmutable('-10 days')->getTimestamp();

        self::assertSame([$oldClosed, $oldNew], $repository->findUidsForCleanup($tenDaysAgo));
        self::assertSame([$oldClosed, $oldNew, $nowClosed, $nowInProgress], $repository->findUidsForCleanup(null), 'any age, also the current second');
        self::assertSame([$oldClosed, $nowClosed], $repository->findUidsForCleanup(null, ['closed']));
        self::assertSame([$oldClosed], $repository->findUidsForCleanup($tenDaysAgo, ['closed']));
        self::assertSame([$oldClosed, $oldNew, $nowClosed], $repository->findUidsForCleanup(null, ['new', 'closed']));
    }

    /**
     * Scenario: Own initial status
     * Scenario: Removed status
     */
    #[Test]
    public function addStoresTheConfiguredInitialStatusAndOpenMeansNotClosed(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'open=Open,waiting,done,rejected';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['closedStatuses'] = 'done,rejected';
        $repository = $this->get(FeedbackRepository::class);
        $open = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Open'), null);
        $rejected = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Rejected'), null);
        $repository->updateStatus($rejected, 'rejected');
        $removed = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Removed status'), null);
        $repository->updateStatus($removed, 'in_progress');

        self::assertSame('open', $repository->findByUid($open)['status'] ?? null);
        self::assertEqualsCanonicalizing([$open, $removed], array_column($repository->findOpenByPage(12, null, 10), 'uid'), 'a status that is not configured counts as open');
        self::assertSame(2, $repository->countOpen());
        self::assertSame(0, $repository->countOpen(99));

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['closedStatuses'] = '';
        self::assertSame(3, $repository->countOpen(), 'no closed status');
    }

    /**
     * Scenario: List module
     * Requirement: Conversation stored with the feedback (root level, hidden from the List module, read-only)
     */
    #[Test]
    public function tablesAreReadOnlyRootLevelAndHiddenFromTheListModule(): void
    {
        foreach ([FeedbackRepository::TABLE, ConversationRepository::MESSAGE_TABLE, ConversationRepository::READ_TABLE] as $table) {
            $ctrl = $GLOBALS['TCA'][$table]['ctrl'];
            self::assertSame(1, $ctrl['rootLevel'], $table);
            self::assertTrue($ctrl['hideTable'], $table . ' is listed in the List module');
            self::assertTrue($ctrl['readOnly'], $table);
        }
    }

    /**
     * Scenario: Update from 1.0.x
     */
    #[Test]
    public function feedbackStoredWithoutStatusHasTheStatusNew(): void
    {
        // A record as 1.0.x wrote it: the status column did not exist yet
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(FeedbackRepository::TABLE);
        $connection->insert(FeedbackRepository::TABLE, ['pid' => 0, 'crdate' => 1791561428, 'source' => 'backend', 'category' => 'bug', 'severity' => 'low', 'message' => 'Old feedback']);

        self::assertSame('new', $this->get(FeedbackRepository::class)->findByUid((int)$connection->lastInsertId())['status'] ?? null);
    }

    /**
     * Scenario: New feedback
     */
    #[Test]
    public function pageUidIsStoredFromTheContext(): void
    {
        $repository = $this->get(FeedbackRepository::class);

        self::assertSame(12, (int)($repository->findByUid($repository->add($this->createSubmission(), null))['page_uid'] ?? -1));
        self::assertSame(0, (int)($repository->findByUid($repository->add($this->createPageSubmission(null, Source::Frontend, 'No page'), null))['page_uid'] ?? -1));
    }

    /**
     * Scenario: Closed feedback
     */
    #[Test]
    public function openFeedbackOfAPageIsFilteredByStatusAndSourceNewestFirst(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $oldFrontend = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Old frontend', '2026-11-01'), null);
        $newFrontend = $repository->add($this->createPageSubmission(12, Source::Frontend, 'New frontend', '2026-11-03'), null);
        $backend = $repository->add($this->createPageSubmission(12, Source::Backend, 'Backend', '2026-11-02'), null);
        $closed = $repository->add($this->createPageSubmission(12, Source::Frontend, 'Closed', '2026-11-04'), null);
        $repository->updateStatus($closed, 'closed');
        $repository->updateStatus($oldFrontend, 'in_progress');
        $repository->add($this->createPageSubmission(13, Source::Frontend, 'Other page'), null);
        $repository->add($this->createPageSubmission(null, Source::Frontend, 'No page'), null);

        self::assertSame([$newFrontend, $backend, $oldFrontend], array_column($repository->findOpenByPage(12, null, 10), 'uid'));
        self::assertSame([$newFrontend, $oldFrontend], array_column($repository->findOpenByPage(12, Source::Frontend, 10), 'uid'));
        self::assertSame([$newFrontend], array_column($repository->findOpenByPage(12, Source::Frontend, 1), 'uid'), 'limit');
        self::assertSame(2, $repository->countOpenByPage(12, Source::Frontend));
        self::assertSame(1, $repository->countOpenByPage(12, Source::Backend));
        self::assertSame(0, $repository->countOpenByPage(0, null), 'feedback without page belongs to no page');
        self::assertSame(['uid' => $newFrontend, 'crdate' => new \DateTimeImmutable('2026-11-03')->getTimestamp(), 'source' => 'frontend', 'message' => 'New frontend', 'status' => 'new'], $repository->findOpenByPage(12, null, 1)[0]);
    }

    private function createPageSubmission(?int $pageUid, Source $source, string $message, string $date = 'now'): Submission
    {
        return new Submission($message, 'bug', Severity::Low, $source, 3, new \DateTimeImmutable($date), context: $pageUid === null ? [] : ['pageUid' => $pageUid]);
    }

    private function createSubmission(): Submission
    {
        return new Submission(
            message: 'Ümlauts and <b>markup</b> stay as they are.',
            category: 'bug',
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
