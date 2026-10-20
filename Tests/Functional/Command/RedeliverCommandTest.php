<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class RedeliverCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'opinion' => ['enabled' => '1', 'recipients' => 'team@example.com', 'subjectPrefix' => '[Opinion]', 'redeliverMaxAttempts' => '3'],
        ],
        'MAIL' => ['transport' => 'null'],
    ];

    /** @var array<string, int> */
    private array $feedbackUids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->feedbackUids = [
            'failed' => $this->addFeedback('-1 hour', DeliveryStatus::Failed, 1),
            'pending' => $this->addFeedback('-1 hour', null, 0),
            'sent' => $this->addFeedback('-1 hour', DeliveryStatus::Sent, 1),
            'recent' => $this->addFeedback('-2 minutes', DeliveryStatus::Failed, 1),
            'givenUp' => $this->addFeedback('-1 hour', DeliveryStatus::Failed, 3),
        ];
    }

    #[Test]
    public function deliversFailedAndPendingFeedbackAgain(): void
    {
        $tester = $this->runCommand([]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Delivered 2 feedback record(s).', $tester->getDisplay());

        $record = $this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['failed']);
        self::assertSame('sent', $record['delivery_status'] ?? null);
        self::assertSame(2, (int)($record['delivery_attempts'] ?? 0));
        self::assertMatchesRegularExpression('/^email: FAILED Connection refused\n\[\d{4}-\d\d-\d\d \d\d:\d\d\] email: OK sent to team@example.com$/', (string)($record['delivery_log'] ?? ''));
        self::assertSame('sent', $this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['pending'])['delivery_status'] ?? null);

        // Too young, already sent, or out of attempts
        foreach (['recent', 'givenUp'] as $key) {
            self::assertSame('failed', $this->get(FeedbackRepository::class)->findByUid($this->feedbackUids[$key])['delivery_status'] ?? null, $key);
        }
        self::assertSame(1, (int)($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['sent'])['delivery_attempts'] ?? 0));
    }

    #[Test]
    public function minAgeOptionIncludesYoungerFeedback(): void
    {
        self::assertStringContainsString('Delivered 3 feedback record(s).', $this->runCommand(['--min-age' => '0'])->getDisplay());
    }

    #[Test]
    public function dryRunDeliversNothing(): void
    {
        $tester = $this->runCommand(['--dry-run' => true]);

        self::assertStringContainsString(sprintf('Would deliver 2 feedback record(s) again: %d, %d.', $this->feedbackUids['failed'], $this->feedbackUids['pending']), $tester->getDisplay());
        self::assertSame('failed', $this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['failed'])['delivery_status'] ?? null);
    }

    #[Test]
    public function failingDeliveryIsReported(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['recipients'] = '';

        $tester = $this->runCommand([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Delivered 0 of 2 feedback record(s).', $tester->getDisplay());
        self::assertSame(2, (int)($this->get(FeedbackRepository::class)->findByUid($this->feedbackUids['failed'])['delivery_attempts'] ?? 0));
    }

    #[Test]
    public function invalidMinAgeIsRejected(): void
    {
        self::assertSame(Command::INVALID, $this->runCommand(['--min-age' => 'soon'])->getStatusCode());
    }

    #[Test]
    public function commandIsSchedulable(): void
    {
        self::assertArrayHasKey('opinion:redeliver', iterator_to_array($this->get(CommandRegistry::class)->getSchedulableCommands()));
    }

    /**
     * @param array<string, string|bool> $input
     */
    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester($this->get(CommandRegistry::class)->get('opinion:redeliver'));
        $tester->execute($input);

        return $tester;
    }

    private function addFeedback(string $age, ?DeliveryStatus $status, int $attempts): int
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission(
            message: 'The teaser image is cropped.',
            category: Category::Bug,
            severity: Severity::Normal,
            source: Source::Frontend,
            backendUserUid: 1,
            createdAt: new \DateTimeImmutable($age),
            systemInfo: ['backendUser' => ['username' => 'admin', 'email' => 'admin@example.com']],
        ), null);
        for ($attempt = 0; $attempt < $attempts && $status !== null; $attempt++) {
            $repository->updateDeliveryStatus($uid, $status, $status === DeliveryStatus::Failed ? 'email: FAILED Connection refused' : 'email: OK');
        }

        return $uid;
    }
}
