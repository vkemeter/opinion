<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Cleanup;

final readonly class CleanupResult
{
    /**
     * @param list<string> $errors
     */
    public function __construct(
        public int $records,
        public int $screenshots,
        public array $errors,
    ) {}
}
