<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Adminpanel;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleData;
use TYPO3\CMS\Adminpanel\ModuleApi\ShortInfoProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\SubmoduleProviderInterface;
use TYPO3\CMS\Adminpanel\Service\ModuleLoader;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Adminpanel\FeedbackSubModule;
use Vkemeter\Opinion\Adminpanel\OpinionModule;

final class OpinionModuleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function moduleIsRenderedAsTabWithFeedbackSubmodule(): void
    {
        $this->setUpBackendUser(3);

        $module = $this->loadModules()['opinion'] ?? null;

        // MainController renders only modules with submodules and short info as tabs
        self::assertInstanceOf(OpinionModule::class, $module);
        self::assertContains(SubmoduleProviderInterface::class, class_implements($module));
        self::assertContains(ShortInfoProviderInterface::class, class_implements($module));
        self::assertSame(['opinion_feedback'], array_keys($module->getSubModules()));
        self::assertInstanceOf(FeedbackSubModule::class, $module->getSubModules()['opinion_feedback']);
        self::assertSame('Feedback', $module->getLabel());
    }

    #[Test]
    public function moduleIsOnlyLoadedForUsersWithAccess(): void
    {
        $this->setUpBackendUser(2);

        self::assertArrayNotHasKey('opinion', $this->loadModules());
    }

    #[Test]
    public function submoduleContentContainsButtonWithDialogConfiguration(): void
    {
        $this->setUpBackendUser(1);

        $content = $this->get(FeedbackSubModule::class)->getContent(new ModuleData(['pageUid' => 12, 'siteIdentifier' => 'default', 'languageId' => 0, 'workspaceId' => 0]));

        self::assertSame(1, preg_match('#<button[^>]+data-opinion-config="([^"]+)"#', $content, $matches));
        $configuration = json_decode(htmlspecialchars_decode($matches[1]), true);
        self::assertIsArray($configuration);
        self::assertSame('frontend', $configuration['source']);
        self::assertSame(['pageUid' => 12, 'siteIdentifier' => 'default', 'languageId' => 0, 'workspaceId' => 0], $configuration['context']);
        self::assertStringContainsString('/typo3/ajax/opinion/submit?token=', $configuration['endpoint']);
        self::assertStringContainsString('>Send feedback</button>', $content);
    }

    #[Test]
    public function submoduleTellsUsersWithoutEmailAddressWhyFeedbackIsUnavailable(): void
    {
        $this->setUpBackendUser(4);

        $content = $this->get(FeedbackSubModule::class)->getContent(new ModuleData(['pageUid' => 12]));

        self::assertStringContainsString('no email address', $content);
        self::assertStringContainsString('/typo3/module/user/setup', $content);
        self::assertStringNotContainsString('data-opinion-config', $content);
    }

    /**
     * @return array<string, \TYPO3\CMS\Adminpanel\ModuleApi\ModuleInterface>
     */
    private function loadModules(): array
    {
        return $this->get(ModuleLoader::class)->validateSortAndInitializeModules($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules']);
    }
}
