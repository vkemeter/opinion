<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Upgrades;

use TYPO3\CMS\Core\Attribute\UpgradeWizard;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Upgrades\DatabaseUpdatedPrerequisite;
use TYPO3\CMS\Core\Upgrades\UpgradeWizardInterface;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Fills the column page_uid of feedback stored before it existed, from the collected context.
 * The context is decoded in PHP, so the wizard does not depend on JSON functions of the database.
 */
#[UpgradeWizard('opinion_pageUid')]
final readonly class PageUidUpgradeWizard implements UpgradeWizardInterface
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function getTitle(): string
    {
        return 'Opinion: page of existing feedback';
    }

    public function getDescription(): string
    {
        return 'Stores the page of feedback sent before version 2.0 in its own field, so it is listed in the admin panel and the page module.';
    }

    public function updateNecessary(): bool
    {
        // Runs the generator up to the first page to store only
        return $this->findPagesToStore()->valid();
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(FeedbackRepository::TABLE);
        foreach ($this->findPagesToStore() as $uid => $pageUid) {
            $connection->update(FeedbackRepository::TABLE, ['page_uid' => $pageUid], ['uid' => $uid]);
        }

        return true;
    }

    /**
     * @return list<class-string>
     */
    public function getPrerequisites(): array
    {
        return [DatabaseUpdatedPrerequisite::class];
    }

    /**
     * Uid and page of each feedback without page uid whose context names a valid page.
     * The same rule decides whether the wizard is necessary, so it is done once nothing is left to write.
     *
     * @return \Generator<int, int>
     */
    private function findPagesToStore(): \Generator
    {
        $lastUid = 0;
        do {
            $queryBuilder = $this->createCandidateQuery();
            $rows = $queryBuilder
                ->select('uid', 'context')
                ->andWhere($queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($lastUid, Connection::PARAM_INT)))
                ->orderBy('uid')
                ->setMaxResults(self::BATCH_SIZE)
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $lastUid = (int)$row['uid'];
                $context = json_decode((string)$row['context'], true);
                $pageUid = FeedbackRepository::pageUidOf(is_array($context) ? $context : []);
                if ($pageUid > 0) {
                    yield $lastUid => $pageUid;
                }
            }
        } while (count($rows) === self::BATCH_SIZE);
    }

    /**
     * Feedback without page uid whose context names a page.
     */
    private function createCandidateQuery(): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(FeedbackRepository::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->from(FeedbackRepository::TABLE)
            ->where(
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->like('context', $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards('"pageUid"') . '%')),
            );
    }
}
