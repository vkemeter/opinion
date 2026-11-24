<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Formatting;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Formatting\ConfiguredLabels;

final class ConfiguredLabelsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'LANG' => [
            'resourceOverrides' => [
                'de' => [
                    'opinion.messages' => ['EXT:opinion/Tests/Functional/Fixtures/Language/de.opinion-override.xlf'],
                ],
            ],
        ],
    ];

    #[Test]
    public function labelPrefersTranslationThenConfiguredLabelThenValue(): void
    {
        $configuration = new OpinionConfiguration(true, [], '', 180, categories: ['bug' => 'Defect', 'question' => 'Question?', 'design' => '']);
        $subject = $this->get(ConfiguredLabels::class);
        $german = $this->get(LanguageServiceFactory::class)->create('de');
        $english = $this->get(LanguageServiceFactory::class)->create('default');

        self::assertSame('Fehler', $subject->label('category', 'bug', $configuration, $german), 'shipped translation wins over the configured label');
        self::assertSame('Bug', $subject->label('category', 'bug', $configuration, $english));
        self::assertSame('Question?', $subject->label('category', 'question', $configuration, $english), 'configured label');
        self::assertSame('Frage', $subject->label('category', 'question', $configuration, $german), 'translation override');
        self::assertSame('design', $subject->label('category', 'design', $configuration, $english), 'value without label');
        self::assertSame('Idee', $subject->label('category', 'idea', $configuration, $german), 'removed shipped value keeps its translation');
        self::assertSame('feature', $subject->label('category', 'feature', $configuration, $english), 'removed own value');
    }

    #[Test]
    public function statusLabelsFollowTheSameRule(): void
    {
        $configuration = new OpinionConfiguration(true, [], '', 180, statuses: ['new' => '', 'waiting' => 'Waiting for reply']);
        $subject = $this->get(ConfiguredLabels::class);
        $german = $this->get(LanguageServiceFactory::class)->create('de');

        self::assertSame('Abgeschlossen', $subject->label('status', 'closed', $configuration, $german), 'shipped, also when removed');
        self::assertSame('Waiting for reply', $subject->label('status', 'waiting', $configuration, $german));
        self::assertSame('gone', $subject->label('status', 'gone', $configuration, $german));
    }

    #[Test]
    public function unknownListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1795551289);

        $this->get(ConfiguredLabels::class)->label('colour', 'red', new OpinionConfiguration(true, [], '', 180), $this->get(LanguageServiceFactory::class)->create('default'));
    }
}
