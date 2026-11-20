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
    public function moduleIsRegisteredForAdminsOnly(): void
    {
        $moduleProvider = $this->get(ModuleProvider::class);

        self::assertTrue($moduleProvider->isModuleRegistered('opinion_feedback'));
        self::assertTrue($moduleProvider->accessGranted('opinion_feedback', $this->setUpBackendUser(1)));
        self::assertFalse($moduleProvider->accessGranted('opinion_feedback', $this->setUpBackendUser(3)));
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

    private function addFeedback(string $message, string $date): int
    {
        return $this->get(FeedbackRepository::class)->add(new Submission(
            message: $message,
            category: Category::Bug,
            severity: Severity::High,
            source: Source::Frontend,
            backendUserUid: 1,
            createdAt: new \DateTimeImmutable($date),
            clientInfo: ['screen' => ['width' => 2560]],
            systemInfo: ['typo3Version' => '14.3.0', 'backendUser' => ['username' => 'admin', 'realName' => 'Ada Admin', 'email' => 'admin@example.com']],
            context: ['pageUid' => 12, 'pageInfo' => ['imagesOnPage' => ['total' => 3]]],
        ), null);
    }
}
