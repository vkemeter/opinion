<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Repository;

use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Resource\File;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;

/**
 * Writes feedback records directly: the table is read-only in FormEngine and
 * the sender is usually no admin, so DataHandler is not an option.
 */
final readonly class FeedbackRepository
{
    public const string TABLE = 'tx_opinion_feedback';

    public function __construct(
        private ConnectionPool $connectionPool,
        private ConfigurationProviderInterface $configurationProvider,
    ) {}

    public function add(Submission $submission, ?File $screenshot, ?File $video = null): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $timestamp = $submission->createdAt->getTimestamp();

        return $connection->transactional(function (Connection $connection) use ($submission, $screenshot, $video, $timestamp): int {
            $connection->insert(self::TABLE, [
                'pid' => 0,
                'crdate' => $timestamp,
                'tstamp' => $timestamp,
                'source' => $submission->source->value,
                'category' => $submission->category,
                'severity' => $submission->severity->value,
                'be_user' => $submission->backendUserUid,
                'message' => $submission->message,
                'screenshot' => $screenshot instanceof File ? 1 : 0,
                'video' => $video instanceof File ? 1 : 0,
                'client_info' => $submission->clientInfo,
                'system_info' => $submission->systemInfo,
                'context' => $submission->context,
                'context_at_end' => $submission->contextAtEnd,
                'pins' => $submission->pins,
                'backend_url' => $submission->backendUrl,
                'page_uid' => self::pageUidOf($submission->context),
                'status' => $this->configurationProvider->getConfiguration()->initialStatus(),
                'delivery_status' => DeliveryStatus::Pending->value,
                'delivery_log' => '',
                'delivery_attempts' => 0,
            ], [
                'client_info' => Types::JSON,
                'system_info' => Types::JSON,
                'context' => Types::JSON,
                'context_at_end' => Types::JSON,
                'pins' => Types::JSON,
            ]);
            $uid = (int)$connection->lastInsertId();

            foreach (array_filter(['screenshot' => $screenshot, 'video' => $video]) as $field => $file) {
                $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
                    'pid' => 0,
                    'crdate' => $timestamp,
                    'tstamp' => $timestamp,
                    'uid_local' => $file->getUid(),
                    'uid_foreign' => $uid,
                    'tablenames' => self::TABLE,
                    'fieldname' => $field,
                    'sorting_foreign' => 1,
                ]);
            }

            return $uid;
        });
    }

    /**
     * Stores the result of a delivery attempt and counts the attempt.
     */
    public function updateDeliveryStatus(int $uid, DeliveryStatus $status, string $log): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->update(self::TABLE)
            ->set('delivery_status', $status->value)
            ->set('delivery_log', $log)
            ->set('tstamp', time())
            ->set('delivery_attempts', $queryBuilder->quoteIdentifier('delivery_attempts') . ' + 1', false)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeStatement();
    }

    /**
     * The page uid of a collected context, 0 when no page was collected.
     *
     * @param array<string, mixed> $context
     */
    public static function pageUidOf(array $context): int
    {
        $pageUid = filter_var($context['pageUid'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $pageUid === false ? 0 : $pageUid;
    }

    /**
     * Open feedback (status not closed) of a page, newest first.
     *
     * @param Source|null $source only feedback of this source, null = both
     * @return list<array{uid: int, crdate: int, source: string, message: string, status: string}>
     */
    public function findOpenByPage(int $pageUid, ?Source $source, int $limit): array
    {
        $rows = $this->createOpenByPageQuery($pageUid, $source)
            ->select('uid', 'crdate', 'source', 'message', 'status')
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn(array $row): array => [
            'uid' => (int)$row['uid'],
            'crdate' => (int)$row['crdate'],
            'source' => (string)$row['source'],
            'message' => (string)$row['message'],
            'status' => (string)$row['status'],
        ], $rows);
    }

    /**
     * @param Source|null $source only feedback of this source, null = both
     */
    public function countOpenByPage(int $pageUid, ?Source $source): int
    {
        return (int)$this->createOpenByPageQuery($pageUid, $source)
            ->count('uid')
            ->executeQuery()
            ->fetchOne();
    }

    private function createOpenByPageQuery(int $pageUid, ?Source $source): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->from(self::TABLE)
            ->where(
                // A page uid of 0 means "no page" and never matches
                $queryBuilder->expr()->eq('page_uid', $queryBuilder->createNamedParameter($pageUid > 0 ? $pageUid : -1, Connection::PARAM_INT)),
            );
        $this->restrictToOpen($queryBuilder, 'status');
        if ($source !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('source', $queryBuilder->createNamedParameter($source->value)));
        }

        return $queryBuilder;
    }

    public function updateStatus(int $uid, string $status): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->update(
            self::TABLE,
            ['status' => $status, 'tstamp' => time()],
            ['uid' => $uid],
        );
    }

    /**
     * Feedback whose delivery failed or never finished, oldest first.
     *
     * @param int $maxAttempts only records with fewer attempts, 0 = all
     * @return list<int>
     */
    public function findUndeliveredUids(int $createdBefore, int $maxAttempts): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->in('delivery_status', $queryBuilder->createNamedParameter(
                    [DeliveryStatus::Pending->value, DeliveryStatus::Failed->value],
                    Connection::PARAM_STR_ARRAY,
                )),
                $queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($createdBefore, Connection::PARAM_INT)),
            )
            ->orderBy('crdate')
            ->addOrderBy('uid');
        if ($maxAttempts > 0) {
            $queryBuilder->andWhere($queryBuilder->expr()->lt('delivery_attempts', $queryBuilder->createNamedParameter($maxAttempts, Connection::PARAM_INT)));
        }

        return array_map(intval(...), $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * @param int|null $senderUid only feedback of this backend user, null = all
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid, ?int $senderUid = null): ?array
    {
        $record = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid, ...($senderUid !== null ? ['be_user' => $senderUid] : [])])
            ->fetchAssociative();

        return $record === false ? null : $record;
    }

    /**
     * Feedback to delete by opinion:cleanup, oldest first.
     *
     * @param int|null $createdBefore only feedback created before this timestamp, null = any age
     * @param list<string> $statuses only feedback with one of these processing statuses, [] = all
     * @return list<int>
     */
    public function findUidsForCleanup(?int $createdBefore, array $statuses = []): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder->select('uid')->from(self::TABLE)->orderBy('uid');
        if ($createdBefore !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($createdBefore, Connection::PARAM_INT)));
        }
        if ($statuses !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->in('status', $queryBuilder->createNamedParameter($statuses, Connection::PARAM_STR_ARRAY)));
        }

        return array_map(intval(...), $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * @return list<int>
     */
    public function findCaptureFileUids(int $feedbackUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(intval(...), $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::TABLE)),
                $queryBuilder->expr()->in('fieldname', $queryBuilder->createNamedParameter(['screenshot', 'video'], Connection::PARAM_STR_ARRAY)),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($feedbackUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * The list query without select, shared by countAll() and findPage() so total and page agree.
     */
    private function createListQuery(?int $senderUid, ?FeedbackFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $queryBuilder
            ->from(self::TABLE, 'feedback')
            ->leftJoin('feedback', 'be_users', 'sender', $queryBuilder->expr()->eq('sender.uid', $queryBuilder->quoteIdentifier('feedback.be_user')));
        if ($senderUid !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('feedback.be_user', $queryBuilder->createNamedParameter($senderUid, Connection::PARAM_INT)));
        }
        if ($filter === null) {
            return $queryBuilder;
        }

        foreach (['status' => $filter->status, 'category' => $filter->category, 'severity' => $filter->severity?->value, 'source' => $filter->source?->value, 'delivery_status' => $filter->deliveryStatus?->value] as $field => $value) {
            if ($value !== null) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('feedback.' . $field, $queryBuilder->createNamedParameter($value)));
            }
        }
        if ($filter->search !== null) {
            // JSON columns may hold the term escaped ("\/", "\u00fc"), so both spellings are searched there
            // Backslash is the LIKE escape character and part of the escaped spelling, so it is escaped as well
            $pattern = static fn(string $term): string => '%' . addcslashes($term, '\\%_') . '%';
            $plain = $queryBuilder->createNamedParameter($pattern($filter->search));
            $escaped = $queryBuilder->createNamedParameter($pattern(substr(json_encode($filter->search, JSON_THROW_ON_ERROR), 1, -1)));
            $queryBuilder->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->like('feedback.message', $plain),
                $queryBuilder->expr()->like('sender.realName', $plain),
                $queryBuilder->expr()->like('sender.username', $plain),
                $queryBuilder->expr()->like('feedback.context', $plain),
                $queryBuilder->expr()->like('feedback.context', $escaped),
                $queryBuilder->expr()->like('feedback.client_info', $plain),
                $queryBuilder->expr()->like('feedback.client_info', $escaped),
            ));
        }

        return $queryBuilder;
    }

    public function remove(int $uid): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')->delete(
            'sys_file_reference',
            ['tablenames' => self::TABLE, 'uid_foreign' => $uid],
        );
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);
    }

    /**
     * Open feedback (status not closed).
     *
     * @param int|null $senderUid only feedback of this backend user, null = all
     */
    public function countOpen(?int $senderUid = null): int
    {
        $queryBuilder = $this->createListQuery($senderUid, null);
        $this->restrictToOpen($queryBuilder, 'feedback.status');

        return (int)$queryBuilder->count('feedback.uid')->executeQuery()->fetchOne();
    }

    /**
     * @param int|null $senderUid only feedback of this backend user, null = all
     */
    public function countAll(?int $senderUid = null, ?FeedbackFilter $filter = null): int
    {
        return (int)$this->createListQuery($senderUid, $filter)
            ->count('feedback.uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Newest first, with the sender's name from be_users.
     *
     * @param int|null $senderUid only feedback of this backend user, null = all
     * @return list<array<string, mixed>>
     */
    public function findPage(int $limit, int $offset, ?int $senderUid = null, ?FeedbackFilter $filter = null): array
    {
        return $this->createListQuery($senderUid, $filter)
            ->select('feedback.*', 'sender.username AS sender_username', 'sender.realName AS sender_name')
            ->orderBy('feedback.crdate', 'DESC')
            ->addOrderBy('feedback.uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Statuses that are not configured as closed are open, also ones that are no longer configured.
     */
    private function restrictToOpen(QueryBuilder $queryBuilder, string $field): void
    {
        $closedStatuses = $this->configurationProvider->getConfiguration()->closedStatuses;
        if ($closedStatuses !== []) {
            $queryBuilder->andWhere($queryBuilder->expr()->notIn($field, $queryBuilder->createNamedParameter($closedStatuses, Connection::PARAM_STR_ARRAY)));
        }
    }
}
