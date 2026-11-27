<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;
use Vkemeter\Opinion\Storage\ScreenshotStorageResolver;

final class CleanupCommandTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'opinion' => ['enabled' => '1', 'recipients' => '', 'subjectPrefix' => '', 'retentionDays' => '30'],
        ],
    ];

    /** @var array{old: int, recent: int} */
    private array $feedbackUids;

    /** @var array{old: string, recent: string} */
    private array $screenshotPaths;

    protected function setUp(): void
    {
        parent::setUp();
        $this->get(ScreenshotStorageInstaller::class)->install();
        foreach (['old' => '-40 days', 'recent' => '-10 days'] as $key => $age) {
            $createdAt = new \DateTimeImmutable($age);
            $file = $this->get(ScreenshotStorage::class)->store(base64_decode(self::PNG_BASE64, true), 'png', $createdAt);
            $this->feedbackUids[$key] = $this->get(FeedbackRepository::class)->add($this->createSubmission($createdAt), $file);
            $this->screenshotPaths[$key] = Environment::getVarPath() . '/opinion' . $file->getIdentifier();
        }
        // Three messages on the old feedback, one on the recent one
        $conversation = $this->get(ConversationRepository::class);
        foreach (['old', 'old', 'old', 'recent'] as $key) {
            $conversation->add($this->feedbackUids[$key], 1, 'Message', new \DateTimeImmutable());
        }
        $conversation->markRead($this->feedbackUids['old'], 1);
    }

    /**
     * Scenario: Old feedback
     * Scenario: Cleanup of an old feedback
     */
    #[Test]
    public function deletesFeedbackOlderThanTheConfiguredRetentionIncludingScreenshots(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 1 feedback record(s), 1 file(s) and 3 message(s)', $tester->getDisplay());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertFileDoesNotExist($this->screenshotPaths['old']);
        self::assertSame(0, $this->countReferences($this->feedbackUids['old']));
        self::assertSame(0, $this->get(ConversationRepository::class)->countByFeedback($this->feedbackUids['old']));
        self::assertSame(0, $this->get(ConnectionPool::class)->getConnectionForTable(ConversationRepository::READ_TABLE)->count('*', ConversationRepository::READ_TABLE, []));
        self::assertSame(1, $this->get(ConversationRepository::class)->countByFeedback($this->feedbackUids['recent']));
        self::assertSame(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('*', 'sys_file', ['storage' => $this->get(ScreenshotStorageResolver::class)->findStorage()?->getUid()]));

        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
        self::assertFileExists($this->screenshotPaths['recent']);
        self::assertSame(1, $this->countReferences($this->feedbackUids['recent']));
    }

    /**
     * Scenario: Missing file
     */
    #[Test]
    public function recordIsRemovedWhenItsScreenshotFileIsAlreadyGone(): void
    {
        unlink($this->screenshotPaths['old']);

        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertSame(0, $this->countReferences($this->feedbackUids['old']));
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
    }

    /**
     * Scenario: Cleanup
     */
    #[Test]
    public function deletesVideosLikeScreenshots(): void
    {
        $createdAt = new \DateTimeImmutable('-40 days');
        $video = $this->get(ScreenshotStorage::class)->store("\x00\x00\x00\x18ftypisom", 'mp4', $createdAt);
        $uid = $this->get(FeedbackRepository::class)->add($this->createSubmission($createdAt), null, $video);
        $path = Environment::getVarPath() . '/opinion' . $video->getIdentifier();

        $tester = $this->runCommand([]);

        self::assertStringContainsString('Deleted 2 feedback record(s), 2 file(s) and 3 message(s)', $tester->getDisplay());
        self::assertFileDoesNotExist($path);
        self::assertSame(0, $this->countReferences($uid));
    }

    /**
     * Scenario: Old feedback
     */
    #[Test]
    public function daysOptionOverridesTheConfiguration(): void
    {
        $tester = $this->runCommand(['--days' => '5']);

        self::assertStringContainsString('Deleted 2 feedback record(s), 2 file(s) and 4 message(s)', $tester->getDisplay());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
        self::assertFileDoesNotExist($this->screenshotPaths['recent']);
    }

    /**
     * Scenario: Dry run
     */
    #[Test]
    public function dryRunDeletesNothing(): void
    {
        $tester = $this->runCommand(['--days' => '5', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would delete 2 feedback record(s), 2 file(s) and 4 message(s)', $tester->getDisplay());
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertFileExists($this->screenshotPaths['old']);
        self::assertSame(3, $this->get(ConversationRepository::class)->countByFeedback($this->feedbackUids['old']));
    }

    /**
     * Scenario: All feedback
     */
    #[Test]
    public function daysZeroDeletesFeedbackOfAnyAge(): void
    {
        $now = $this->get(FeedbackRepository::class)->add($this->createSubmission(new \DateTimeImmutable()), null);

        $tester = $this->runCommand(['--days' => '0']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 3 feedback record(s), 2 file(s) and 4 message(s) regardless of age.', $this->display($tester));
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($now), 'created in the current second');
        self::assertFileDoesNotExist($this->screenshotPaths['recent']);
    }

    /**
     * Scenario: Closed feedback of any age
     */
    #[Test]
    public function statusRestrictsTheDeletionAtAnyAge(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->updateStatus($this->feedbackUids['recent'], 'closed');
        $inProgress = $repository->add($this->createSubmission(new \DateTimeImmutable('-20 days')), null);
        $repository->updateStatus($inProgress, 'in_progress');

        $tester = $this->runCommand(['--days' => '0', '--status' => 'closed']);

        self::assertStringContainsString('Deleted 1 feedback record(s), 1 file(s) and 1 message(s) regardless of age with status closed.', $this->display($tester));
        self::assertNull($repository->findByUid($this->feedbackUids['recent']));
        self::assertNotNull($repository->findByUid($this->feedbackUids['old']), 'new');
        self::assertNotNull($repository->findByUid($inProgress), 'in progress');
    }

    /**
     * Scenario: Status keeps the retention period
     */
    #[Test]
    public function statusKeepsTheRetentionPeriod(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->updateStatus($this->feedbackUids['old'], 'closed');
        $repository->updateStatus($this->feedbackUids['recent'], 'closed');

        $tester = $this->runCommand(['--status' => 'closed']);

        self::assertStringContainsString('Deleted 1 feedback record(s)', $tester->getDisplay());
        self::assertNull($repository->findByUid($this->feedbackUids['old']));
        self::assertNotNull($repository->findByUid($this->feedbackUids['recent']), 'younger than retentionDays');
    }

    /**
     * Scenario: Several statuses
     */
    #[Test]
    public function severalStatusesCanBeGiven(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->updateStatus($this->feedbackUids['recent'], 'closed');
        $inProgress = $repository->add($this->createSubmission(new \DateTimeImmutable('-20 days')), null);
        $repository->updateStatus($inProgress, 'in_progress');

        $tester = $this->runCommand(['--days' => '0', '--status' => 'new, closed', '--dry-run' => true]);

        self::assertStringContainsString('Would delete 2 feedback record(s), 2 file(s) and 4 message(s) regardless of age with status new, closed.', $this->display($tester));

        $tester = $this->runCommand(['--days' => '0', '--status' => 'new,closed']);
        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']), 'new');
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']), 'closed');
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($inProgress), 'in progress stays');
    }

    /**
     * Scenario: Invalid options
     */
    #[Test]
    public function invalidOptionsAreRejected(): void
    {
        foreach (['-1', 'many'] as $days) {
            $tester = $this->runCommand(['--days' => $days]);
            self::assertSame(Command::INVALID, $tester->getStatusCode(), $days);
            self::assertStringContainsString('--days', $tester->getDisplay(), $days);
        }
        $tester = $this->runCommand(['--days' => '0', '--status' => 'closed,done']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('done', $tester->getDisplay());
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
    }

    /**
     * Scenario: Invalid options
     */
    #[Test]
    public function statusFollowsTheConfiguredStatuses(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,rejected,closed';
        $this->get(FeedbackRepository::class)->updateStatus($this->feedbackUids['recent'], 'rejected');

        self::assertSame(Command::SUCCESS, $this->runCommand(['--days' => '0', '--status' => 'rejected'])->getStatusCode());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
        self::assertSame(Command::INVALID, $this->runCommand(['--days' => '0', '--status' => 'in_progress'])->getStatusCode(), 'not configured');
    }

    /**
     * Requirement: Cleanup summary, errors and scheduling / Redelivery command (selectable as Scheduler task)
     * Scenario: Scheduler task
     */
    #[Test]
    public function commandIsSchedulable(): void
    {
        self::assertArrayHasKey('opinion:cleanup', iterator_to_array($this->get(CommandRegistry::class)->getSchedulableCommands()));
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('opinion:cleanup'));
        $tester->execute($input);

        return $tester;
    }

    /**
     * The output with the line breaks of the console style collapsed.
     */
    private function display(CommandTester $tester): string
    {
        return (string)preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    private function countReferences(int $feedbackUid): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->count('*', 'sys_file_reference', ['tablenames' => FeedbackRepository::TABLE, 'uid_foreign' => $feedbackUid]);
    }

    private function createSubmission(\DateTimeImmutable $createdAt): Submission
    {
        return new Submission('The teaser image is cropped.', 'bug', Severity::Normal, Source::Frontend, 1, $createdAt);
    }
}
