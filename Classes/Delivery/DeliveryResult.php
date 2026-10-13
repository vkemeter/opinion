<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Delivery;

final readonly class DeliveryResult
{
    public function __construct(
        public string $channel,
        public bool $success,
        public string $message = '',
    ) {}

    public static function success(string $channel, string $message = ''): self
    {
        return new self($channel, true, $message);
    }

    public static function failure(string $channel, string $message): self
    {
        return new self($channel, false, $message);
    }
}
