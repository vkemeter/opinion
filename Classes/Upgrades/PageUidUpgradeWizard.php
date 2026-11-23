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
        return (int)$this->createCandidateQuery()->count('uid')->executeQuery()->fetchOne() > 0;
    }

    public function executeUpdate(): bool
    {
        $connection = $this->connectionPool->getConnectionForTable(FeedbackRepository::TABLE);
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
                    $connection->update(FeedbackRepository::TABLE, ['page_uid' => $pageUid], ['uid' => $lastUid]);
                }
            }
        } while (count($rows) === self::BATCH_SIZE);

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
