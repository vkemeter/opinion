<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Adminpanel;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleInterface;
use TYPO3\CMS\Adminpanel\Service\ModuleLoader;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Adminpanel\PageFeedbackModule;
use Vkemeter\Opinion\Adminpanel\PageFeedbackSubModule;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class PageFeedbackModuleTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(3));
    }

    /**
     * Scenario: Two open feedback
     * Scenario: Backend feedback of the page
     */
    #[Test]
    public function entryShowsTheNumberOfOpenFrontendFeedbackOfThePage(): void
    {
        $this->addFeedback(12, Source::Frontend, 'Teaser of the admin', 1);
        $this->addFeedback(12, Source::Frontend, 'Teaser of the reporter', 3);
        $this->addFeedback(12, Source::Backend, 'From the page module', 1);
        $this->addFeedback(12, Source::Frontend, 'Done', 1, 'closed');
        $this->addFeedback(13, Source::Frontend, 'Other page', 1);
        $this->renderPage(12);

        $module = $this->loadModules()['opinion_page'] ?? null;

        self::assertInstanceOf(PageFeedbackModule::class, $module);
        self::assertSame('2', $module->getShortInfo(), 'open frontend feedback of all senders, no backend or closed feedback');
        self::assertSame('Feedback on this page', $module->getLabel());
        self::assertSame(['opinion_page_list'], array_keys($module->getSubModules()));
    }

    /**
     * Scenario: Own closed status
     */
    #[Test]
    public function ownClosedStatusesAreNotCounted(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,closed,rejected';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['closedStatuses'] = 'closed,rejected';
        $this->addFeedback(12, Source::Frontend, 'Open', 1);
        $this->addFeedback(12, Source::Frontend, 'Rejected', 1, 'rejected');
        $this->renderPage(12);

        $module = $this->loadModules()['opinion_page'] ?? null;
        self::assertInstanceOf(PageFeedbackModule::class, $module);
        self::assertSame('1', $module->getShortInfo());
    }

    /**
     * Scenario: Nothing open
     */
    #[Test]
    public function entryIsHiddenWithoutOpenFrontendFeedbackOrWithoutAccess(): void
    {
        $this->addFeedback(12, Source::Backend, 'Backend only', 1);
        $this->addFeedback(14, Source::Frontend, 'Open', 1);

        $this->renderPage(12);
        self::assertArrayNotHasKey('opinion_page', $this->loadModules(), 'backend feedback only');

        $GLOBALS['TYPO3_REQUEST'] = new ServerRequest('https://example.com/');
        self::assertArrayNotHasKey('opinion_page', $this->loadModules(), 'no page resolved yet');

        $this->renderPage(14);
        $this->setUpBackendUser(2);
        self::assertArrayNotHasKey('opinion_page', $this->loadModules(), 'user who may not send feedback');
    }

    /**
     * Scenario: Two open feedback
     * Scenario: Markup in a message
     */
    #[Test]
    public function listShowsTextDateAndStatusOnlyEscaped(): void
    {
        $this->addFeedback(12, Source::Frontend, '<script>alert(1)</script>', 1, 'in_progress', '2026-11-02 09:30');
        $this->addFeedback(12, Source::Backend, 'From the page module', 1);
        $subModule = $this->get(PageFeedbackSubModule::class);

        $content = $subModule->getContent($subModule->getDataToStore($this->renderPage(12), new Response()));

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
        self::assertStringNotContainsString('<script>', $content);
        self::assertStringContainsString('02.11.2026 09:30', $content);
        self::assertStringContainsString('In progress', $content);
        self::assertStringNotContainsString('From the page module', $content);
        self::assertStringNotContainsString('Ada Admin', $content, 'no sender');
        self::assertStringNotContainsString('<a ', $content, 'no link for editors');
    }

    /**
     * Scenario: Link for administrators
     */
    #[Test]
    public function administratorsGetALinkToTheDetailView(): void
    {
        $this->addFeedback(12, Source::Frontend, 'Teaser is cropped', 3);
        $this->setUpBackendUser(1);
        $subModule = $this->get(PageFeedbackSubModule::class);

        $content = $subModule->getContent($subModule->getDataToStore($this->renderPage(12), new Response()));

        self::assertMatchesRegularExpression('#<a href="[^"]*/typo3/module/opinion/feedback/show\?[^"]*uid=\d+[^"]*" target="_blank" rel="noopener"[^>]*>Teaser is cropped</a>#', $content);
        self::assertStringNotContainsString('token=', $content, 'shareable URL');
    }

    /**
     * Not in the spec or the documentation: the admin panel list shows ten entries and the number of the others (candidate for the spec)
     */
    #[Test]
    public function listShowsTenAndTellsHowManyMore(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->addFeedback(12, Source::Frontend, 'Feedback ' . $i, 1);
        }
        $subModule = $this->get(PageFeedbackSubModule::class);

        $content = $subModule->getContent($subModule->getDataToStore($this->renderPage(12), new Response()));

        self::assertSame(PageFeedbackSubModule::LIMIT, substr_count($content, '<tr><td'));
        self::assertStringContainsString('and 2 more', $content);
    }

    /**
     * The request as the frontend request handler leaves it in the global when the admin panel renders.
     */
    private function renderPage(int $pageUid): ServerRequest
    {
        $request = new ServerRequest('https://example.com/', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/index.php'])
            ->withAttribute('routing', new PageArguments($pageUid, '0', []));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /**
     * @return array<string, ModuleInterface>
     */
    private function loadModules(): array
    {
        return $this->get(ModuleLoader::class)->validateSortAndInitializeModules($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['adminpanel']['modules']);
    }

    private function addFeedback(int $pageUid, Source $source, string $message, int $senderUid, string $status = 'new', string $date = 'now'): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission($message, 'bug', Severity::Low, $source, $senderUid, new \DateTimeImmutable($date), context: ['pageUid' => $pageUid]), null);
        $repository->updateStatus($uid, $status);
    }
}
