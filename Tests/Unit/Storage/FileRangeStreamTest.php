<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Storage;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Storage\FileRangeStream;

final class FileRangeStreamTest extends UnitTestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = (string)tempnam(sys_get_temp_dir(), 'opinion');
        file_put_contents($this->path, '0123456789');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    /**
     * Scenario: Seeking in the player
     */
    #[Test]
    public function emitSendsOnlyTheRange(): void
    {
        $this->expectOutputString('3456');

        new FileRangeStream($this->path, 3, 4)->emit();
    }

    /**
     * Scenario: Seeking in the player
     */
    #[Test]
    public function rangeCanBeReadAgain(): void
    {
        $stream = new FileRangeStream($this->path, 7, 3);

        self::assertSame('789', (string)$stream);
        self::assertSame('789', (string)$stream, 'not consumed by the first read');
        self::assertSame(3, $stream->getSize());
    }
}
