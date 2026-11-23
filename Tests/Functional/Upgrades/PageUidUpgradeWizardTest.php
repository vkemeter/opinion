<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Upgrades;

use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Upgrades\PageUidUpgradeWizard;

final class PageUidUpgradeWizardTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function fillsThePageOfExistingFeedbackFromTheContext(): void
    {
        // Feedback as stored before the column existed
        $withPage = $this->insertOldFeedback(['pageUid' => 12, 'module' => 'web_layout']);
        $withoutPage = $this->insertOldFeedback(['module' => 'web_list']);
        $invalidPage = $this->insertOldFeedback(['pageUid' => 'twelve']);
        $wizard = $this->get(PageUidUpgradeWizard::class);

        self::assertTrue($wizard->updateNecessary());
        self::assertTrue($wizard->executeUpdate());

        self::assertSame(12, $this->pageUidOf($withPage));
        self::assertSame(0, $this->pageUidOf($withoutPage));
        self::assertSame(0, $this->pageUidOf($invalidPage));
        self::assertSame(1, $this->get(FeedbackRepository::class)->countOpenByPage(12, null));
    }

    #[Test]
    public function nothingToDoWithoutOldFeedback(): void
    {
        $this->insertOldFeedback(['module' => 'web_list']);

        self::assertFalse($this->get(PageUidUpgradeWizard::class)->updateNecessary());
    }

    /**
     * @param array<string, mixed> $context
     */
    private function insertOldFeedback(array $context): int
    {
        $connection = $this->get(ConnectionPool::class)->getConnectionForTable(FeedbackRepository::TABLE);
        $connection->insert(FeedbackRepository::TABLE, [
            'pid' => 0,
            'crdate' => time(),
            'source' => 'backend',
            'category' => 'bug',
            'severity' => 'low',
            'status' => 'new',
            'message' => 'Old feedback',
            'context' => $context,
        ], ['context' => Types::JSON]);

        return (int)$connection->lastInsertId();
    }

    private function pageUidOf(int $uid): int
    {
        return (int)($this->get(FeedbackRepository::class)->findByUid($uid)['page_uid'] ?? -1);
    }
}
