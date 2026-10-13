<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Formatting;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Formatting\DataFlattener;

final class DataFlattenerTest extends UnitTestCase
{
    #[Test]
    public function flattenCreatesDottedLabelsAndStringValues(): void
    {
        self::assertSame(
            ['screen.width' => '2560', 'online' => 'yes', 'cached' => 'no', 'images.files.0.name' => 'a.jpg', 'ratio' => '1.5', 'empty' => ''],
            new DataFlattener()->flatten([
                'screen' => ['width' => 2560],
                'online' => true,
                'cached' => false,
                'images' => ['files' => [['name' => 'a.jpg']]],
                'ratio' => 1.5,
                'empty' => null,
            ]),
        );
    }
}
