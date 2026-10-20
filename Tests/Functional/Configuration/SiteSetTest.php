<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Site\Set\SetRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class SiteSetTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    #[Test]
    public function siteSetEnablesTheAdminPanelThroughASetting(): void
    {
        $setRegistry = $this->get(SetRegistry::class);
        $set = $setRegistry->getSet('vkemeter/opinion');

        self::assertNotNull($set, 'Site set not found');
        self::assertArrayNotHasKey('vkemeter/opinion', $setRegistry->getInvalidSets());
        self::assertStringContainsString('config.admPanel = {$opinion.adminPanel.enable}', (string)file_get_contents(GeneralUtility::getFileAbsFileName($set->typoscript . 'setup.typoscript')));
        self::assertTrue($set->settingsDefinitions[0]->default ?? null);
        self::assertSame('opinion.adminPanel.enable', $set->settingsDefinitions[0]->key);
    }
}
