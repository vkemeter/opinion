<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

final readonly class Screenshot
{
    public function __construct(
        public string $content,
        public ScreenshotType $type,
    ) {}
}
