<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Configuration;

use Vkemeter\Opinion\Collection\CollectableField;

final readonly class OpinionConfiguration
{
    /**
     * @param list<string> $recipients
     * @param list<CollectableField> $disabledFields details that are not collected
     * @param list<string> $deniedUrlParameters query parameters removed from collected URLs
     * @param int $redeliverMaxAttempts delivery attempts after which opinion:redeliver gives up, 0 = no limit
     * @param int $rateLimit submissions per backend user and hour, 0 = no limit
     */
    public function __construct(
        public bool $enabled,
        public array $recipients,
        public string $subjectPrefix,
        public int $retentionDays,
        public array $disabledFields = [],
        public array $deniedUrlParameters = [],
        public int $redeliverMaxAttempts = 0,
        public int $rateLimit = 0,
    ) {}

    public function collects(CollectableField $field): bool
    {
        return !in_array($field, $this->disabledFields, true);
    }
}
