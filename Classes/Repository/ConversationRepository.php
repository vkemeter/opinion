<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Messages of the conversation of a feedback and the read state per backend user.
 * The read state is the uid of the newest message the user has seen, so messages
 * written in the same second are told apart.
 */
final readonly class ConversationRepository
{
    public const string MESSAGE_TABLE = 'tx_opinion_message';
    public const string READ_TABLE = 'tx_opinion_read';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * Stores a message; a given status is set on the feedback in the same transaction.
     */
    public function add(int $feedbackUid, int $authorUid, string $message, \DateTimeImmutable $createdAt, ?string $status = null): int
    {
        return $this->connectionPool->getConnectionForTable(self::MESSAGE_TABLE)->transactional(
            function (Connection $connection) use ($feedbackUid, $authorUid, $message, $createdAt, $status): int {
                $connection->insert(self::MESSAGE_TABLE, [
                    'pid' => 0,
                    'crdate' => $createdAt->getTimestamp(),
                    'feedback' => $feedbackUid,
                    'be_user' => $authorUid,
                    'message' => $message,
                ]);
                $uid = (int)$connection->lastInsertId();
                if ($status !== null) {
                    $connection->update(
                        FeedbackRepository::TABLE,
                        ['status' => $status, 'tstamp' => $createdAt->getTimestamp()],
                        ['uid' => $feedbackUid],
                    );
                }

                return $uid;
            },
        );
    }

    /**
     * Oldest first, with the author's name; authors deleted since have empty names.
     *
     * @return list<array<string, mixed>>
     */
    public function findByFeedback(int $feedbackUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder
            ->select('message.uid', 'message.crdate', 'message.be_user', 'message.message', 'author.realName AS author_name', 'author.username AS author_username')
            ->from(self::MESSAGE_TABLE, 'message')
            ->leftJoin('message', 'be_users', 'author', (string)$queryBuilder->expr()->and(
                $queryBuilder->expr()->eq('author.uid', $queryBuilder->quoteIdentifier('message.be_user')),
                $queryBuilder->expr()->eq('author.deleted', 0),
            ))
            ->where($queryBuilder->expr()->eq('message.feedback', $queryBuilder->createNamedParameter($feedbackUid, Connection::PARAM_INT)))
            ->orderBy('message.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Marks all current messages of the feedback as read for the user.
     */
    public function markRead(int $feedbackUid, int $userUid): void
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $lastMessage = (int)$queryBuilder
            ->selectLiteral('MAX(uid)')
            ->from(self::MESSAGE_TABLE)
            ->where($queryBuilder->expr()->eq('feedback', $queryBuilder->createNamedParameter($feedbackUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();
        if ($lastMessage === 0) {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable(self::READ_TABLE);
        $key = ['feedback' => $feedbackUid, 'be_user' => $userUid];
        if ($connection->update(self::READ_TABLE, ['last_message' => $lastMessage], $key) > 0) {
            return;
        }
        try {
            $connection->insert(self::READ_TABLE, ['pid' => 0, ...$key, 'last_message' => $lastMessage]);
        } catch (UniqueConstraintViolationException) {
            // Another request of the same user inserted the row meanwhile
            $connection->update(self::READ_TABLE, ['last_message' => $lastMessage], $key);
        }
    }

    /**
     * Feedback with messages of others the user has not seen yet.
     *
     * @param int|null $senderUid only feedback of this sender (editors), null = all (administrators)
     * @return list<int>
     */
    public function findUnreadFeedbackUids(int $userUid, ?int $senderUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $expr = $queryBuilder->expr();
        $user = $queryBuilder->createNamedParameter($userUid, Connection::PARAM_INT);
        $queryBuilder
            ->select('message.feedback')
            ->distinct()
            ->from(self::MESSAGE_TABLE, 'message')
            ->join('message', FeedbackRepository::TABLE, 'feedback', $expr->eq('feedback.uid', $queryBuilder->quoteIdentifier('message.feedback')))
            ->leftJoin('message', self::READ_TABLE, 'readstate', (string)$expr->and(
                $expr->eq('readstate.feedback', $queryBuilder->quoteIdentifier('message.feedback')),
                $expr->eq('readstate.be_user', $user),
            ))
            ->where(
                $expr->neq('message.be_user', $user),
                $expr->or(
                    $expr->isNull('readstate.last_message'),
                    $expr->gt('message.uid', $queryBuilder->quoteIdentifier('readstate.last_message')),
                ),
            );
        if ($senderUid !== null) {
            $queryBuilder->andWhere($expr->eq('feedback.be_user', $queryBuilder->createNamedParameter($senderUid, Connection::PARAM_INT)));
        }

        return array_map(intval(...), $queryBuilder->executeQuery()->fetchFirstColumn());
    }

    /**
     * Number of messages per feedback, for the list.
     *
     * @param list<int> $feedbackUids
     * @return array<int, int> feedback uid => number of messages, feedback without messages left out
     */
    public function countByFeedbackUids(array $feedbackUids): array
    {
        if ($feedbackUids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::MESSAGE_TABLE);
        $queryBuilder->getRestrictions()->removeAll();
        $rows = $queryBuilder
            ->select('feedback')
            ->addSelectLiteral('COUNT(*) AS messages')
            ->from(self::MESSAGE_TABLE)
            ->where($queryBuilder->expr()->in('feedback', $queryBuilder->createNamedParameter($feedbackUids, Connection::PARAM_INT_ARRAY)))
            ->groupBy('feedback')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_column(array_map(static fn(array $row): array => [(int)$row['feedback'], (int)$row['messages']], $rows), 1, 0);
    }

    public function countByFeedback(int $feedbackUid): int
    {
        return $this->connectionPool->getConnectionForTable(self::MESSAGE_TABLE)
            ->count('*', self::MESSAGE_TABLE, ['feedback' => $feedbackUid]);
    }

    /**
     * Deletes messages and read state of the feedback.
     *
     * @return int number of deleted messages
     */
    public function removeByFeedback(int $feedbackUid): int
    {
        $this->connectionPool->getConnectionForTable(self::READ_TABLE)->delete(self::READ_TABLE, ['feedback' => $feedbackUid]);

        return $this->connectionPool->getConnectionForTable(self::MESSAGE_TABLE)->delete(self::MESSAGE_TABLE, ['feedback' => $feedbackUid]);
    }
}
