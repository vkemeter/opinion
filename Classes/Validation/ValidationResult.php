<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Validation;

use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Screenshot;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;

final readonly class ValidationResult
{
    /**
     * @param array<string, string> $errors error code per field
     * @param array<string, mixed> $clientInfo
     * @param array<string, mixed> $context
     */
    public function __construct(
        public array $errors,
        public string $message = '',
        public ?Category $category = null,
        public ?Severity $severity = null,
        public ?Source $source = null,
        public array $clientInfo = [],
        public array $context = [],
        public ?Screenshot $screenshot = null,
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
