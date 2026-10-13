<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Factory;

final class InvalidSubmissionException extends \RuntimeException
{
    /**
     * @param array<string, string> $errors error code per field
     */
    public function __construct(
        public readonly array $errors,
        int $code,
    ) {
        parent::__construct('The submission is invalid: ' . implode(', ', array_keys($errors)), $code);
    }
}
