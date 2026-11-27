<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Fixtures;

use Psr\Log\AbstractLogger;

/**
 * Logger for tests: keeps every record, so a test can assert what was logged.
 */
final class CollectingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string)$level, 'message' => (string)$message, 'context' => $context];
    }

    /**
     * Messages of one level with their placeholders filled in.
     *
     * @return list<string>
     */
    public function messages(string $level): array
    {
        $messages = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level) {
                $replacements = [];
                foreach ($record['context'] as $key => $value) {
                    $replacements['{' . $key . '}'] = is_scalar($value) ? (string)$value : (string)json_encode($value);
                }
                $messages[] = strtr($record['message'], $replacements);
            }
        }

        return $messages;
    }
}
