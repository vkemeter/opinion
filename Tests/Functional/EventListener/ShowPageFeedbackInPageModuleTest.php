<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\EventListener;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\NullSite;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class ShowPageFeedbackInPageModuleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel', 'setup'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
    }

    #[Test]
    public function administratorsSeeBothGroupsWithLinks(): void
    {
        $this->addFeedback(12, Source::Frontend, 'Frontend one');
        $this->addFeedback(12, Source::Frontend, 'Frontend two');
        $this->addFeedback(12, Source::Backend, 'Backend <b>one</b>');
        $this->addFeedback(12, Source::Frontend, 'Closed one', FeedbackStatus::Closed);
        $this->addFeedback(1, Source::Frontend, 'Other page');

        $html = $this->renderHeader(12, 1);

        self::assertMatchesRegularExpression('#<details class="callout-content">\s*<summary[^>]*>\s*Open feedback on this page: 3\s*</summary>#', $html, 'collapsed, with the total');
        self::assertLessThan(strpos($html, 'From the backend'), strpos($html, 'From the frontend'), 'frontend group first');
        self::assertStringContainsString('Frontend two', $html);
        self::assertStringContainsString('Backend &lt;b&gt;one&lt;/b&gt;', $html);
        self::assertStringNotContainsString('Closed one', $html);
        self::assertStringNotContainsString('Other page', $html);
        self::assertSame(3, substr_count($html, '/typo3/module/opinion/feedback/show?'));
    }

    #[Test]
    public function editorsSeeTheEntriesWithoutLinks(): void
    {
        $this->addFeedback(12, Source::Backend, 'Backend only');
        self::assertSame('', $this->renderHeader(12, 3), 'editor without access to the page');

        // Access as a backend group would grant it: live workspace, web mount and page permission "show"
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['workspace_perms' => 1], ['uid' => 3]);
        foreach ([1, 12] as $pageUid) {
            // The rootline up to the mount is read with the same permission
            $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['perms_everybody' => Permission::PAGE_SHOW], ['uid' => $pageUid]);
        }
        $html = $this->renderHeader(12, 3, '1');

        self::assertStringContainsString('Backend only', $html);
        self::assertStringNotContainsString('From the frontend', $html, 'empty group not shown');
        self::assertStringNotContainsString('/typo3/module/opinion/feedback/show', $html);
    }

    #[Test]
    public function noBoxWithoutOpenFeedbackOrWithoutPermissionToSendFeedback(): void
    {
        $this->addFeedback(12, Source::Frontend, 'Closed one', FeedbackStatus::Closed);
        self::assertSame('', $this->renderHeader(12, 1), 'nothing open');

        $this->addFeedback(12, Source::Frontend, 'Open one');
        self::assertSame('', $this->renderHeader(12, 2), 'editor who may not send feedback');
    }

    /**
     * Page context as built for an administrator; the box itself is rendered for the given user.
     */
    private function renderHeader(int $pageUid, int $userUid, string $webMounts = ''): string
    {
        $admin = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($admin);
        $request = new ServerRequest('https://example.com/typo3/module/web/layout', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withQueryParams(['id' => (string)$pageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('site', new NullSite())
            ->withAttribute('route', $this->get(Router::class)->getRoute('web_layout'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $moduleTemplate = $this->get(ModuleTemplateFactory::class)->create($request);

        $backendUser = $this->setUpBackendUser($userUid);
        if ($webMounts !== '') {
            $backendUser->groupData['webmounts'] = $webMounts;
        }
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $event = new ModifyPageLayoutContentEvent($request, $moduleTemplate);
        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return $event->getHeaderContent();
    }

    private function addFeedback(int $pageUid, Source $source, string $message, FeedbackStatus $status = FeedbackStatus::New): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission($message, Category::Bug, Severity::Low, $source, 3, new \DateTimeImmutable(), context: ['pageUid' => $pageUid]), null);
        $repository->updateStatus($uid, $status);
    }
}
