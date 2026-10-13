<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Collection;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Collection\CollectableField;
use Vkemeter\Opinion\Collection\CollectionFilter;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;

final class CollectionFilterTest extends UnitTestCase
{
    #[Test]
    public function everythingIsKeptByDefault(): void
    {
        $data = ['url' => 'https://example.com/', 'screen' => ['width' => 1]];

        self::assertSame($data, new CollectionFilter()->filter('client', $data, $this->configuration([])));
    }

    #[Test]
    public function disabledFieldsAreRemovedFromTheirSectionOnly(): void
    {
        $configuration = $this->configuration([CollectableField::ClientScreen, CollectableField::SystemIpAddress]);
        $filter = new CollectionFilter();

        self::assertSame(['url' => 'x'], $filter->filter('client', ['url' => 'x', 'screen' => ['width' => 1]], $configuration));
        self::assertSame(
            ['typo3Version' => '14.3.0', 'backendUser' => ['uid' => 1]],
            $filter->filter('system', ['typo3Version' => '14.3.0', 'ipAddress' => '1.2.3.0', 'backendUser' => ['uid' => 1]], $configuration),
        );
    }

    #[Test]
    public function contextFieldsRemoveAllTheirKeysAndEmptyPageInfo(): void
    {
        $configuration = $this->configuration([
            CollectableField::ContextPage,
            CollectableField::ContextLanguage,
            CollectableField::ContextCacheState,
            CollectableField::ContextImagesOnPage,
        ]);

        self::assertSame(
            ['siteIdentifier' => 'default', 'pageInfo' => ['parseTimeMs' => 12]],
            new CollectionFilter()->filter('context', [
                'pageUid' => 12,
                'pageTitle' => 'Teaser',
                'siteIdentifier' => 'default',
                'languageId' => 0,
                'language' => 'English',
                'pageInfo' => ['cached' => false, 'noCacheReasons' => ['x'], 'parseTimeMs' => 12, 'imagesOnPage' => ['total' => 1]],
            ], $configuration),
        );
        self::assertSame(
            [],
            new CollectionFilter()->filter('context', ['pageInfo' => ['imagesOnPage' => ['total' => 1]]], $configuration),
        );
    }

    #[Test]
    public function enabledNamesListTheAllowedKeysOfASection(): void
    {
        $names = new CollectionFilter()->getEnabledNames('client', $this->configuration([CollectableField::ClientTimezone]));

        self::assertContains('url', $names);
        self::assertContains('screen', $names);
        self::assertNotContains('timezone', $names);
        self::assertNotContains('ipAddress', $names);
    }

    /**
     * @param list<CollectableField> $disabledFields
     */
    private function configuration(array $disabledFields): OpinionConfiguration
    {
        return new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180, $disabledFields);
    }
}
