<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemsRegistry;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Backend\ToolbarItem\OpinionToolbarItem;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class OpinionToolbarItemTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel', 'setup'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        // The TYPO3 13 shortcut toolbar item needs a language service when the registry builds it
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
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

        [$html, $configuration] = $this->renderItem();

        self::assertSame('backend', $configuration['source']);
        self::assertStringContainsString('/typo3/ajax/opinion/submit?token=', $configuration['endpoint']);
        self::assertStringContainsString('Send feedback', $html);
        self::assertStringContainsString('data-opinion-unread="0"', $html, 'nothing unread');
        self::assertArrayNotHasKey('conversations', $configuration, 'no link in the dialog');
    }

    #[Test]
    public function badgeCountsFeedbackWithUnreadMessages(): void
    {
        $feedbackRepository = $this->get(FeedbackRepository::class);
        $conversation = $this->get(ConversationRepository::class);
        foreach (['First', 'Second'] as $message) {
            $uid = $feedbackRepository->add(new Submission($message . ' feedback', Category::Bug, Severity::Low, Source::Backend, 1, new \DateTimeImmutable()), null);
            $conversation->add($uid, 3, 'Reply of the reporter', new \DateTimeImmutable());
            $conversation->add($uid, 3, 'Another reply', new \DateTimeImmutable());
        }
        $this->setUpBackendUser(1);

        [$html] = $this->renderItem();

        self::assertStringContainsString('data-opinion-unread="2"', $html);
        self::assertStringContainsString('data-opinion-unread-label="2 feedback entries with new messages"', $html);
        self::assertStringNotContainsString('toolbar-item-badge', $html, 'shown on the module menu entry, not on the toolbar');
    }

    #[Test]
    public function noUnreadCountWhileConversationIsSwitchedOff(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['conversation'] = '0';
        $uid = $this->get(FeedbackRepository::class)->add(new Submission('Feedback', Category::Bug, Severity::Low, Source::Backend, 1, new \DateTimeImmutable()), null);
        $this->get(ConversationRepository::class)->add($uid, 3, 'Reply', new \DateTimeImmutable());
        $this->setUpBackendUser(1);

        [$html] = $this->renderItem();

        self::assertStringContainsString('data-opinion-unread="0"', $html);
    }

    #[Test]
    public function noUnreadCountWithoutModuleAccess(): void
    {
        // The reporter may send feedback but is not granted the module
        $this->setUpBackendUser(3);

        // Messages exist, but without the module there is nothing to show them in
        $uid = $this->get(FeedbackRepository::class)->add(new Submission('Feedback', Category::Bug, Severity::Low, Source::Backend, 3, new \DateTimeImmutable()), null);
        $this->get(ConversationRepository::class)->add($uid, 1, 'Answer', new \DateTimeImmutable());

        [$html] = $this->renderItem();

        self::assertStringContainsString('data-opinion-unread="0"', $html);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function renderItem(): array
    {
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

        return [$html, $configuration];
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
