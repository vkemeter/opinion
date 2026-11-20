<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Repository;

use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;

/**
 * Search and filter of the module list; null = not filtered.
 */
final readonly class FeedbackFilter
{
    private const int MAX_SEARCH_LENGTH = 100;

    public function __construct(
        public ?string $search = null,
        public ?FeedbackStatus $status = null,
        public ?Category $category = null,
        public ?Severity $severity = null,
        public ?Source $source = null,
        public ?DeliveryStatus $deliveryStatus = null,
    ) {}

    /**
     * Invalid values are ignored.
     *
     * @param array<array-key, mixed> $parameters
     */
    public static function fromQueryParameters(array $parameters): self
    {
        $value = static fn(string $name): string => is_string($parameters[$name] ?? null) ? $parameters[$name] : '';
        $search = mb_substr(trim($value('search')), 0, self::MAX_SEARCH_LENGTH);

        return new self(
            $search !== '' ? $search : null,
            FeedbackStatus::tryFrom($value('status')),
            Category::tryFrom($value('category')),
            Severity::tryFrom($value('severity')),
            Source::tryFrom($value('source')),
            DeliveryStatus::tryFrom($value('deliveryStatus')),
        );
    }

    /**
     * The active filter as query parameters, for pagination and back links.
     *
     * @return array<string, string>
     */
    public function toQueryParameters(): array
    {
        return array_filter([
            'search' => $this->search ?? '',
            'status' => $this->status->value ?? '',
            'category' => $this->category->value ?? '',
            'severity' => $this->severity->value ?? '',
            'source' => $this->source->value ?? '',
            'deliveryStatus' => $this->deliveryStatus->value ?? '',
        ], static fn(string $value): bool => $value !== '');
    }

    public function isActive(): bool
    {
        return $this->toQueryParameters() !== [];
    }
}
