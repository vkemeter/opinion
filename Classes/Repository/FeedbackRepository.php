<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Repository;

use Doctrine\DBAL\Types\Types;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use Vkemeter\Opinion\Domain\DeliveryStatus;
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
    ) {}

    public function add(Submission $submission, ?File $screenshot): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $timestamp = $submission->createdAt->getTimestamp();

        return $connection->transactional(function (Connection $connection) use ($submission, $screenshot, $timestamp): int {
            $connection->insert(self::TABLE, [
                'pid' => 0,
                'crdate' => $timestamp,
                'tstamp' => $timestamp,
                'source' => $submission->source->value,
                'category' => $submission->category->value,
                'severity' => $submission->severity->value,
                'be_user' => $submission->backendUserUid,
                'message' => $submission->message,
                'screenshot' => $screenshot instanceof File ? 1 : 0,
                'client_info' => $submission->clientInfo,
                'system_info' => $submission->systemInfo,
                'context' => $submission->context,
                'delivery_status' => DeliveryStatus::Pending->value,
                'delivery_log' => '',
                'delivery_attempts' => 0,
            ], [
                'client_info' => Types::JSON,
                'system_info' => Types::JSON,
                'context' => Types::JSON,
            ]);
            $uid = (int)$connection->lastInsertId();

            if ($screenshot instanceof File) {
                $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
                    'pid' => 0,
                    'crdate' => $timestamp,
                    'tstamp' => $timestamp,
                    'uid_local' => $screenshot->getUid(),
                    'uid_foreign' => $uid,
                    'tablenames' => self::TABLE,
                    'fieldname' => 'screenshot',
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
            ->orderBy('uid');
        if ($maxAttempts > 0) {
            $queryBuilder->andWhere($queryBuilder->expr()->lt('delivery_attempts', $queryBuilder->createNamedParameter($maxAttempts, Connection::PARAM_INT)));
        }

        return array_map(intval(...), $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $record = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['*'], self::TABLE, ['uid' => $uid])
            ->fetchAssociative();

        return $record === false ? null : $record;
    }

    /**
     * @return list<int>
     */
    public function findUidsCreatedBefore(int $timestamp): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(intval(...), $queryBuilder
            ->select('uid')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->lt('crdate', $queryBuilder->createNamedParameter($timestamp, Connection::PARAM_INT)))
            ->orderBy('uid')
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @return list<int>
     */
    public function findScreenshotFileUids(int $feedbackUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_file_reference');
        $queryBuilder->getRestrictions()->removeAll();

        return array_map(intval(...), $queryBuilder
            ->select('uid_local')
            ->from('sys_file_reference')
            ->where(
                $queryBuilder->expr()->eq('tablenames', $queryBuilder->createNamedParameter(self::TABLE)),
                $queryBuilder->expr()->eq('fieldname', $queryBuilder->createNamedParameter('screenshot')),
                $queryBuilder->expr()->eq('uid_foreign', $queryBuilder->createNamedParameter($feedbackUid, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    public function remove(int $uid): void
    {
        $this->connectionPool->getConnectionForTable('sys_file_reference')->delete(
            'sys_file_reference',
            ['tablenames' => self::TABLE, 'uid_foreign' => $uid],
        );
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['uid' => $uid]);
    }

    public function countAll(): int
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE)->count('*', self::TABLE, []);
    }

    /**
     * Newest first, with the sender's name from be_users.
     *
     * @return list<array<string, mixed>>
     */
    public function findPage(int $limit, int $offset): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('feedback.*', 'sender.username AS sender_username', 'sender.realName AS sender_name')
            ->from(self::TABLE, 'feedback')
            ->leftJoin('feedback', 'be_users', 'sender', $queryBuilder->expr()->eq('sender.uid', $queryBuilder->quoteIdentifier('feedback.be_user')))
            ->orderBy('feedback.crdate', 'DESC')
            ->addOrderBy('feedback.uid', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->executeQuery()
            ->fetchAllAssociative();
    }
}
