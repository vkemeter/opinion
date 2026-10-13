<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Configuration;

use Vkemeter\Opinion\Collection\CollectableField;

final readonly class OpinionConfiguration
{
    /**
     * @param list<string> $recipients
     * @param list<CollectableField> $disabledFields details that are not collected
     */
    public function __construct(
        public bool $enabled,
        public array $recipients,
        public string $subjectPrefix,
        public int $retentionDays,
        public array $disabledFields = [],
    ) {}

    public function collects(CollectableField $field): bool
    {
        return !in_array($field, $this->disabledFields, true);
    }
}
