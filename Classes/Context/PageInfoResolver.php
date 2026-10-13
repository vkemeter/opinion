<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Context;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

final readonly class PageInfoResolver
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @return array{pageUid: int, pageTitle: string}|null
     */
    public function resolve(mixed $pageUid): ?array
    {
        $uid = filter_var($pageUid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($uid === false) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $title = $queryBuilder
            ->select('title')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return $title === false ? null : ['pageUid' => $uid, 'pageTitle' => (string)$title];
    }
}
