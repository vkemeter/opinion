<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Factory;

use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;

/**
 * Rebuilds the submission of a stored feedback record, so it can be delivered again.
 * Screenshot and video are not part of it, they are FAL files already.
 */
final readonly class StoredSubmissionFactory
{
    /**
     * @param array<string, mixed> $record row of tx_opinion_feedback
     * @throws \ValueError when severity or source are no known values; the category is taken as stored
     */
    public function createFromRecord(array $record): Submission
    {
        return new Submission(
            message: (string)($record['message'] ?? ''),
            category: (string)($record['category'] ?? ''),
            severity: Severity::from((string)($record['severity'] ?? '')),
            source: Source::from((string)($record['source'] ?? '')),
            backendUserUid: (int)($record['be_user'] ?? 0),
            createdAt: new \DateTimeImmutable('@' . (int)($record['crdate'] ?? 0))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            clientInfo: self::decode($record['client_info'] ?? null),
            systemInfo: self::decode($record['system_info'] ?? null),
            context: self::decode($record['context'] ?? null),
            contextAtEnd: self::decode($record['context_at_end'] ?? null),
            backendUrl: (string)($record['backend_url'] ?? ''),
        );
    }

    /**
     * A JSON column of tx_opinion_feedback as array; invalid or empty values give [].
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
