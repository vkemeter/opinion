<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

final readonly class Submission
{
    /**
     * @param array<string, mixed> $clientInfo whitelisted data collected by the browser
     * @param array<string, mixed> $systemInfo data collected on the server
     * @param array<string, mixed> $context data contributed by the entry point, validated on the server
     */
    public function __construct(
        public string $message,
        public Category $category,
        public Severity $severity,
        public Source $source,
        public int $backendUserUid,
        public \DateTimeImmutable $createdAt,
        public array $clientInfo = [],
        public array $systemInfo = [],
        public array $context = [],
        public ?Screenshot $screenshot = null,
    ) {}
}
