<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

final readonly class Video
{
    public function __construct(
        public string $content,
        public VideoType $type,
    ) {}
}
