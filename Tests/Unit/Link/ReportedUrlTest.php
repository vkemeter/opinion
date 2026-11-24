<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Link;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Link\ReportedUrl;

final class ReportedUrlTest extends UnitTestCase
{
    private const array HOSTS = ['www.example.com', 'example.com'];

    #[Test]
    public function browserUrlIsPreferredAndLinked(): void
    {
        self::assertSame(
            ['url' => 'https://www.example.com/typo3/module/web/layout?id=1', 'linked' => true],
            ReportedUrl::fromDetails(['url' => 'https://www.example.com/typo3/module/web/layout?id=1'], ['url' => '/typo3/module/web/layout?id=1'], self::HOSTS, false),
        );
    }

    #[Test]
    public function modulePathIsUsedWithoutBrowserUrlAndLinkedOnlyWhereAllowed(): void
    {
        self::assertSame(['url' => '/typo3/module/web/layout?id=1', 'linked' => true], ReportedUrl::fromDetails([], ['url' => '/typo3/module/web/layout?id=1'], self::HOSTS, true));
        self::assertSame(['url' => '/typo3/module/web/layout?id=1', 'linked' => false], ReportedUrl::fromDetails([], ['url' => '/typo3/module/web/layout?id=1'], self::HOSTS, false));
    }

    #[Test]
    public function unsafeUrlsAreNotLinked(): void
    {
        self::assertSame(['url' => 'javascript:alert(1)', 'linked' => false], ReportedUrl::fromDetails(['url' => 'javascript:alert(1)'], [], self::HOSTS, true));
        self::assertFalse(ReportedUrl::fromDetails([], ['url' => '//evil.example.com/x'], self::HOSTS, true)['linked'] ?? null);
    }

    #[Test]
    public function onlyOwnHostsAreLinked(): void
    {
        self::assertTrue(ReportedUrl::fromDetails(['url' => 'https://example.com/news'], [], self::HOSTS, false)['linked'] ?? null);
        self::assertTrue(ReportedUrl::fromDetails(['url' => 'https://WWW.Example.COM/news'], [], self::HOSTS, false)['linked'] ?? null);
        self::assertSame(['url' => 'https://evil.example/login', 'linked' => false], ReportedUrl::fromDetails(['url' => 'https://evil.example/login'], [], self::HOSTS, true));
        self::assertFalse(ReportedUrl::fromDetails(['url' => 'https://sub.example.com/'], [], self::HOSTS, true)['linked'] ?? null);
    }

    #[Test]
    public function urlsWithBackslashAreNotLinked(): void
    {
        self::assertFalse(ReportedUrl::fromDetails([], ['url' => '/\\evil.example/typo3'], self::HOSTS, true)['linked'] ?? null);
        self::assertFalse(ReportedUrl::fromDetails(['url' => 'https://evil.example\\@example.com/'], [], self::HOSTS, true)['linked'] ?? null);
    }

    #[Test]
    public function withoutUrlNothingIsReturned(): void
    {
        self::assertNull(ReportedUrl::fromDetails(['url' => ''], [], self::HOSTS, true));
    }
}
