<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\Routing\Route;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Controller\FeedbackModuleController;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\ConversationRepository;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;
use Vkemeter\Opinion\Tests\Functional\Fixtures\MailSpy;

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

    /**
     * Scenario: Editor
     */
    #[Test]
    public function moduleIsAStandaloneUserModule(): void
    {
        $module = $this->get(ModuleProvider::class)->getModule('opinion_feedback');

        self::assertNotNull($module);
        self::assertTrue($module->isStandalone());
        self::assertFalse($module->hasParentModule());
        self::assertTrue($this->get(ModuleProvider::class)->accessGranted('opinion_feedback', $this->setUpBackendUser(1)));
        // Editors need the module granted in their group
        self::assertFalse($this->get(ModuleProvider::class)->accessGranted('opinion_feedback', $this->setUpBackendUser(3)));
    }

    /**
     * Scenario: Status column
     * Scenario: Sender with email address
     */
    #[Test]
    public function listShowsFeedbackNewestFirst(): void
    {
        $this->addFeedback('First <script>alert(1)</script> feedback text', '2026-10-01 10:00');
        $this->addFeedback('Second feedback text', '2026-10-02 10:00');
        $this->get(FeedbackRepository::class)->updateStatus($this->addFeedback('Third feedback text', '2026-10-03 10:00'), 'in_progress');

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

    /**
     * Requirement: Detail view (full message and collected details as tables)
     */
    #[Test]
    public function showRendersMessageAndDetails(): void
    {
        $uid = $this->addFeedback("Teaser image\nis cropped.", '2026-10-01 10:00');

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertStringContainsString('Teaser image<br />', $html);
        self::assertStringContainsString('pageInfo.imagesOnPage.total', $html);
        self::assertStringContainsString('screen.width', $html);
        self::assertStringContainsString('No screenshot attached.', $html);
        self::assertStringContainsString('opinion/feedback/save', $html);
        self::assertMatchesRegularExpression('#<option\s+value="new"\s+selected>\s*New\s*</option>#', $html);
        self::assertMatchesRegularExpression('#<option\s+value="closed"\s*>#', $html);
    }

    /**
     * Scenario: Backend feedback
     */
    #[Test]
    public function showLinksTheReportedUrlOnce(): void
    {
        $uid = $this->get(FeedbackRepository::class)->add(new Submission(
            message: 'Wrong teaser',
            category: 'bug',
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

    /**
     * Scenario: Foreign host
     * Scenario: Backslash in a backend path
     */
    #[Test]
    public function showDoesNotLinkAForeignHostOrABackslashPath(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $foreign = $repository->add(new Submission('Foreign', 'bug', Severity::High, Source::Frontend, 1, new \DateTimeImmutable('2026-10-01 10:00'), clientInfo: ['url' => 'https://evil.example/login']), null);
        $backslash = $repository->add(new Submission('Backslash', 'bug', Severity::High, Source::Backend, 1, new \DateTimeImmutable('2026-10-01 10:00'), context: ['url' => '/\\evil.example/typo3']), null);

        $controller = $this->get(FeedbackModuleController::class);
        $foreignHtml = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$foreign]))->getBody();
        $backslashHtml = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$backslash]))->getBody();

        self::assertStringContainsString('https://evil.example/login', $foreignHtml);
        self::assertStringNotContainsString('href="https://evil.example', $foreignHtml);
        self::assertStringContainsString('evil.example/typo3', $backslashHtml);
        self::assertStringNotContainsString('evil.example/typo3"', $backslashHtml);
    }

    /**
     * Requirement: Deliver again (offered for failed or pending feedback)
     */
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

    /**
     * Scenario: Successful retry
     */
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

    /**
     * Scenario: Already sent
     */
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

    /**
     * Scenario: All channels disabled
     */
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

    /**
     * Requirement: Deliver again / Administrators change the status (by POST)
     */
    #[Test]
    public function postRoutesOnlyAcceptPost(): void
    {
        foreach (['opinion_feedback.redeliver', 'opinion_feedback.save'] as $identifier) {
            $route = $this->get(Router::class)->getRoute($identifier);
            self::assertInstanceOf(Route::class, $route);
            self::assertSame(['POST'], $route->getMethods(), $identifier);
        }
    }

    /**
     * Scenario: Admin closes a feedback
     */
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

    /**
     * Scenario: Invalid value
     */
    #[Test]
    public function statusActionRejectsInvalidValues(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');

        $this->postStatus($uid, 'done');

        self::assertSame('new', $this->get(FeedbackRepository::class)->findByUid($uid)['status'] ?? null);
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages();
        self::assertSame('The status is invalid and has not been changed.', ($messages[0] ?? null)?->getMessage());
    }

    /**
     * Scenario: Own status
     * Scenario: Own status with label
     */
    #[Test]
    public function ownStatusesCanBeSetAndRemovedOnesAreKept(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,waiting=Waiting for reply,closed';
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');

        $this->postStatus($uid, 'waiting');
        self::assertSame('waiting', $this->statusOf($uid));
        $show = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();
        self::assertStringContainsString('Waiting for reply', $show);
        self::assertStringContainsString('badge badge-warning', $show);

        $this->postStatus($uid, 'in_progress');
        self::assertSame('waiting', $this->statusOf($uid), 'a status that is not configured cannot be set');

        // Removed from the configuration: still shown, kept when the form is saved with a message
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,closed';
        $this->postSave($uid, ['status' => 'waiting', 'message' => 'Any news?']);
        self::assertSame('waiting', $this->statusOf($uid));
        self::assertSame(1, $this->get(ConversationRepository::class)->countByFeedback($uid));
    }

    /**
     * Scenario: Request by a non-admin
     */
    #[Test]
    public function statusActionRejectsNonAdmins(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');
        $this->setUpBackendUser(2);

        self::assertSame(403, $this->postStatus($uid, 'closed')->getStatusCode());
        self::assertSame('new', $this->get(FeedbackRepository::class)->findByUid($uid)['status'] ?? null);
    }

    /**
     * Scenario: Own list
     * Scenario: Editor views own feedback
     */
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
        self::assertStringNotContainsString('name="status"', $detail, 'the status is read-only');
    }

    /**
     * Scenario: Screenshot through the module
     */
    #[Test]
    public function showLinksTheScreenshotThroughTheModule(): void
    {
        $uid = $this->addFeedbackWithScreenshot(1);

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertSame(2, substr_count($html, '/typo3/opinion/screenshot?'));
        self::assertStringNotContainsString('dumpFile', $html);
    }

    /**
     * Scenario: Feedback with pins
     * Scenario: Markup in a comment
     */
    #[Test]
    public function showListsThePinCommentsEscaped(): void
    {
        $withPins = $this->addFeedbackWithScreenshot(1, [['number' => 1, 'comment' => "wrong logo\nshould be the new one"], ['number' => 3, 'comment' => '<b>bold</b>']]);
        $withoutPins = $this->addFeedbackWithScreenshot(1);
        $controller = $this->get(FeedbackModuleController::class);

        $html = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$withPins]))->getBody();
        $plain = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$withoutPins]))->getBody();

        self::assertStringContainsString('Pin comments', $html);
        self::assertStringContainsString("<li value=\"1\">wrong logo<br />\nshould be the new one</li>", $html);
        self::assertStringContainsString('<li value="3">&lt;b&gt;bold&lt;/b&gt;</li>', $html);
        self::assertStringNotContainsString('Pin comments', $plain);
    }

    /**
     * Scenario: Video feedback
     * Scenario: Mixed list
     */
    #[Test]
    public function showPlaysTheVideoWithTheEndContextAndTheListMarksIt(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $file = $this->get(ScreenshotStorage::class)->store("\x00\x00\x00\x18ftypisom", 'mp4', new \DateTimeImmutable());
        $withVideo = $this->get(FeedbackRepository::class)->add(new Submission('With video', 'bug', Severity::Low, Source::Backend, 1, new \DateTimeImmutable(), context: ['module' => 'web_layout'], contextAtEnd: ['module' => 'web_list']), null, $file);
        $this->addFeedbackWithScreenshot(1);
        $controller = $this->get(FeedbackModuleController::class);

        $html = (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$withVideo]))->getBody();
        self::assertMatchesRegularExpression('#<video[^>]+preload="metadata"[^>]+src="[^"]*/opinion/video\?#', $html);
        self::assertMatchesRegularExpression('#<a[^>]+download="feedback-' . $withVideo . '\.mp4"#', $html);
        self::assertStringContainsString('Download video', $html);
        self::assertStringContainsString('At the end of the recording', $html);
        self::assertStringContainsString('web_list', $html);

        $list = (string)$controller->listAction($this->createRequest('opinion_feedback'))->getBody();
        self::assertSame(1, substr_count($list, 'title="With video"'));
        self::assertSame(1, substr_count($list, 'title="With screenshot"'));
    }

    /**
     * Scenario: Administrator
     * Scenario: Sender
     * Scenario: Editor opens a foreign screenshot
     * Scenario: Sender without access any more
     */
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

        $this->removeFeedbackPermission(3);
        $this->setUpBackendUser(3);
        $response = $this->requestScreenshot($ofReporter);
        self::assertSame(403, $response->getStatusCode(), 'sender without options.opinion.enable');
        self::assertNotSame(self::png(), (string)$response->getBody());
    }

    /**
     * Scenario: Seeking in the player
     * Scenario: Sender without access any more
     */
    #[Test]
    public function videoIsDeliveredToAdminsAndTheSenderOnlyWithRanges(): void
    {
        $video = "\x00\x00\x00\x18ftypisom" . str_repeat('0123456789', 10);
        $this->get(ScreenshotStorageInstaller::class)->install();
        $file = $this->get(ScreenshotStorage::class)->store($video, 'mp4', new \DateTimeImmutable());
        $ofReporter = $this->get(FeedbackRepository::class)->add(new Submission('With video', 'bug', Severity::Low, Source::Backend, 3, new \DateTimeImmutable()), null, $file);
        $ofAdmin = $this->addFeedbackWithScreenshot(1);
        self::assertSame('opinion_feedback', $this->get(Router::class)->getRoute('opinion_video')?->getOption('inheritAccessFromModule'), 'Core checks the module access');

        $response = $this->requestVideo($ofReporter);
        self::assertSame(200, $response->getStatusCode(), 'admin, foreign feedback');
        self::assertSame($video, (string)$response->getBody());
        self::assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        self::assertSame(404, $this->requestVideo($ofAdmin)->getStatusCode(), 'screenshot, no video');

        $response = $this->requestVideo($ofReporter, 'bytes=4-7');
        self::assertSame(206, $response->getStatusCode());
        self::assertSame('ftyp', (string)$response->getBody());
        self::assertSame('bytes 4-7/112', $response->getHeaderLine('Content-Range'));
        self::assertSame('4', $response->getHeaderLine('Content-Length'));
        self::assertSame(substr($video, 100), (string)$this->requestVideo($ofReporter, 'bytes=100-')->getBody(), 'open range');
        self::assertSame('6789', (string)$this->requestVideo($ofReporter, 'bytes=-4')->getBody(), 'suffix range');
        self::assertSame('bytes 110-111/112', $this->requestVideo($ofReporter, 'bytes=110-999')->getHeaderLine('Content-Range'), 'end beyond the file');
        $response = $this->requestVideo($ofReporter, 'bytes=200-');
        self::assertSame(416, $response->getStatusCode());
        self::assertSame('bytes */112', $response->getHeaderLine('Content-Range'));
        self::assertSame(200, $this->requestVideo($ofReporter, 'bytes=0-1,4-5')->getStatusCode(), 'several ranges get the whole file');

        $this->setUpBackendUser(3);
        self::assertSame(206, $this->requestVideo($ofReporter, 'bytes=0-')->getStatusCode(), 'sender');

        $this->setUpBackendUser(2);
        self::assertSame(403, $this->requestVideo($ofReporter)->getStatusCode(), 'without options.opinion.enable');

        $this->removeFeedbackPermission(3);
        $this->setUpBackendUser(3);
        self::assertSame(403, $this->requestVideo($ofReporter, 'bytes=0-')->getStatusCode(), 'sender without options.opinion.enable');
        self::assertSame(403, $this->requestVideo($ofReporter)->getStatusCode(), 'sender without options.opinion.enable');
    }

    /**
     * Scenario: Reading a conversation
     * Scenario: Markup in a message
     * Scenario: Two administrators
     * Scenario: Deleted backend user
     */
    #[Test]
    public function showRendersTheConversationOldestFirstEscapedAndMarksItReadForTheViewer(): void
    {
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $conversation = $this->get(ConversationRepository::class);
        $conversation->add($uid, 3, "First <script>alert(1)</script>\nsecond line", new \DateTimeImmutable('2026-10-02 09:00'));
        $conversation->add($uid, 1, 'Answer of the admin', new \DateTimeImmutable('2026-10-02 10:00'));
        $conversation->add($uid, 99, 'Message of a deleted user', new \DateTimeImmutable('2026-10-02 11:00'));

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertStringContainsString('First &lt;script&gt;alert(1)&lt;/script&gt;<br />', $html);
        self::assertStringNotContainsString('<script>alert(1)', $html);
        self::assertLessThan(strpos($html, 'Answer of the admin'), strpos($html, 'First &lt;script'), 'oldest first');
        self::assertStringContainsString('Rita Reporter', $html);
        self::assertStringContainsString('Deleted user', $html);
        self::assertStringContainsString('02.10.2026 10:00', $html);
        self::assertStringContainsString('opinion/feedback/save', $html);
        self::assertSame([], $conversation->findUnreadFeedbackUids(1, null), 'read for the viewer');
        self::assertSame([$uid], $conversation->findUnreadFeedbackUids(4, null), 'still unread for the other admin');
    }

    /**
     * Scenario: Sender answers
     * Scenario: Message by an administrator
     */
    #[Test]
    public function senderAndAdminWriteMessagesWithoutEmail(): void
    {
        $mailer = $this->spyOnMails();
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);

        $this->setUpBackendUser(3);
        $response = $this->postMessage($uid, "  Any news?\n ");
        self::assertSame(302, $response->getStatusCode());
        $this->setUpBackendUser(1);
        $this->postMessage($uid, 'We are on it.');

        $messages = $this->get(ConversationRepository::class)->findByFeedback($uid);
        self::assertSame(['Any news?', 'We are on it.'], array_column($messages, 'message'));
        self::assertSame([3, 1], array_map(intval(...), array_column($messages, 'be_user')));
        self::assertSame('The message was sent.', $this->getFlashMessages()[0] ?? null);
        self::assertSame([], $mailer->messages, 'no email to the sender or the recipients');
    }

    /**
     * Scenario: Foreign feedback
     * Scenario: Empty message
     */
    #[Test]
    public function invalidOrForeignMessagesAreRejected(): void
    {
        $foreign = $this->addFeedback('Feedback of the admin', '2026-10-01 10:00');
        $own = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $this->setUpBackendUser(3);

        $response = $this->postMessage($foreign, 'Not mine');
        self::assertSame(302, $response->getStatusCode(), 'rejected like an unknown uid');
        self::assertStringNotContainsString('opinion/feedback/show', $response->getHeaderLine('Location'), 'back to the list');
        self::assertSame([], $this->getFlashMessages(), 'nothing about the foreign feedback is revealed');
        $this->postMessage($own, "   \n  ");
        $this->postMessage($own, str_repeat('a', 5001));

        self::assertSame(0, $this->get(ConversationRepository::class)->countByFeedback($foreign));
        self::assertSame(0, $this->get(ConversationRepository::class)->countByFeedback($own));
        self::assertSame([
            'Nothing to save: write a message or change the status.',
            'The message is longer than 5000 characters. Nothing has been saved.',
        ], $this->getFlashMessages());

        $this->setUpBackendUser(2);
        self::assertSame(403, $this->postMessage($own, 'Without access')->getStatusCode());
    }

    /**
     * Scenario: Sender replies to closed feedback
     * Scenario: Admin writes to closed feedback
     */
    #[Test]
    public function replyOfTheSenderReopensClosedFeedbackAndOneOfTheAdminDoesNot(): void
    {
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $this->get(FeedbackRepository::class)->updateStatus($uid, 'closed');

        $this->postMessage($uid, 'Fixed.');
        self::assertSame('closed', $this->statusOf($uid), 'admin');

        $this->setUpBackendUser(3);
        $this->postMessage($uid, 'Still broken.');
        self::assertSame('in_progress', $this->statusOf($uid), 'sender');
    }

    /**
     * Scenario: Own reopen status
     * Scenario: Reopen status not usable
     */
    #[Test]
    public function replyReopensWithTheConfiguredStatus(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,waiting,closed,rejected';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['closedStatuses'] = 'closed,rejected';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['reopenStatus'] = 'waiting';
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $this->get(FeedbackRepository::class)->updateStatus($uid, 'rejected');
        $this->setUpBackendUser(3);

        $this->postMessage($uid, 'Why rejected?');
        self::assertSame('waiting', $this->statusOf($uid), 'own reopen status');

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['statuses'] = 'new,closed';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['closedStatuses'] = 'closed';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['reopenStatus'] = 'in_progress';
        $this->get(FeedbackRepository::class)->updateStatus($uid, 'closed');
        $this->postMessage($uid, 'Still broken.');
        self::assertSame('new', $this->statusOf($uid), 'reopen status not configured: the initial status');
    }

    /**
     * Scenario: Reading a conversation
     */
    #[Test]
    public function usersWithoutRealNameAreShownWithTheirUsername(): void
    {
        // TYPO3 stores a missing real name as empty string, not as null
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->insert('be_users', ['uid' => 5, 'username' => 'nameless', 'realName' => '', 'admin' => 1]);
        $uid = $this->get(FeedbackRepository::class)->add(new Submission('Feedback of nameless', 'bug', Severity::Low, Source::Backend, 5, new \DateTimeImmutable(), systemInfo: ['backendUser' => ['username' => 'nameless', 'realName' => '', 'email' => '']]), null);
        $this->get(ConversationRepository::class)->add($uid, 5, 'Answer of nameless', new \DateTimeImmutable());

        $show = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();
        $list = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();

        self::assertMatchesRegularExpression('#<strong>\s*nameless\s*</strong>#', $show, 'author of the message');
        self::assertMatchesRegularExpression('#· Backend · nameless#', $show, 'sender line');
        self::assertMatchesRegularExpression('#<td>nameless</td>#', $list, 'sender column');
    }

    /**
     * Scenario: Status and message together
     * Scenario: Nothing changed
     */
    #[Test]
    public function statusAndMessageAreSavedTogetherOrNotAtAll(): void
    {
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);

        $this->postSave($uid, ['status' => 'in_progress', 'message' => 'We are on it.']);
        self::assertSame('in_progress', $this->statusOf($uid));
        self::assertSame(1, $this->get(ConversationRepository::class)->countByFeedback($uid));
        self::assertSame(['The message and the status have been saved.'], $this->getFlashMessages());

        $this->postSave($uid, ['status' => 'done', 'message' => 'Lost?']);
        $this->postSave($uid, ['status' => 'closed', 'message' => str_repeat('a', 5001)]);
        self::assertSame('in_progress', $this->statusOf($uid), 'invalid form stores no status');
        self::assertSame(1, $this->get(ConversationRepository::class)->countByFeedback($uid), 'invalid form stores no message');

        $this->postSave($uid, ['status' => 'in_progress', 'message' => '  ']);
        self::assertSame([
            'The status is invalid and has not been changed.',
            'The message is longer than 5000 characters. Nothing has been saved.',
            'Nothing to save: write a message or change the status.',
        ], $this->getFlashMessages());
    }

    /**
     * Scenario: Admin answers and closes
     */
    #[Test]
    public function statusChosenByAnAdministratorWinsOverReopening(): void
    {
        // The administrator is the sender here; a closed feedback stays closed when it is kept in the form
        $uid = $this->addFeedback('Own feedback of the admin', '2026-10-01 10:00', 1);
        $this->get(FeedbackRepository::class)->updateStatus($uid, 'closed');

        $this->postSave($uid, ['status' => 'closed', 'message' => 'Note to myself']);

        self::assertSame('closed', $this->statusOf($uid));
        self::assertSame(1, $this->get(ConversationRepository::class)->countByFeedback($uid));
    }

    /**
     * Scenario: Switched off
     * Scenario: Message while switched off
     */
    #[Test]
    public function switchedOffConversationShowsNoThreadAndRejectsMessages(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['conversation'] = '0';
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $this->get(ConversationRepository::class)->add($uid, 3, 'Stored before switching off', new \DateTimeImmutable());

        $show = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();
        $list = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();
        $this->postSave($uid, ['status' => 'closed', 'message' => 'Not allowed']);

        self::assertStringNotContainsString('Stored before switching off', $show);
        self::assertStringNotContainsString('name="message"', $show);
        self::assertStringContainsString('name="status"', $show, 'the status can still be changed');
        self::assertStringNotContainsString('<th>Conversation</th>', $list);
        self::assertSame(['Conversations are switched off. Nothing has been saved.'], $this->getFlashMessages());
        self::assertSame('new', $this->statusOf($uid));
        self::assertSame(1, $this->get(ConversationRepository::class)->countByFeedback($uid), 'stored messages are kept');

        $this->postStatus($uid, 'closed');
        self::assertSame('closed', $this->statusOf($uid));
    }

    /**
     * Scenario: Collected details collapsed
     */
    #[Test]
    public function detailViewHasOneFormAndCollapsedDetails(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertSame(1, substr_count($html, 'opinion/feedback/save'), 'one form for message and status');
        self::assertSame(1, substr_count($html, 'name="status"'), 'the status is part of that form');
        self::assertSame(1, preg_match_all('#<button\s+type="submit"\s+class="btn btn-primary#', $html), 'one save button');
        self::assertGreaterThan(0, substr_count($html, '<details'));
        self::assertStringNotContainsString('<details open', $html);
    }

    /**
     * Scenario: New answer for the sender
     */
    #[Test]
    public function listMarksFeedbackWithUnreadMessages(): void
    {
        $answered = $this->addFeedback('Answered feedback', '2026-10-01 10:00', 3);
        $this->addFeedback('Quiet feedback', '2026-10-02 10:00', 3);
        $this->postMessage($answered, 'Can you send a screenshot?');
        $this->postMessage($answered, 'Or the URL?');
        $this->setUpBackendUser(3);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();

        self::assertSame(1, preg_match_all('#>New<span class="visually-hidden"\s*>: New messages</span#', $html), 'new badge');
        self::assertSame(1, preg_match_all('#<span class="visually-hidden">2 messages</span>#', $html), 'message count');
    }

    /**
     * Scenario: Foreign uid
     */
    #[Test]
    public function editorGetsNoForeignFeedback(): void
    {
        $foreign = $this->addFeedback('Feedback of the admin', '2026-10-01 10:00');
        $this->setUpBackendUser(3);

        $response = $this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$foreign]));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * Scenario: Forged redelivery
     * Scenario: Request by a non-admin
     */
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
        $this->postSave($own, ['status' => 'closed', 'message' => 'Close it']);
        self::assertSame(['Only administrators change the status. Nothing has been saved.'], $this->getFlashMessages());
        self::assertSame(0, $this->get(ConversationRepository::class)->countByFeedback($own), 'message not stored either');
        $record = $this->get(FeedbackRepository::class)->findByUid($own);
        self::assertSame(1, (int)($record['delivery_attempts'] ?? 0));
        self::assertSame('new', $record['status'] ?? null);
    }

    /**
     * Scenario: Granted module without TSconfig option
     */
    #[Test]
    public function moduleNeedsTheFeedbackPermission(): void
    {
        $this->setUpBackendUser(2);

        self::assertSame(403, $this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getStatusCode());
    }

    /**
     * Requirement: Module access (with enabled = 0 only administrators)
     */
    #[Test]
    public function disabledExtensionClosesTheModuleForEditorsOnly(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['enabled'] = '0';
        $controller = $this->get(FeedbackModuleController::class);

        self::assertSame(200, $controller->listAction($this->createRequest('opinion_feedback'))->getStatusCode());
        $this->setUpBackendUser(3);
        self::assertSame(403, $controller->listAction($this->createRequest('opinion_feedback'))->getStatusCode());
    }

    /**
     * Scenario: Own category in the filter
     * Scenario: Category no longer configured
     * Scenario: Removed category in the module
     */
    #[Test]
    public function ownCategoriesAreListedFilteredAndShownWithTheirLabels(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['categories'] = 'bug,question=Question & answer';
        $repository = $this->get(FeedbackRepository::class);
        $question = $repository->add(new Submission('A question about the teaser', 'question', Severity::Low, Source::Backend, 1, new \DateTimeImmutable()), null);
        // Category removed from the configuration after it was sent
        $idea = $repository->add(new Submission('An idea for the footer', 'idea', Severity::Low, Source::Backend, 1, new \DateTimeImmutable()), null);
        $controller = $this->get(FeedbackModuleController::class);

        $html = (string)$controller->listAction($this->createRequest('opinion_feedback'))->getBody();
        self::assertMatchesRegularExpression('#<option\s+value="question"#', $html);
        self::assertStringContainsString('Question &amp; answer', $html);
        self::assertDoesNotMatchRegularExpression('#<option\s+value="idea"#', $html, 'the filter offers configured categories only');
        self::assertStringContainsString('<td>Idea</td>', $html, 'a removed shipped category keeps its translation');

        $filtered = (string)$controller->listAction($this->createRequest('opinion_feedback', ['category' => 'question']))->getBody();
        self::assertStringContainsString('A question about the teaser', $filtered);
        self::assertStringNotContainsString('An idea for the footer', $filtered);
        self::assertStringContainsString('An idea for the footer', (string)$controller->listAction($this->createRequest('opinion_feedback', ['category' => 'idea']))->getBody(), 'unconfigured filter value ignored');

        self::assertStringContainsString('Question &amp; answer', (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$question]))->getBody());
        self::assertStringContainsString('Idea', (string)$controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$idea]))->getBody());
    }

    /**
     * Scenario: Invalid filter value
     * Scenario: Markup in the search term
     */
    #[Test]
    public function listCanBeFilteredAndKeepsTheFilterInItsLinks(): void
    {
        $this->addFeedback('Teaser is cropped', '2026-10-01 10:00');
        $closed = $this->addFeedback('Old bug', '2026-10-02 10:00');
        $this->get(FeedbackRepository::class)->updateStatus($closed, 'closed');

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

    /**
     * Scenario: Paging keeps the filter
     */
    #[Test]
    public function pagingKeepsTheFilter(): void
    {
        for ($i = 0; $i < 51; $i++) {
            $this->addFeedback('Bug ' . $i, '2026-10-01 10:00');
        }

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['search' => 'Bug']))->getBody();

        self::assertMatchesRegularExpression('#href="[^"]*page=2[^"]*search=Bug#', $html);
    }

    /**
     * Scenario: Editor searches
     */
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

    /**
     * Requirement: Filter state in the URL (kept when returning from the detail view)
     */
    #[Test]
    public function statusChangeReturnsWithTheFilter(): void
    {
        $uid = $this->addFeedback('Feedback', '2026-10-01 10:00');

        $response = $this->postSave($uid, ['status' => 'closed', 'filter' => ['search' => 'Feed', 'status' => 'bogus']]);

        self::assertStringContainsString('search=Feed', $response->getHeaderLine('Location'));
        self::assertStringNotContainsString('bogus', $response->getHeaderLine('Location'));
    }

    /**
     * Scenario: Unknown feedback
     */
    #[Test]
    public function showRedirectsToListForUnknownFeedback(): void
    {
        $response = $this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => '999']));

        self::assertSame(302, $response->getStatusCode());
    }

    /**
     * Scenario: All channels disabled
     */
    #[Test]
    public function redeliverLeavesSkippedFeedbackUndelivered(): void
    {
        $mailer = $this->spyOnMails();
        $uid = $this->addFeedback('Skipped feedback', '2026-10-01 10:00');
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($uid, DeliveryStatus::Skipped, 'no delivery channel enabled');

        // Forged: the detail view offers no button for skipped feedback
        $response = $this->get(FeedbackModuleController::class)->redeliverAction(
            $this->createRequest('opinion_feedback.redeliver')->withMethod('POST')->withParsedBody(['uid' => (string)$uid]),
        );

        self::assertSame(302, $response->getStatusCode());
        $record = $this->get(FeedbackRepository::class)->findByUid($uid);
        self::assertSame('skipped', $record['delivery_status'] ?? null);
        self::assertSame(1, (int)($record['delivery_attempts'] ?? 0));
        self::assertSame([], $mailer->messages, 'no email sent');
        self::assertSame(['This feedback cannot be delivered again.'], $this->getFlashMessages());
    }

    /**
     * Scenario: Editor with access
     */
    #[Test]
    public function editorWithTheOptionAndTheGrantedModuleGetsTheModule(): void
    {
        $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $backendUser = $this->setUpBackendUser(3);
        $backendUser->groupData['modules'] = 'opinion_feedback';

        self::assertTrue($this->get(ModuleProvider::class)->accessGranted('opinion_feedback', $backendUser));
        $response = $this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'));
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Feedback of the reporter', (string)$response->getBody());
    }

    /**
     * Scenario: Granted module without TSconfig option
     */
    #[Test]
    public function grantedModuleWithoutTheOptionDeniesAccess(): void
    {
        $this->addFeedback('Feedback of the editor', '2026-10-01 10:00', 2);
        $this->setUpBackendUser(2)->groupData['modules'] = 'opinion_feedback';
        $controller = $this->get(FeedbackModuleController::class);

        $list = $controller->listAction($this->createRequest('opinion_feedback'));
        self::assertSame(403, $list->getStatusCode());
        self::assertStringNotContainsString('Feedback of the editor', (string)$list->getBody());
        self::assertSame(403, $controller->showAction($this->createRequest('opinion_feedback.show', ['uid' => '1']))->getStatusCode());
    }

    /**
     * Scenario: Editor
     */
    #[Test]
    public function editorWithoutTheGrantedModuleDoesNotGetTheModule(): void
    {
        $backendUser = $this->setUpBackendUser(3);
        // May send feedback, but the module is not granted
        $backendUser->groupData['modules'] = '';

        self::assertFalse($this->get(ModuleProvider::class)->accessGranted('opinion_feedback', $backendUser));
    }

    /**
     * Scenario: Pagination
     */
    #[Test]
    public function thirdPageOfAHundredAndTwentyShowsTheLastTwenty(): void
    {
        $this->addNumberedFeedback(120);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['page' => '3']))->getBody();

        self::assertSame(20, preg_match_all('#Numbered feedback \d{3}#', $html));
        self::assertStringContainsString('Numbered feedback 001', $html, 'oldest on the last page');
        self::assertStringContainsString('Numbered feedback 020', $html);
        self::assertStringNotContainsString('Numbered feedback 021', $html);
        self::assertStringContainsString('Page 3 of 3', $html);
        self::assertMatchesRegularExpression('#href="[^"]*page=2[^"]*"\s*>Previous</a#', $html);
        self::assertStringNotContainsString('>Next</a', $html);
    }

    /**
     * Scenario: Page beyond the end
     */
    #[Test]
    public function pageBeyondTheEndShowsTheLastPage(): void
    {
        $this->addNumberedFeedback(120);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback', ['page' => '99']))->getBody();

        self::assertSame(20, preg_match_all('#Numbered feedback \d{3}#', $html));
        self::assertStringContainsString('Numbered feedback 001', $html);
        self::assertStringContainsString('Page 3 of 3', $html);
    }

    /**
     * Scenario: Sender with email address
     */
    #[Test]
    public function listShowsTheStoredSenderEmailAlsoAfterTheUserChanged(): void
    {
        $repository = $this->get(FeedbackRepository::class);
        $repository->add(new Submission('Sent before the change', 'bug', Severity::Low, Source::Backend, 1, new \DateTimeImmutable('2026-10-01 10:00'), systemInfo: ['backendUser' => ['username' => 'admin', 'realName' => 'Ada Admin', 'email' => 'ada.old@example.com']]), null);
        $repository->add(new Submission('Sent by a deleted user', 'bug', Severity::Low, Source::Backend, 99, new \DateTimeImmutable('2026-10-02 10:00'), systemInfo: ['backendUser' => ['username' => 'gone', 'realName' => 'Gone User', 'email' => 'gone@example.com']]), null);

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();

        self::assertStringContainsString('<a href="mailto:ada.old@example.com">ada.old@example.com</a>', $html);
        self::assertStringNotContainsString('mailto:admin@example.com', $html, 'not the current address of the user');
        self::assertStringContainsString('<a href="mailto:gone@example.com">gone@example.com</a>', $html);
    }

    /**
     * Scenario: Editor views own feedback
     */
    #[Test]
    public function editorSeesOwnFeedbackWithScreenshotAndDetailsButNoAdministration(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $screenshot = $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());
        $uid = $this->get(FeedbackRepository::class)->add(new Submission('Feedback of the reporter', 'bug', Severity::Low, Source::Frontend, 3, new \DateTimeImmutable(), clientInfo: ['screen' => ['width' => 2560]], context: ['pageUid' => 12]), $screenshot);
        $this->get(FeedbackRepository::class)->updateDeliveryStatus($uid, DeliveryStatus::Failed, 'email: FAILED Connection refused');
        $this->setUpBackendUser(3);

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertStringContainsString('Feedback of the reporter', $html);
        self::assertStringContainsString('/typo3/opinion/screenshot?', $html, 'screenshot');
        self::assertStringContainsString('screen.width', $html, 'collected details');
        self::assertStringContainsString('>New</span>', $html, 'processing status');
        self::assertStringNotContainsString('name="status"', $html, 'status not changeable');
        self::assertStringNotContainsString('Connection refused', $html, 'no delivery log');
        self::assertStringNotContainsString('attempt(s)', $html, 'no attempt count');
        self::assertStringNotContainsString('Deliver again', $html);
    }

    /**
     * Scenario: Messages of both sides
     */
    #[Test]
    public function threadShowsTheSenderOnTheStartSideAndOthersOnTheEndSide(): void
    {
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $conversation = $this->get(ConversationRepository::class);
        $conversation->add($uid, 3, 'Question of the sender', new \DateTimeImmutable('2026-10-02 09:00'));
        $conversation->add($uid, 1, 'Answer of the admin', new \DateTimeImmutable('2026-10-02 10:00'));

        $html = (string)$this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => (string)$uid]))->getBody();

        self::assertMatchesRegularExpression('#<li class="d-flex justify-content-start">(?:(?!</li>).)*Question of the sender#s', $html);
        self::assertMatchesRegularExpression('#<li class="d-flex justify-content-end">(?:(?!</li>).)*Answer of the admin#s', $html);
        self::assertLessThan(strpos($html, 'Answer of the admin'), strpos($html, 'Question of the sender'), 'oldest first');
        self::assertLessThan(strpos($html, 'opinion-conversation-message'), strpos($html, 'Answer of the admin'), 'form after the thread');
    }

    /**
     * Scenario: Feedback without messages
     */
    #[Test]
    public function feedbackWithoutMessagesHasAnEmptyConversationColumn(): void
    {
        $answered = $this->addFeedback('Answered feedback', '2026-10-01 10:00');
        $this->addFeedback('Quiet feedback', '2026-10-02 10:00');
        $this->get(ConversationRepository::class)->add($answered, 3, 'A reply', new \DateTimeImmutable());

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();

        self::assertSame(1, preg_match('#Quiet feedback.*?</tr>#s', $html, $row));
        self::assertStringNotContainsString('message', strtolower(strip_tags(substr($row[0], (int)strpos($row[0], '</a>')))), 'no message count');
        self::assertStringNotContainsString('text-bg-info', $row[0], 'no new badge');
        self::assertSame(1, preg_match('#Answered feedback.*?</tr>#s', $html, $answeredRow));
        self::assertStringContainsString('1 message', $answeredRow[0], 'the other row has its count');
    }

    /**
     * Scenario: Empty message
     */
    #[Test]
    public function emptyMessageOfTheSenderStoresNothingAndSaysSo(): void
    {
        $uid = $this->addFeedback('Feedback of the reporter', '2026-10-01 10:00', 3);
        $this->setUpBackendUser(3);

        $this->postMessage($uid, "   \n ");

        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush();
        self::assertCount(1, $messages);
        self::assertSame(ContextualFeedbackSeverity::INFO, $messages[0]->getSeverity());
        self::assertSame(0, $this->get(ConversationRepository::class)->countByFeedback($uid));
    }

    private function addNumberedFeedback(int $count): void
    {
        for ($number = 1; $number <= $count; $number++) {
            $this->addFeedback(sprintf('Numbered feedback %03d', $number), sprintf('2026-10-01 10:00 +%d minutes', $number));
        }
    }

    /**
     * Records the sent emails instead of sending them.
     */
    private function spyOnMails(): MailSpy
    {
        $spy = new MailSpy();
        $container = $this->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(MailerInterface::class, $spy);

        return $spy;
    }

    private function removeFeedbackPermission(int $userUid): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('be_users')->update('be_users', ['TSconfig' => ''], ['uid' => $userUid]);
    }

    private function postStatus(int $uid, string $status): ResponseInterface
    {
        return $this->postSave($uid, ['status' => $status]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function postSave(int $uid, array $fields): ResponseInterface
    {
        return $this->get(FeedbackModuleController::class)->saveAction(
            $this->createRequest('opinion_feedback.save')->withMethod('POST')->withParsedBody(['uid' => (string)$uid, ...$fields]),
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

    private function statusOf(int $uid): ?string
    {
        $status = $this->get(FeedbackRepository::class)->findByUid($uid)['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    private function postMessage(int $uid, string $message): ResponseInterface
    {
        return $this->postSave($uid, ['message' => $message]);
    }

    /**
     * @return list<string>
     */
    private function getFlashMessages(): array
    {
        return array_values(array_map(
            static fn($message): string => $message->getMessage(),
            $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessagesAndFlush(),
        ));
    }

    private function requestScreenshot(int $uid): ResponseInterface
    {
        return $this->get(FeedbackModuleController::class)->screenshotAction($this->createRequest('opinion_screenshot', ['uid' => (string)$uid]));
    }

    private function requestVideo(int $uid, string $range = ''): ResponseInterface
    {
        $request = $this->createRequest('opinion_video', ['uid' => (string)$uid]);

        return $this->get(FeedbackModuleController::class)->videoAction($range === '' ? $request : $request->withHeader('Range', $range));
    }

    /**
     * @param list<array{number: int, comment: string}> $pins
     */
    private function addFeedbackWithScreenshot(int $senderUid, array $pins = []): int
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $screenshot = $this->get(ScreenshotStorage::class)->store(self::png(), 'png', new \DateTimeImmutable());

        return $this->get(FeedbackRepository::class)->add(new Submission('With screenshot', 'bug', Severity::Low, Source::Backend, $senderUid, new \DateTimeImmutable(), pins: $pins), $screenshot);
    }

    private static function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
    }

    private function addFeedback(string $message, string $date, int $senderUid = 1): int
    {
        return $this->get(FeedbackRepository::class)->add(new Submission(
            message: $message,
            category: 'bug',
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
