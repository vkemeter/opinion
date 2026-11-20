<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Link;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Link\ReportedUrl;

final class ReportedUrlTest extends UnitTestCase
{
    #[Test]
    public function browserUrlIsPreferredAndLinked(): void
    {
        self::assertSame(
            ['url' => 'https://www.example.com/typo3/module/web/layout?id=1', 'linked' => true],
            ReportedUrl::fromDetails(['url' => 'https://www.example.com/typo3/module/web/layout?id=1'], ['url' => '/typo3/module/web/layout?id=1'], false),
        );
    }

    #[Test]
    public function modulePathIsUsedWithoutBrowserUrlAndLinkedOnlyWhereAllowed(): void
    {
        self::assertSame(['url' => '/typo3/module/web/layout?id=1', 'linked' => true], ReportedUrl::fromDetails([], ['url' => '/typo3/module/web/layout?id=1'], true));
        self::assertSame(['url' => '/typo3/module/web/layout?id=1', 'linked' => false], ReportedUrl::fromDetails([], ['url' => '/typo3/module/web/layout?id=1'], false));
    }

    #[Test]
    public function unsafeUrlsAreNotLinked(): void
    {
        self::assertSame(['url' => 'javascript:alert(1)', 'linked' => false], ReportedUrl::fromDetails(['url' => 'javascript:alert(1)'], [], true));
        self::assertFalse(ReportedUrl::fromDetails([], ['url' => '//evil.example.com/x'], true)['linked'] ?? null);
    }

    #[Test]
    public function withoutUrlNothingIsReturned(): void
    {
        self::assertNull(ReportedUrl::fromDetails(['url' => ''], [], true));
    }
}
