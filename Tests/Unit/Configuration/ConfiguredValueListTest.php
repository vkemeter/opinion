<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Configuration\ConfiguredValueList;

final class ConfiguredValueListTest extends UnitTestCase
{
    private const array DEFAULT = ['bug' => '', 'other' => ''];

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function settingsProvider(): array
    {
        return [
            'values and labels in order, trimmed' => ['bug, question=Question , design=Design', ['bug' => '', 'question' => 'Question', 'design' => 'Design']],
            'invalid and duplicate entries ignored, first wins' => ['bug,Bug Report,bug=Defect,<b>x</b>,idea', ['bug' => '', 'idea' => '']],
            'allowed characters and length' => ['a-1_b,' . str_repeat('x', 30) . ',' . str_repeat('y', 31) . ',-start,_start', ['a-1_b' => '', str_repeat('x', 30) => '']],
            'label may contain equals signs' => ['formula=a=b', ['formula' => 'a=b']],
            'nothing valid left' => [' , ,=Label', self::DEFAULT],
            'empty' => ['', self::DEFAULT],
        ];
    }

    /**
     * @param array<string, string> $expected
     */
    #[Test]
    #[DataProvider('settingsProvider')]
    public function parseAppliesTheRules(string $setting, array $expected): void
    {
        self::assertSame($expected, ConfiguredValueList::parse($setting, self::DEFAULT));
    }
}
