<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Repository\FeedbackFilter;

final class FeedbackFilterTest extends UnitTestCase
{
    #[Test]
    public function validParametersAreTakenAndInvalidOnesIgnored(): void
    {
        $filter = FeedbackFilter::fromQueryParameters(['search' => '  teaser ', 'status' => 'closed', 'category' => 'feature', 'severity' => ['high'], 'id' => '5']);

        self::assertSame('teaser', $filter->search);
        self::assertSame(FeedbackStatus::Closed, $filter->status);
        self::assertNull($filter->category);
        self::assertNull($filter->severity);
        self::assertSame(['search' => 'teaser', 'status' => 'closed'], $filter->toQueryParameters());
    }

    #[Test]
    public function searchIsCutAndEmptyFilterIsInactive(): void
    {
        self::assertSame(100, mb_strlen((string)FeedbackFilter::fromQueryParameters(['search' => str_repeat('ü', 150)])->search));
        self::assertFalse(FeedbackFilter::fromQueryParameters(['search' => '   '])->isActive());
        self::assertTrue(new FeedbackFilter(category: Category::Bug)->isActive());
    }
}
