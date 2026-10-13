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
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
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
            $file = $this->get(ScreenshotStorage::class)->store((string)base64_decode(self::PNG_BASE64, true), 'png', $createdAt);
            $this->feedbackUids[$key] = $this->get(FeedbackRepository::class)->add($this->createSubmission($createdAt), $file);
            $this->screenshotPaths[$key] = Environment::getVarPath() . '/opinion' . $file->getIdentifier();
        }
    }

    #[Test]
    public function deletesFeedbackOlderThanTheConfiguredRetentionIncludingScreenshots(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 1 feedback record(s) and 1 screenshot(s)', $tester->getDisplay());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertFileDoesNotExist($this->screenshotPaths['old']);
        self::assertSame(0, $this->countReferences($this->feedbackUids['old']));
        self::assertSame(1, $this->get(ConnectionPool::class)->getConnectionForTable('sys_file')->count('*', 'sys_file', ['storage' => $this->get(ScreenshotStorageResolver::class)->findStorage()?->getUid()]));

        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
        self::assertFileExists($this->screenshotPaths['recent']);
        self::assertSame(1, $this->countReferences($this->feedbackUids['recent']));
    }

    #[Test]
    public function daysOptionOverridesTheConfiguration(): void
    {
        $tester = $this->runCommand(['--days' => '5']);

        self::assertStringContainsString('Deleted 2 feedback record(s) and 2 screenshot(s)', $tester->getDisplay());
        self::assertNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['recent']));
        self::assertFileDoesNotExist($this->screenshotPaths['recent']);
    }

    #[Test]
    public function dryRunDeletesNothing(): void
    {
        $tester = $this->runCommand(['--days' => '5', '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Would delete 2 feedback record(s) and 2 screenshot(s)', $tester->getDisplay());
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
        self::assertFileExists($this->screenshotPaths['old']);
    }

    #[Test]
    public function invalidDaysAreRejected(): void
    {
        self::assertSame(Command::INVALID, $this->runCommand(['--days' => '0'])->getStatusCode());
        self::assertSame(Command::INVALID, $this->runCommand(['--days' => 'many'])->getStatusCode());
        self::assertNotNull($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['old']));
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

    private function countReferences(int $feedbackUid): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->count('*', 'sys_file_reference', ['tablenames' => FeedbackRepository::TABLE, 'uid_foreign' => $feedbackUid]);
    }

    private function createSubmission(\DateTimeImmutable $createdAt): Submission
    {
        return new Submission('The teaser image is cropped.', Category::Bug, Severity::Normal, Source::Frontend, 1, $createdAt);
    }
}
