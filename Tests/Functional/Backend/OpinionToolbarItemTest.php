<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemsRegistry;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Backend\ToolbarItem\OpinionToolbarItem;

final class OpinionToolbarItemTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function toolbarItemIsRegistered(): void
    {
        $this->setUpBackendUser(1);
        $classes = array_map(get_class(...), $this->get(ToolbarItemsRegistry::class)->getToolbarItems());

        self::assertContains(OpinionToolbarItem::class, $classes);
    }

    #[Test]
    public function accessFollowsTheAccessChecker(): void
    {
        $this->setUpBackendUser(2);
        self::assertFalse($this->getToolbarItem()->checkAccess());

        $this->setUpBackendUser(3);
        self::assertTrue($this->getToolbarItem()->checkAccess());
    }

    #[Test]
    public function itemRendersButtonWithDialogConfiguration(): void
    {
        $this->setUpBackendUser(1);
        $request = new ServerRequest('https://example.com/typo3/main', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $toolbarItem = $this->getToolbarItem();
        $toolbarItem->setRequest($request);

        $html = $toolbarItem->getItem();

        self::assertSame(1, preg_match('#data-opinion-toolbar-config="([^"]+)"#', $html, $matches));
        $configuration = json_decode(htmlspecialchars_decode($matches[1]), true);
        self::assertIsArray($configuration);
        self::assertSame('backend', $configuration['source']);
        self::assertStringContainsString('/typo3/ajax/opinion/submit?token=', $configuration['endpoint']);
        self::assertStringContainsString('Send feedback', $html);
    }

    private function getToolbarItem(): OpinionToolbarItem
    {
        foreach ($this->get(ToolbarItemsRegistry::class)->getToolbarItems() as $item) {
            if ($item instanceof OpinionToolbarItem) {
                return $item;
            }
        }
        self::fail('Toolbar item not registered');
    }
}
