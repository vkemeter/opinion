<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Context;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Context\BackendContextProvider;
use Vkemeter\Opinion\Context\FrontendContextProvider;
use Vkemeter\Opinion\Domain\Source;

final class ContextProviderTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    /**
     * Requirement: Frontend context validation (anything else is dropped)
     */
    #[Test]
    public function frontendContextKeepsExistingPageAndDropsUnknownValues(): void
    {
        $provider = $this->get(FrontendContextProvider::class);

        self::assertTrue($provider->supports(Source::Frontend));
        self::assertFalse($provider->supports(Source::Backend));
        self::assertSame(
            ['pageUid' => 12, 'pageTitle' => 'Teaser page', 'workspaceId' => 0],
            $provider->resolve(['pageUid' => 12, 'siteIdentifier' => 'unknown', 'languageId' => 3, 'workspaceId' => 0, 'evil' => 'x'], new ServerRequest()),
        );
    }

    /**
     * Scenario: Submission from a page
     * Scenario: Language of another site
     */
    #[Test]
    public function frontendContextKeepsSiteAndLanguageOfThatSiteOnly(): void
    {
        $language = static fn(int $id, string $title): array => ['languageId' => $id, 'title' => $title, 'locale' => 'en_US.UTF-8', 'base' => '/l' . $id . '/'];
        $this->get(SiteWriter::class)->write('main', ['rootPageId' => 1, 'base' => 'https://example.com/', 'languages' => [$language(0, 'English'), $language(1, 'Deutsch')]]);
        $provider = $this->get(FrontendContextProvider::class);

        self::assertSame(
            ['pageUid' => 12, 'pageTitle' => 'Teaser page', 'siteIdentifier' => 'main', 'languageId' => 1, 'language' => 'Deutsch'],
            $provider->resolve(['pageUid' => 12, 'siteIdentifier' => 'main', 'languageId' => 1], new ServerRequest()),
        );
        self::assertSame(
            ['pageUid' => 12, 'pageTitle' => 'Teaser page', 'siteIdentifier' => 'main'],
            $provider->resolve(['pageUid' => 12, 'siteIdentifier' => 'main', 'languageId' => 7], new ServerRequest()),
            'language of another site dropped, site kept',
        );
    }

    /**
     * Requirement: Frontend context validation (fixed schema, at most 50 images)
     */
    #[Test]
    public function frontendContextKeepsSanitizedAdminPanelPageInfo(): void
    {
        $result = $this->get(FrontendContextProvider::class)->resolve([
            'pageInfo' => [
                'pageType' => 0,
                'cached' => false,
                'noCacheReasons' => ['EXT:adminpanel: "No caching" disables cache.'],
                'uncachedElements' => 2,
                'parseTimeMs' => 153,
                'documentSize' => '48.2 Ki',
                'frontendUser' => ['uid' => 0, 'username' => '', 'groups' => '0,-1', 'password' => 'secret'],
                'imagesOnPage' => [
                    'total' => 60,
                    'totalSize' => '3.1 Mi',
                    'files' => array_fill(0, 60, ['name' => '/fileadmin/a.jpg', 'size' => '52 Ki']),
                ],
                'cookie' => ['be_typo_user' => 'abc'],
            ],
        ], new ServerRequest());

        $pageInfo = $result['pageInfo'];
        self::assertSame(0, $pageInfo['pageType']);
        self::assertFalse($pageInfo['cached']);
        self::assertSame(['EXT:adminpanel: "No caching" disables cache.'], $pageInfo['noCacheReasons']);
        self::assertSame(153, $pageInfo['parseTimeMs']);
        self::assertSame(['uid' => 0, 'groups' => '0,-1'], $pageInfo['frontendUser']);
        self::assertSame(60, $pageInfo['imagesOnPage']['total']);
        self::assertCount(50, $pageInfo['imagesOnPage']['files']);
        self::assertArrayNotHasKey('cookie', $pageInfo);
        self::assertStringNotContainsString('secret', (string)json_encode($result));
    }

    /**
     * Scenario: Deleted page
     */
    #[Test]
    public function frontendContextDropsDeletedOrInvalidPages(): void
    {
        $provider = $this->get(FrontendContextProvider::class);

        self::assertSame([], $provider->resolve(['pageUid' => 13], new ServerRequest()));
        self::assertSame([], $provider->resolve(['pageUid' => '12 OR 1=1'], new ServerRequest()));
        self::assertSame([], $provider->resolve(['pageUid' => -1, 'workspaceId' => 'live'], new ServerRequest()));
    }

    /**
     * Scenario: Absolute URL
     * Scenario: Page module
     */
    #[Test]
    public function backendContextKeepsRegisteredModulePageAndPathAndQueryOfTheUrl(): void
    {
        $provider = $this->get(BackendContextProvider::class);

        self::assertTrue($provider->supports(Source::Backend));
        self::assertSame(
            ['module' => 'web_layout', 'pageUid' => 12, 'pageTitle' => 'Teaser page', 'url' => '/typo3/module/web/layout?token=0123456789abcdef&id=12'],
            $provider->resolve([
                'module' => 'web_layout',
                'pageUid' => '12',
                'url' => 'https://evil.example.com/typo3/module/web/layout?token=0123456789abcdef&id=12',
            ], new ServerRequest()),
        );
    }

    /**
     * Scenario: Unknown module
     */
    #[Test]
    public function backendContextDropsUnknownModulesAndRelativeUrls(): void
    {
        self::assertSame(
            [],
            $this->get(BackendContextProvider::class)->resolve(['module' => 'not_a_module', 'url' => 'javascript:alert(1)'], new ServerRequest()),
        );
    }
}
