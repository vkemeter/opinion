<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteSetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function settingProvider(): iterable
    {
        yield 'switched off' => [['opinion' => ['adminPanel' => ['enable' => false]]], ''];
        yield 'default' => [[], '1'];
    }

    /**
     * Scenario: Setting off
     *
     * @param array<string, mixed> $settings
     */
    #[Test]
    #[DataProvider('settingProvider')]
    public function siteSetSwitchesTheAdminPanelBySiteSetting(array $settings, string $expected): void
    {
        // TypoScript and pages are cached in files, which outlive the database of the previous data set
        $this->get(CacheManager::class)->flushCaches();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $connectionPool = $this->get(ConnectionPool::class);
        $connectionPool->getConnectionForTable('pages')->update('pages', ['slug' => '/', 'is_siteroot' => 1], ['uid' => 1]);
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('main', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'dependencies' => ['vkemeter/opinion'],
            'languages' => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/']],
        ]);
        $siteWriter->writeSettings('main', $settings);
        // Renders the value config.admPanel gets from the set
        $connectionPool->getConnectionForTable('sys_template')->insert('sys_template', [
            'pid' => 1,
            'root' => 1,
            'clear' => 0,
            'config' => "page = PAGE\npage.10 = TEXT\npage.10.value < config.admPanel\npage.10.wrap = admPanel=|",
        ]);

        $html = (string)$this->executeFrontendSubRequest(new InternalRequest('https://example.com/'))->getBody();

        self::assertSame(1, preg_match('#admPanel=([^\s<]*)#', $html, $matches), strip_tags($html));
        // An empty value switches the admin panel off like 0
        self::assertSame($expected, $matches[1]);
    }
}
