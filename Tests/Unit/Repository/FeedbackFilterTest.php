<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Configuration\ConfiguredValueList;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Repository\FeedbackFilter;

final class FeedbackFilterTest extends UnitTestCase
{
    #[Test]
    public function validParametersAreTakenAndInvalidOnesIgnored(): void
    {
        $filter = FeedbackFilter::fromQueryParameters(['search' => '  teaser ', 'status' => 'closed', 'category' => 'feature', 'severity' => ['high'], 'id' => '5'], self::configuration('bug,question'));

        self::assertSame('teaser', $filter->search);
        self::assertSame('closed', $filter->status);
        self::assertNull($filter->category);
        self::assertNull($filter->severity);
        self::assertSame(['search' => 'teaser', 'status' => 'closed'], $filter->toQueryParameters());
    }

    #[Test]
    public function categoryMustBeConfigured(): void
    {
        self::assertSame('question', FeedbackFilter::fromQueryParameters(['category' => 'question'], self::configuration('bug,question'))->category);
        self::assertNull(FeedbackFilter::fromQueryParameters(['category' => 'idea'], self::configuration('bug,question'))->category, 'no longer configured');
        self::assertSame('2', FeedbackFilter::fromQueryParameters(['category' => '2'], self::configuration('1,2'))->category, 'numeric value');
    }

    #[Test]
    public function searchIsCutAndEmptyFilterIsInactive(): void
    {
        self::assertSame(100, mb_strlen((string)FeedbackFilter::fromQueryParameters(['search' => str_repeat('ü', 150)], self::configuration())->search));
        self::assertFalse(FeedbackFilter::fromQueryParameters(['search' => '   '], self::configuration())->isActive());
        self::assertTrue(new FeedbackFilter(category: 'bug')->isActive());
    }

    #[Test]
    public function statusMustBeConfigured(): void
    {
        $configuration = self::configuration(statuses: 'new,waiting,closed');

        self::assertSame('waiting', FeedbackFilter::fromQueryParameters(['status' => 'waiting'], $configuration)->status);
        self::assertNull(FeedbackFilter::fromQueryParameters(['status' => 'in_progress'], $configuration)->status, 'no longer configured');
    }

    private static function configuration(string $categories = '', string $statuses = ''): OpinionConfiguration
    {
        return new OpinionConfiguration(
            true,
            [],
            '',
            180,
            categories: ConfiguredValueList::parse($categories, OpinionConfiguration::DEFAULT_CATEGORIES),
            statuses: ConfiguredValueList::parse($statuses, OpinionConfiguration::DEFAULT_STATUSES),
        );
    }
}
