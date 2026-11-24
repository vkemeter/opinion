<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Link;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Link\LinkableHosts;

final class LinkableHostsTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function hostsOfAllSitesLanguagesAndTheRequestAreCollected(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $language = static fn(int $id, string $base): array => ['languageId' => $id, 'title' => 'L' . $id, 'locale' => 'en_US.UTF-8', 'base' => $base];
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('main', ['rootPageId' => 1, 'base' => 'https://WWW.Example.com/', 'languages' => [$language(0, '/'), $language(1, 'https://example.de/')]]);
        $siteWriter->write('relative', ['rootPageId' => 12, 'base' => '/', 'languages' => [$language(0, '/')]]);

        $hosts = $this->get(LinkableHosts::class)->forRequest(new ServerRequest('https://backend.example.org/typo3/'));

        self::assertEqualsCanonicalizing(['backend.example.org', 'www.example.com', 'example.de'], $hosts);
        self::assertEqualsCanonicalizing(['www.example.com', 'example.de'], $this->get(LinkableHosts::class)->forRequest(null));
    }
}
