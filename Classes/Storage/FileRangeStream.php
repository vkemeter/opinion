<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Storage;

use TYPO3\CMS\Core\Http\SelfEmittableStreamInterface;
use TYPO3\CMS\Core\Http\Stream;

/**
 * One byte range of a file for the answer to a range request: sent straight from the file,
 * because a video player asks for "the rest from here" on every seek.
 */
final class FileRangeStream extends Stream implements SelfEmittableStreamInterface
{
    public function __construct(
        string $path,
        private readonly int $start,
        private readonly int $length,
    ) {
        parent::__construct($path, 'rb');
    }

    public function emit(): void
    {
        $output = fopen('php://output', 'wb');
        if ($output === false) {
            return;
        }
        stream_copy_to_stream($this->openAtStart(), $output, $this->length);
        fclose($output);
    }

    public function __toString(): string
    {
        return (string)stream_get_contents($this->openAtStart(), $this->length);
    }

    public function getSize(): int
    {
        return $this->length;
    }

    /**
     * @return resource
     */
    private function openAtStart()
    {
        $this->seek($this->start);
        if (!is_resource($this->resource)) {
            throw new \RuntimeException('The file of the range is not open.', 1795640237);
        }

        return $this->resource;
    }
}
