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
use Vkemeter\Opinion\Domain\FeedbackStatus;
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

    #[Test]
    public function deletesFeedbackOlderThanTheConfiguredRetentionIncludingScreenshots(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 1 feedback record(s), 1 screenshot(s) and 3 message(s)', $tester->getDisplay());
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

    #[Test]
    public function daysOptionOverridesTheConfiguration(): void
    {
        $tester = $this->runCommand(['--days' => '5']);

        self::assertStringContainsString('Deleted 2 feedback record(s), 2 screenshot(s) and 4 message(s)', $tester->getDisplay());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
        self::assertFileDoesNotExist($this->screenshotPaths['recent']);
    }

    #[Test]
    public function dryRunDeletesNothing(): void
    {
        $tester = $this->runCommand(['--days' => '5', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would delete 2 feedback record(s), 2 screenshot(s) and 4 message(s)', $tester->getDisplay());
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertFileExists($this->screenshotPaths['old']);
        self::assertSame(3, $this->get(ConversationRepository::class)->countByFeedback($this->feedbackUids['old']));
    }

    #[Test]
    public function daysZeroDeletesFeedbackOfAnyAge(): void
    {
        $now = $this->get(FeedbackRepository::class)->add($this->createSubmission(new \DateTimeImmutable()), null);

        $tester = $this->runCommand(['--days' => '0']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 3 feedback record(s), 2 screenshot(s) and 4 message(s) regardless of age.', $this->display($tester));
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($now), 'created in the current second');
        self::assertFileDoesNotExist($this->screenshotPaths['recent']);
    }

    #[Test]
    public function statusRestrictsTheDeletionAtAnyAge(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->updateStatus($this->feedbackUids['recent'], FeedbackStatus::Closed);
        $inProgress = $repository->add($this->createSubmission(new \DateTimeImmutable('-20 days')), null);
        $repository->updateStatus($inProgress, FeedbackStatus::InProgress);

        $tester = $this->runCommand(['--days' => '0', '--status' => 'closed']);

        self::assertStringContainsString('Deleted 1 feedback record(s), 1 screenshot(s) and 1 message(s) regardless of age with status closed.', $this->display($tester));
        self::assertNull($repository->findByUid($this->feedbackUids['recent']));
        self::assertNotNull($repository->findByUid($this->feedbackUids['old']), 'new');
        self::assertNotNull($repository->findByUid($inProgress), 'in progress');
    }

    #[Test]
    public function statusKeepsTheRetentionPeriod(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->updateStatus($this->feedbackUids['old'], FeedbackStatus::Closed);
        $repository->updateStatus($this->feedbackUids['recent'], FeedbackStatus::Closed);

        $tester = $this->runCommand(['--status' => 'closed']);

        self::assertStringContainsString('Deleted 1 feedback record(s)', $tester->getDisplay());
        self::assertNull($repository->findByUid($this->feedbackUids['old']));
        self::assertNotNull($repository->findByUid($this->feedbackUids['recent']), 'younger than retentionDays');
    }

    #[Test]
    public function severalStatusesCanBeGiven(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->updateStatus($this->feedbackUids['recent'], FeedbackStatus::Closed);
        $inProgress = $repository->add($this->createSubmission(new \DateTimeImmutable('-20 days')), null);
        $repository->updateStatus($inProgress, FeedbackStatus::InProgress);

        $tester = $this->runCommand(['--days' => '0', '--status' => 'new, closed', '--dry-run' => true]);

        self::assertStringContainsString('Would delete 2 feedback record(s), 2 screenshot(s) and 4 message(s) regardless of age with status new, closed.', $this->display($tester));
    }

    #[Test]
    public function invalidOptionsAreRejected(): void
    {
        self::assertSame(Command::INVALID, $this->runCommand(['--days' => '-1'])->getStatusCode());
        self::assertSame(Command::INVALID, $this->runCommand(['--days' => 'many'])->getStatusCode());
        $tester = $this->runCommand(['--days' => '0', '--status' => 'closed,done']);
        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('done', $tester->getDisplay());
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
    }

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
