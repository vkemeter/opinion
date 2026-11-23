<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class ConversationRepositoryTest extends FunctionalTestCase
{
    private const int ADMIN = 1;
    private const int SECOND_ADMIN = 4;
    private const int SENDER = 3;

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function messagesAreReturnedOldestFirstWithAuthor(): void
    {
        $feedback = $this->addFeedback();
        $repository = $this->get(ConversationRepository::class);
        $now = new \DateTimeImmutable('2026-11-01 10:00');
        // Same second: the order still follows the order of writing
        $repository->add($feedback, self::ADMIN, 'Question', $now);
        $repository->add($feedback, self::SENDER, 'Answer', $now);

        $messages = $repository->findByFeedback($feedback);

        self::assertSame(['Question', 'Answer'], array_column($messages, 'message'));
        self::assertSame('Ada Admin', $messages[0]['author_name']);
        self::assertSame('reporter', $messages[1]['author_username']);
        self::assertSame(2, $repository->countByFeedback($feedback));
    }

    #[Test]
    public function readStateIsKeptPerUserAndOwnMessagesAreNeverUnread(): void
    {
        $feedback = $this->addFeedback();
        $repository = $this->get(ConversationRepository::class);
        $repository->add($feedback, self::SENDER, 'Any news?', new \DateTimeImmutable());

        self::assertSame([], $repository->findUnreadFeedbackUids(self::SENDER, self::SENDER), 'own message');
        self::assertSame([$feedback], $repository->findUnreadFeedbackUids(self::ADMIN, null), 'missing read row means unread');
        self::assertSame([$feedback], $repository->findUnreadFeedbackUids(self::SECOND_ADMIN, null));

        $repository->markRead($feedback, self::ADMIN);
        $repository->markRead($feedback, self::ADMIN);

        self::assertSame([], $repository->findUnreadFeedbackUids(self::ADMIN, null), 'read by the first admin');
        self::assertSame([$feedback], $repository->findUnreadFeedbackUids(self::SECOND_ADMIN, null), 'still unread for the second admin');

        $repository->add($feedback, self::SENDER, 'Hello?', new \DateTimeImmutable());
        self::assertSame([$feedback], $repository->findUnreadFeedbackUids(self::ADMIN, null), 'newer message unread again');
    }

    #[Test]
    public function unreadFeedbackOfEditorsIsLimitedToTheirOwn(): void
    {
        $own = $this->addFeedback(self::SENDER);
        $foreign = $this->addFeedback(self::ADMIN);
        $repository = $this->get(ConversationRepository::class);
        $repository->add($own, self::ADMIN, 'Answer for the sender', new \DateTimeImmutable());
        $repository->add($foreign, self::SECOND_ADMIN, 'Between admins', new \DateTimeImmutable());

        self::assertSame([$own], $repository->findUnreadFeedbackUids(self::SENDER, self::SENDER));
    }

    #[Test]
    public function addSetsTheStatusOnlyWhenGiven(): void
    {
        $feedback = $this->addFeedback();
        $feedbackRepository = $this->get(FeedbackRepository::class);
        $feedbackRepository->updateStatus($feedback, FeedbackStatus::Closed);
        $repository = $this->get(ConversationRepository::class);

        $repository->add($feedback, self::ADMIN, 'Done', new \DateTimeImmutable());
        self::assertSame('closed', $this->statusOf($feedback));

        $repository->add($feedback, self::SENDER, 'Not fixed', new \DateTimeImmutable(), FeedbackStatus::InProgress);
        self::assertSame('in_progress', $this->statusOf($feedback));
    }

    #[Test]
    public function removeByFeedbackDeletesMessagesAndReadState(): void
    {
        $feedback = $this->addFeedback();
        $other = $this->addFeedback();
        $repository = $this->get(ConversationRepository::class);
        $repository->add($feedback, self::ADMIN, 'One', new \DateTimeImmutable());
        $repository->add($feedback, self::SENDER, 'Two', new \DateTimeImmutable());
        $repository->add($other, self::ADMIN, 'Other', new \DateTimeImmutable());
        $repository->markRead($feedback, self::ADMIN);

        self::assertSame(2, $repository->removeByFeedback($feedback));
        self::assertSame([], $repository->findByFeedback($feedback));
        self::assertSame(1, $repository->countByFeedback($other));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(ConversationRepository::READ_TABLE)->count('*', ConversationRepository::READ_TABLE, []));
    }

    private function statusOf(int $uid): ?string
    {
        $status = $this->get(FeedbackRepository::class)->findByUid($uid)['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function addFeedback(int $senderUid = self::SENDER): int
    {
        return $this->get(FeedbackRepository::class)->add(
            new Submission('Teaser is cropped', Category::Bug, Severity::Low, Source::Backend, $senderUid, new \DateTimeImmutable()),
            null,
        );
    }
}
