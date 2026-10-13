<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Collector;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Collector\ClientInfoSanitizer;
use Vkemeter\Opinion\Collector\SchemaSanitizer;

final class ClientInfoSanitizerTest extends UnitTestCase
{
    #[Test]
    public function whitelistedValuesAreKept(): void
    {
        $clientInfo = [
            'url' => 'https://example.com/page?x=1',
            'title' => 'Page',
            'userAgent' => 'Mozilla/5.0',
            'userAgentData' => ['brands' => [['brand' => 'Chromium', 'version' => '140']], 'platform' => 'macOS', 'mobile' => false],
            'language' => 'de-AT',
            'languages' => ['de-AT', 'en'],
            'timezone' => 'Europe/Vienna',
            'screen' => ['width' => 2560, 'height' => 1440, 'colorDepth' => 30],
            'viewport' => ['width' => 1280, 'height' => 720],
            'devicePixelRatio' => 2,
            'colorScheme' => 'dark',
            'reducedMotion' => false,
            'online' => true,
            'timestamp' => '2026-10-09T12:00:00.000Z',
        ];

        $sanitized = new ClientInfoSanitizer(new SchemaSanitizer())->sanitize($clientInfo);

        self::assertSame([...$clientInfo, 'devicePixelRatio' => 2.0], $sanitized);
    }

    #[Test]
    public function unknownKeysAndPrivateDataAreDropped(): void
    {
        $sanitized = new ClientInfoSanitizer(new SchemaSanitizer())->sanitize([
            'language' => 'de',
            'cookie' => 'be_typo_user=abc',
            'cookies' => ['be_typo_user' => 'abc'],
            'localStorage' => ['token' => 'secret'],
            'sessionStorage' => ['token' => 'secret'],
            'screen' => ['width' => 100, 'session' => 'secret'],
            'userAgentData' => ['brands' => [['brand' => 'X', 'token' => 'secret']]],
        ]);

        self::assertSame(
            ['userAgentData' => ['brands' => [['brand' => 'X']]], 'language' => 'de', 'screen' => ['width' => 100]],
            $sanitized,
        );
        self::assertStringNotContainsString('secret', (string)json_encode($sanitized));
        self::assertStringNotContainsString('be_typo_user', (string)json_encode($sanitized));
    }

    #[Test]
    public function valuesOfWrongTypeAreDropped(): void
    {
        $sanitized = new ClientInfoSanitizer(new SchemaSanitizer())->sanitize([
            'title' => ['nested'],
            'screen' => ['width' => '100', 'height' => 1.5],
            'online' => 'yes',
            'colorScheme' => 'sepia',
            'languages' => 'de',
            'viewport' => 'big',
        ]);

        self::assertSame([], $sanitized);
    }

    #[Test]
    public function stringsListsAndNumbersAreCapped(): void
    {
        $sanitized = new ClientInfoSanitizer(new SchemaSanitizer())->sanitize([
            'title' => str_repeat('a', 400),
            'languages' => array_fill(0, 20, 'de'),
            'screen' => ['width' => 999999999, 'height' => -5],
            'devicePixelRatio' => 1000,
        ]);

        self::assertSame(300, mb_strlen($sanitized['title']));
        self::assertCount(10, $sanitized['languages']);
        self::assertSame(['width' => 100000000, 'height' => 0], $sanitized['screen']);
        self::assertSame(100.0, $sanitized['devicePixelRatio']);
    }

    #[Test]
    public function onlyHttpUrlsAreAccepted(): void
    {
        $sanitizer = new ClientInfoSanitizer(new SchemaSanitizer());

        self::assertSame([], $sanitizer->sanitize(['url' => 'javascript:alert(1)']));
        self::assertSame([], $sanitizer->sanitize(['url' => 'file:///etc/passwd']));
        self::assertSame([], $sanitizer->sanitize(['url' => 'https://example.com/' . str_repeat('a', 2048)]));
        self::assertSame(['url' => 'http://example.com/'], $sanitizer->sanitize(['url' => 'http://example.com/']));
    }
}
