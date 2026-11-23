<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Routing\Route;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Controller\FeedbackModuleController;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class FeedbackModuleControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'opinion' => ['enabled' => '1', 'recipients' => 'team@example.com', 'subjectPrefix' => '[Opinion]'],
        ],
        'MAIL' => ['transport' => 'null'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->setUpBackendUser(1));
    }

    #[Test]
    public function moduleIsAStandaloneUserModule(): void
    {
        $module = $this->get(ModuleProvider::class)->getModule('opinion_feedback');

        self::assertNotNull($module);
        self::assertTrue($module->isStandalone());
        self::assertFalse($module->hasParentModule());
        self::assertSame('user', $module->getAccess());
        self::assertSame('/module/opinion/feedback', $module->getPath());
        self::assertTrue($this->get(ModuleProvider::class)->accessGranted('opinion_feedback', $this->setUpBackendUser(1)));
        // Editors need the module granted in their group
        self::assertFalse($this->get(ModuleProvider::class)->accessGranted('opinion_feedback', $this->setUpBackendUser(3)));
    }

    #[Test]
    public function listShowsFeedbackNewestFirst(): void
    {
        $this->addFeedback('First <script>alert(1)</script> feedback text', '2026-10-01 10:00');
        $this->addFeedback('Second feedback text', '2026-10-02 10:00');
        $this->get(FeedbackRepository::class)->updateStatus($this->addFeedback('Third feedback text', '2026-10-03 10:00'), FeedbackStatus::InProgress);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();

        self::assertStringContainsString('3 feedback entries', $html);
        self::assertStringContainsString('>In progress</span>', $html);
        self::assertStringContainsString('>New</span>', $html);
        self::assertLessThan(strpos($html, 'First'), strpos($html, 'Second feedback text'));
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Ada Admin', $html);
        self::assertStringContainsString('<a href="mailto:admin@example.com">admin@example.com</a>', $html);
        self::assertStringContainsString('opinion/feedback/show', $html);
    }

    #[Test]
    public function showRendersMessageAndDetails(): void
    {
        $uid = $this->addFeedback("Teaser image\nis cropped.", '2026-10-01 10:00');

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertStringContainsString('Teaser image<br />', $html);
        self::assertStringContainsString('pageInfo.imagesOnPage.total', $html);
        self::assertStringContainsString('screen.width', $html);
        self::assertStringContainsString('No screenshot attached.', $html);
        self::assertStringNotContainsString('dumpFile', $html);
        self::assertStringContainsString('opinion/feedback/status', $html);
        self::assertMatchesRegularExpression('#<option\s+value="new"\s+selected>\s*New\s*</option>#', $html);
        self::assertMatchesRegularExpression('#<option\s+value="closed"\s*>#', $html);
    }

    #[Test]
    public function showLinksTheReportedUrlOnce(): void
    {
        $uid = $this->get(FeedbackRepository::class)->add(new Submission(
            message: 'Wrong teaser',
            category: Category::Bug,
            severity: Severity::High,
            source: Source::Backend,
            backendUserUid: 1,
            createdAt: new \DateTimeImmutable('2026-10-01 10:00'),
            clientInfo: ['url' => 'https://example.com/typo3/module/web/layout?id=1'],
            context: ['pageUid' => 1, 'url' => '/typo3/module/web/layout?id=1'],
        ), null);

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertStringContainsString('Reported URL', $html);
        self::assertMatchesRegularExpression('#href="https://example.com/typo3/module/web/layout\?id=1"\s+target="_blank"#', $html);
        self::assertSame(2, substr_count($html, '/typo3/module/web/layout?id=1'), 'Once as href and once as text, not in the tables');
    }

    #[Test]
    public function showOffersANewDeliveryOnlyForUndeliveredFeedback(): void
    {
        $failed = $this->addFeedback('Failed feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($failed, DeliveryStatus::Failed, 'email: FAILED Connection refused');
        $sent = $this->addFeedback('Sent feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($sent, DeliveryStatus::Sent, 'email: OK');

        $controller = $this->get(FeedbackModuleController::class);
        $failedHtml = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$failed]))->getBody();
        $sentHtml = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$sent]))->getBody();

        self::assertStringContainsString('Deliver again', $failedHtml);
        self::assertStringContainsString('opinion/feedback/redeliver', $failedHtml);
        self::assertStringContainsString('1 attempt(s)', $failedHtml);
        self::assertStringNotContainsString('Deliver again', $sentHtml);

        $skipped = $this->addFeedback('Skipped feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($skipped, DeliveryStatus::Skipped, 'no delivery channel enabled');
        $skippedHtml = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$skipped]))->getBody();
        self::assertStringNotContainsString('Deliver again', $skippedHtml);
        self::assertStringContainsString('Not delivered (no channel enabled)', $skippedHtml);
    }

    #[Test]
    public function redeliverDeliversAgainAndRedirectsToTheDetailView(): void
    {
        $uid = $this->addFeedback('Failed feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: FAILED Connection refused');

        $response = $this->get(FeedbackModuleController::class)->redeliverAction(
            $this->createRequest('opinion_feedback.redeliver')->withMethod('POST')->withParsedBody(['uid' => (string)$uid]),
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('opinion/feedback/show', $response->getHeaderLine('Location'));
        self::assertStringContainsString('uid=' . $uid, $response->getHeaderLine('Location'));
        $record = $this->get(FeedbackRepository::class)->findByUid($uid);
        self::assertSame('sent', $record['delivery_status'] ?? null);
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('The feedback has been delivered.', ($messages[0] ?? null)?->getMessage());
    }

    #[Test]
    public function redeliverLeavesSentFeedbackAlone(): void
    {
        $uid = $this->addFeedback('Sent feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($uid, DeliveryStatus::Sent, 'email: OK');

        $this->get(FeedbackModuleController::class)->redeliverAction(
            $this->createRequest('opinion_feedback.redeliver')->withMethod('POST')->withParsedBody(['uid' => (string)$uid]),
        );

        self::assertSame(1, (int)($this->get(FeedbackRepository::class)->findByUid($uid)['delivery_attempts'] ?? 0));
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('This feedback cannot be delivered again.', ($messages[0] ?? null)?->getMessage());
    }

    #[Test]
    public function redeliverWithoutEnabledChannelReportsSkipped(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['disabledDeliveryChannels'] = 'email';
        $uid = $this->addFeedback('Failed feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: FAILED Connection refused');

        $this->get(FeedbackModuleController::class)->redeliverAction(
            $this->createRequest('opinion_feedback.redeliver')->withMethod('POST')->withParsedBody(['uid' => (string)$uid]),
        );

        self::assertSame('skipped', $this->get(FeedbackRepository::class)->findByUid($uid)['delivery_status'] ?? null);
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('No delivery channel is enabled, the feedback has not been delivered.', ($messages[0] ?? null)?->getMessage());
    }

    #[Test]
    public function postRoutesOnlyAcceptPost(): void
    {
        foreach (['opinion_feedback.redeliver', 'opinion_feedback.status'] as $identifier) {
            $route = $this->get(Router::class)->getRoute($identifier);
            self::assertInstanceOf(Route::class, $route);
            self::assertSame(['POST'], $route->getMethods(), $identifier);
        }
    }

    #[Test]
    public function statusActionChangesTheStatusOnly(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: FAILED');

        $response = $this->postStatus($uid, 'closed');

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('uid=' . $uid, $response->getHeaderLine('Location'));
        $record = $this->get(FeedbackRepository::class)->findByUid($uid);
        self::assertSame('closed', $record['status'] ?? null);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertSame(1, (int)($record['delivery_attempts'] ?? 0));
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('The status has been saved.', ($messages[0] ?? null)?->getMessage());
    }

    #[Test]
    public function statusActionRejectsInvalidValues(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');

        $this->postStatus($uid, 'done');

        self::assertSame('new', $this->get(FeedbackRepository::class)->findByUid($uid)['status'] ?? null);
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('The status is invalid and has not been changed.', ($messages[0] ?? null)?->getMessage());
    }

    #[Test]
    public function statusActionRejectsNonAdmins(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');
        $this->setUpBackendUser(2);

        self::assertSame(403, $this->postStatus($uid, 'closed')->getStatusCode());
        self::assertSame('new', $this->get(FeedbackRepository::class)->findByUid($uid)['status'] ?? null);
    }

    #[Test]
    public function editorSeesOnlyOwnFeedbackWithoutDeliveryDetails(): void
    {
        $this->addFeedback('Feedback of the admin', '2026-10-01 10:00');
        $own = $this->addFeedback('Feedback of the reporter', '2026-10-02 10:00', 3);
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($own, DeliveryStatus::Failed, 'email: FAILED team@example.com');
        $this->setUpBackendUser(3);
        $controller = $this->get(FeedbackModuleController::class);

        $list = (string)$controller->listAction($this->createRequest('opinion_feedback'))->getBody();
        self::assertStringContainsString('1 feedback entries', $list);
        self::assertStringContainsString('Feedback of the reporter', $list);
        self::assertStringNotContainsString('Feedback of the admin', $list);
        self::assertStringNotContainsString('Delivery status', $list);

        $detail = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$own]))->getBody();
        self::assertStringContainsString('Feedback of the reporter', $detail);
        self::assertStringContainsString('>New</span>', $detail);
        self::assertStringNotContainsString('team@example.com', $detail);
        self::assertStringNotContainsString('Deliver again', $detail);
        self::assertStringNotContainsString('opinion/feedback/status', $detail);
    }

    #[Test]
    public function showLinksTheScreenshotThroughTheModule(): void
    {
        $uid = $this->addFeedbackWithScreenshot(1);

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertSame(2, substr_count($html, '/typo3/opinion/screenshot?'));
        self::assertStringNotContainsString('dumpFile', $html);
    }

    #[Test]
    public function screenshotIsDeliveredToAdminsAndTheSenderOnly(): void
    {
        $ofAdmin = $this->addFeedbackWithScreenshot(1);
        $ofReporter = $this->addFeedbackWithScreenshot(3);
        $withoutScreenshot = $this->addFeedback('No screenshot', '2026-10-01 10:00');
        self::assertSame('opinion_feedback', $this->get(Router::class)->getRoute('opinion_screenshot')?->getOption('inheritAccessFromModule'), 'Core checks the module access');

        $response = $this->requestScreenshot($ofReporter);
        self::assertSame(200, $response->getStatusCode(), 'admin, foreign feedback');
        self::assertSame(self::png(), (string)$response->getBody());
        self::assertSame(404, $this->requestScreenshot($withoutScreenshot)->getStatusCode(), 'no screenshot');
        self::assertSame(404, $this->requestScreenshot(999)->getStatusCode(), 'unknown');

        $this->setUpBackendUser(3);
        self::assertSame(200, $this->requestScreenshot($ofReporter)->getStatusCode(), 'sender');
        self::assertSame(404, $this->requestScreenshot($ofAdmin)->getStatusCode(), 'foreign feedback');

        $this->setUpBackendUser(2);
        self::assertSame(403, $this->requestScreenshot($ofReporter)->getStatusCode(), 'without options.opinion.enable');
    }

    #[Test]
    public function editorGetsNoForeignFeedback(): void
    {
        $foreign = $this->addFeedback('Feedback of the admin', '2026-10-01 10:00');
        $this->setUpBackendUser(3);

        $response = $this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$foreign]));

        self::assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function editorCannotDeliverAgainOrChangeTheStatus(): void
    {
        $own = $this->addFeedback('Feedback of the reporter', '2026-10-02 10:00', 3);
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($own, DeliveryStatus::Failed, 'email: FAILED');
        $this->setUpBackendUser(3);

        $response = $this->get(FeedbackModuleController::class)->redeliverAction(
            $this->createRequest('opinion_feedback.redeliver')->withMethod('POST')->withParsedBody(['uid' => (string)$own]),
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(403, $this->postStatus($own, 'closed')->getStatusCode());
        $record = $this->get(FeedbackRepository::class)->findByUid($own);
        self::assertSame(1, (int)($record['delivery_attempts'] ?? 0));
        self::assertSame('new', $record['status'] ?? null);
    }

    #[Test]
    public function moduleNeedsTheFeedbackPermission(): void
    {
        $this->setUpBackendUser(2);

        self::assertSame(403, $this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getStatusCode());
    }

    #[Test]
    public function disabledExtensionClosesTheModuleForEditorsOnly(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['enabled'] = '0';
        $controller = $this->get(FeedbackModuleController::class);

        self::assertSame(200, $controller->listAction($this->createRequest('opinion_feedback'))->getStatusCode());
        $this->setUpBackendUser(3);
        self::assertSame(403, $controller->listAction($this->createRequest('opinion_feedback'))->getStatusCode());
    }

    #[Test]
    public function listCanBeFilteredAndKeepsTheFilterInItsLinks(): void
    {
        $this->addFeedback('Teaser is cropped', '2026-10-01 10:00');
        $closed = $this->addFeedback('Old bug', '2026-10-02 10:00');
        $this->get(FeedbackRepository::class)->updateStatus($closed, FeedbackStatus::Closed);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['status' => 'closed', 'search' => '<b>bug</b>', 'category' => 'nonsense']))->getBody();

        self::assertStringContainsString('No feedback matches the search and filter.', $html, 'the search term matches nothing');
        self::assertStringContainsString('value="&lt;b&gt;bug&lt;/b&gt;"', $html);
        self::assertStringNotContainsString('<b>bug</b>', $html);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['status' => 'closed', 'category' => 'nonsense']))->getBody();
        self::assertStringContainsString('1 feedback entries', $html);
        self::assertStringContainsString('Old bug', $html);
        self::assertStringNotContainsString('Teaser is cropped', $html);
        self::assertMatchesRegularExpression('#href="[^"]*opinion/feedback/show[^"]*uid=' . $closed . '[^"]*status=closed#', $html, 'detail link keeps the filter');
        self::assertStringNotContainsString('category=nonsense', $html, 'invalid value ignored');
        self::assertMatchesRegularExpression('#<input\s+type="hidden"\s+name="token"#', $html, 'GET form keeps the route token');
        self::assertStringContainsString('Reset', $html);
    }

    #[Test]
    public function pagingKeepsTheFilter(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->addFeedback('Bug ' . $i, '2026-10-01 10:00');
        }

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['search' => 'Bug']))->getBody();

        self::assertMatchesRegularExpression('#href="[^"]*page=2[^"]*search=Bug#', $html);
    }

    #[Test]
    public function editorSearchFindsOwnFeedbackOnly(): void
    {
        $this->addFeedback('Teaser of the admin', '2026-10-01 10:00');
        $this->addFeedback('Teaser of the reporter', '2026-10-02 10:00', 3);
        $this->setUpBackendUser(3);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['search' => 'Teaser']))->getBody();

        self::assertStringContainsString('Teaser of the reporter', $html);
        self::assertStringNotContainsString('Teaser of the admin', $html);
        self::assertStringNotContainsString('opinion-filter-deliveryStatus', $html);
    }

    #[Test]
    public function statusChangeReturnsWithTheFilter(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');

        $response = $this->get(FeedbackModuleController::class)->statusAction(
            $this->createRequest('opinion_feedback.status')->withMethod('POST')->withParsedBody(['uid' => (string)$uid, 'status' => 'closed', 'filter' => ['search' => 'Feed', 'status' => 'bogus']]),
        );

        self::assertStringContainsString('search=Feed', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('bogus', $response->getHeaderLine('Location'));
    }

    #[Test]
    public function showRedirectsToListForUnknownFeedback(): void
    {
        $response = $this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => '999']));

        self::assertSame(302, $response->getStatusCode());
    }

    private function postStatus(int $uid, string $status): ResponseInterface
    {
        return $this->get(FeedbackModuleController::class)->statusAction(
            $this->createRequest('opinion_feedback.status')->withMethod('POST')->withParsedBody(['uid' => (string)$uid, 'status' => $status]),
        );
    }

    /**
     * @param array<string, string> $query
     */
    private function createRequest(string $routeIdentifier, array $query = []): ServerRequest
    {
        $route = $this->get(Router::class)->getRoute($routeIdentifier);
        self::assertInstanceOf(Route::class, $route);
        $request = new ServerRequest('https://example.com/typo3/module/opinion/feedback', 'GET', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $route)
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('opinion_feedback'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function requestScreenshot(int $uid): ResponseInterface
    {
        return $this->get(FeedbackModuleController::class)->screenshotAction($this->createRequest('opinion_screenshot', ['uid' => (string)$uid]));
    }

    private function addFeedbackWithScreenshot(int $senderUid): int
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $screenshot = $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());

        return $this->get(FeedbackRepository::class)->add(new Submission('With screenshot', Category::Bug, Severity::Low, Source::Backend, $senderUid, new \DateTimeImmutable()), $screenshot);
    }

    private static function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }

    private function addFeedback(string $message, string $date, int $senderUid = 1): int
    {
        return $this->get(FeedbackRepository::class)->add(new Submission(
            message: $message,
            category: Category::Bug,
            severity: Severity::High,
            source: Source::Frontend,
            backendUserUid: $senderUid,
            createdAt: new \DateTimeImmutable($date),
            clientInfo: ['screen' => ['width' => 2560]],
            systemInfo: ['typo3Version' => '14.3.0', 'backendUser' => ['username' => 'admin', 'realName' => 'Ada Admin', 'email' => 'admin@example.com']],
            context: ['pageUid' => 12, 'pageInfo' => ['imagesOnPage' => ['total' => 3]]],
        ), null);
    }
}
