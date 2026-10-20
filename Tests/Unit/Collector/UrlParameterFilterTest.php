<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Collector;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Collector\UrlParameterFilter;

final class UrlParameterFilterTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, list<string>, string}>
     */
    public static function urlProvider(): iterable
    {
        yield 'no query' => ['https://example.com/page#top', ['ADMCMD_*'], 'https://example.com/page#top'];
        yield 'token is always removed' => ['/typo3/module/web/layout?token=abc&id=12', [], '/typo3/module/web/layout?id=12'];
        yield 'exact name, case-insensitive' => ['https://example.com/?L=1&LoginType=login', ['logintype'], 'https://example.com/?L=1'];
        yield 'prefix with *' => ['https://example.com/?ADMCMD_prev=abc&id=3&ADMCMD_simTime=1', ['ADMCMD_*'], 'https://example.com/?id=3'];
        yield 'array parameter by its base name' => ['https://example.com/?tx_felogin_login%5Bhash%5D=abc&tx_felogin_login[action]=x&id=3', ['tx_felogin_login'], 'https://example.com/?id=3'];
        yield 'prefix does not match other names' => ['https://example.com/?username=x', ['user'], 'https://example.com/?username=x'];
        yield 'everything removed drops the ?' => ['https://example.com/page?token=abc#c1', [], 'https://example.com/page#c1'];
        yield 'remaining pairs are kept as sent' => ['https://example.com/?q=a%20b&token=x&tx_news[news]=5', [], 'https://example.com/?q=a%20b&tx_news[news]=5'];
    }

    /**
     * @param list<string> $deniedParameters
     */
    #[Test]
    #[DataProvider('urlProvider')]
    public function filterRemovesDeniedParameters(string $url, array $deniedParameters, string $expected): void
    {
        self::assertSame($expected, new UrlParameterFilter()->filter($url, $deniedParameters));
    }
}
