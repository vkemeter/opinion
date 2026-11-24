<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Configuration;

use Vkemeter\Opinion\Collection\CollectableField;

final readonly class OpinionConfiguration
{
    public const array DEFAULT_CATEGORIES = ['bug' => '', 'content' => '', 'idea' => '', 'other' => ''];

    /**
     * @param list<string> $recipients
     * @param list<CollectableField> $disabledFields details that are not collected
     * @param list<string> $deniedUrlParameters query parameters removed from collected URLs
     * @param int $redeliverMaxAttempts delivery attempts after which opinion:redeliver gives up, 0 = no limit
     * @param int $rateLimit submissions per backend user and hour, 0 = no limit
     * @param list<string> $disabledDeliveryChannels identifiers of delivery channels that do not run
     * @param bool $conversationEnabled administrators and the sender exchange messages in the module
     * @param array<array-key, string> $categories offered categories in their order: value => configured label ('' = none); numeric values are int keys, see categoryValues()
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
        public array $disabledDeliveryChannels = [],
        public bool $conversationEnabled = true,
        public array $categories = self::DEFAULT_CATEGORIES,
    ) {}

    /**
     * The configured category values as strings, in their order.
     *
     * @return list<string>
     */
    public function categoryValues(): array
    {
        return array_map(strval(...), array_keys($this->categories));
    }

    public function collects(CollectableField $field): bool
    {
        return !in_array($field, $this->disabledFields, true);
    }
}
