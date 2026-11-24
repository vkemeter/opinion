<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Dashboard;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Dashboard\OpenFeedbackDataProvider;
use Vkemeter\Opinion\Dashboard\UnreadConversationsDataProvider;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class DashboardWidgetsTest extends FunctionalTestCase
{
    private const array WIDGETS = ['opinion-openFeedback', 'opinion-newMessages', 'opinion-latestFeedback'];

    protected array $coreExtensionsToLoad = ['adminpanel', 'dashboard'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function widgetsAreRegisteredInTheGroupFeedback(): void
    {
        $this->setUpAdmin();
        $registry = $this->get(WidgetRegistry::class);

        self::assertSame(self::WIDGETS, array_keys($registry->getAvailableWidgetsForWidgetGroup('opinion')));
    }

    #[Test]
    public function openFeedbackCountsNewAndInProgressWithTheRulesOfTheModule(): void
    {
        $this->addFeedback(3, 'new');
        $this->addFeedback(1, 'new');
        $this->addFeedback(1, 'in_progress');
        $this->addFeedback(1, 'closed');
        $provider = $this->get(OpenFeedbackDataProvider::class);

        $this->setUpAdmin();
        self::assertSame(3, $provider->getNumber(), 'administrator, closed not counted');

        $this->setUpBackendUser(3);
        self::assertSame(0, $provider->getNumber(), 'editor without the module');

        $this->setUpEditorWithModule();
        self::assertSame(1, $provider->getNumber(), 'editor with the module: own feedback only');
    }

    #[Test]
    public function openFeedbackFollowsTheClosedStatuses(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,waiting,closed,rejected';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['closedStatuses'] = 'closed,rejected';
        foreach (['new', 'waiting', 'closed', 'rejected'] as $status) {
            $this->addFeedback(1, $status);
        }
        $this->setUpAdmin();

        self::assertSame(2, $this->get(OpenFeedbackDataProvider::class)->getNumber());
    }

    #[Test]
    public function newMessagesCountsUnreadFeedbackAndIsZeroWhileTheConversationIsOff(): void
    {
        $conversation = $this->get(ConversationRepository::class);
        foreach ([1, 2] as $ignored) {
            $conversation->add($this->addFeedback(3, 'new'), 1, 'Answer of the admin', new \DateTimeImmutable());
        }
        $provider = $this->get(UnreadConversationsDataProvider::class);

        $this->setUpEditorWithModule();
        self::assertSame(2, $provider->getNumber());

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['conversation'] = '0';
        self::assertSame(0, $provider->getNumber());
    }

    #[Test]
    public function latestFeedbackListsTheFiveNewestLinkedToTheDetailView(): void
    {
        for ($day = 1; $day <= 7; $day++) {
            $this->addFeedback(1, 'new', sprintf('Feedback of day %d <b>bold</b>', $day), sprintf('2026-11-%02d 10:00', $day));
        }
        $this->setUpAdmin();

        $html = $this->renderWidget('opinion-latestFeedback');

        self::assertStringContainsString('Feedback of day 7', $html);
        self::assertStringContainsString('Feedback of day 3', $html);
        self::assertStringNotContainsString('Feedback of day 2', $html);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
        self::assertSame(5, substr_count($html, '/typo3/module/opinion/feedback/show?'));
        self::assertStringContainsString('Open module &quot;Feedback&quot;', $html);
    }

    #[Test]
    public function latestFeedbackOfAnEditorShowsOnlyOwnFeedback(): void
    {
        $this->addFeedback(1, 'new', 'Feedback of the admin');
        $this->addFeedback(3, 'new', 'Feedback of the reporter');
        $this->setUpEditorWithModule();

        $html = $this->renderWidget('opinion-latestFeedback');

        self::assertStringContainsString('Feedback of the reporter', $html);
        self::assertStringNotContainsString('Feedback of the admin', $html);
    }

    #[Test]
    public function latestFeedbackShowsTheLabelOfAnOwnCategory(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['categories'] = 'question=Question & answer';
        $repository = $this->get(FeedbackRepository::class);
        $repository->add(new Submission('A question about the teaser', 'question', Severity::Low, Source::Backend, 1, new \DateTimeImmutable()), null);
        $repository->add(new Submission('An idea for the footer', 'idea', Severity::Low, Source::Backend, 1, new \DateTimeImmutable()), null);
        $this->setUpAdmin();

        $html = $this->renderWidget('opinion-latestFeedback');

        self::assertStringContainsString('Question &amp; answer', $html);
        self::assertStringContainsString('<td>Idea</td>', $html, 'removed shipped category keeps its translation');
    }

    #[Test]
    public function numberWidgetShowsTheNumberAndTheButtonToTheModule(): void
    {
        $this->addFeedback(1, 'new');
        $this->setUpAdmin();

        $html = $this->renderWidget('opinion-openFeedback');

        self::assertMatchesRegularExpression('#<div class="widget-number-number">\s*1\s*</div>#', $html);
        self::assertStringContainsString('Open feedback', $html);
        self::assertStringContainsString('/typo3/module/opinion/feedback?token=', $html);
    }

    #[Test]
    public function numberWidgetHidesTheButtonFromUsersWithoutTheModule(): void
    {
        $backendUser = $this->setUpBackendUser(3);
        $backendUser->groupData['available_widgets'] = implode(',', self::WIDGETS);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $html = $this->renderWidget('opinion-openFeedback');

        self::assertStringContainsString('Open feedback', $html);
        self::assertStringNotContainsString('/typo3/module/opinion/feedback', $html);
    }

    private function renderWidget(string $identifier): string
    {
        $request = new ServerRequest('https://example.com/typo3/module/dashboard', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $widget = $this->get(WidgetRegistry::class)->getAvailableWidget($request, $identifier);
        self::assertTrue(method_exists($widget, 'renderWidgetContent'));

        return $widget->renderWidgetContent();
    }

    private function setUpAdmin(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
    }

    /**
     * The reporter may send feedback; the module is granted as a backend group would do.
     */
    private function setUpEditorWithModule(): void
    {
        $backendUser = $this->setUpBackendUser(3);
        $backendUser->groupData['modules'] = 'opinion_feedback';
        $backendUser->groupData['available_widgets'] = implode(',', self::WIDGETS);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function addFeedback(int $senderUid, string $status, string $message = 'Teaser is cropped', string $date = 'now'): int
    {
        $repository = $this->get(FeedbackRepository::class);
        $uid = $repository->add(new Submission($message, 'bug', Severity::Low, Source::Backend, $senderUid, new \DateTimeImmutable($date)), null);
        $repository->updateStatus($uid, $status);

        return $uid;
    }
}
