<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Validation;

use Vkemeter\Opinion\Domain\Screenshot;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Video;

final readonly class ValidationResult
{
    /**
     * @param array<string, string> $errors error code per field
     * @param array<string, mixed> $clientInfo
     * @param array<string, mixed> $context
     * @param array<string, mixed> $contextAtEnd context at the end of a recording, unresolved
     * @param list<array{number: int, comment: string}> $pins comments of the pins on the screenshot
     */
    public function __construct(
        public array $errors,
        public string $message = '',
        public string $category = '',
        public ?Severity $severity = null,
        public ?Source $source = null,
        public array $clientInfo = [],
        public array $context = [],
        public ?Screenshot $screenshot = null,
        public ?Video $video = null,
        public array $contextAtEnd = [],
        public array $pins = [],
    ) {}

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
