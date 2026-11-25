<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Domain;

enum VideoType: string
{
    case Mp4 = 'video/mp4';
    case Webm = 'video/webm';

    public static function fromContent(string $content): ?self
    {
        return match (true) {
            substr($content, 4, 4) === 'ftyp' => self::Mp4,
            // EBML magic; the doc type tells WebM from other Matroska files
            str_starts_with($content, "\x1A\x45\xDF\xA3") && str_contains(substr($content, 0, 64), "\x42\x82\x84webm") => self::Webm,
            default => null,
        };
    }

    public function getFileExtension(): string
    {
        return match ($this) {
            self::Mp4 => 'mp4',
            self::Webm => 'webm',
        };
    }
}
