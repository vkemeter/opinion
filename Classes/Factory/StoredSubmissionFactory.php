<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Factory;

use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;

/**
 * Rebuilds the submission of a stored feedback record, so it can be delivered again.
 * The screenshot is not part of it, it is already a FAL file.
 */
final readonly class StoredSubmissionFactory
{
    /**
     * @param array<string, mixed> $record row of tx_opinion_feedback
     * @throws \ValueError when category, severity or source are no known values
     */
    public function createFromRecord(array $record): Submission
    {
        return new Submission(
            message: (string)($record['message'] ?? ''),
            category: Category::from((string)($record['category'] ?? '')),
            severity: Severity::from((string)($record['severity'] ?? '')),
            source: Source::from((string)($record['source'] ?? '')),
            backendUserUid: (int)($record['be_user'] ?? 0),
            createdAt: new \DateTimeImmutable('@' . (int)($record['crdate'] ?? 0))->setTimezone(new \DateTimeZone(date_default_timezone_get())),
            clientInfo: $this->decode($record['client_info'] ?? null),
            systemInfo: $this->decode($record['system_info'] ?? null),
            context: $this->decode($record['context'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
