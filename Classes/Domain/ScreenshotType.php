<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

enum ScreenshotType: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';

    public static function fromContent(string $content): ?self
    {
        return match (true) {
            str_starts_with($content, "\x89PNG\r\n\x1a\n") => self::Png,
            str_starts_with($content, "\xFF\xD8\xFF") => self::Jpeg,
            default => null,
        };
    }

    public function getFileExtension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Jpeg => 'jpg',
        };
    }
}
