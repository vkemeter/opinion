<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\Route;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Controller\FeedbackModuleController;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class FeedbackModuleControllerTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

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

        $html = (string)$this->get(FeedbackModuleController::class)->listAction($this->createRequest('opinion_feedback'))->getBody();

        self::assertStringContainsString('2 feedback entries', $html);
        self::assertLessThan(strpos($html, 'First'), strpos($html, 'Second feedback text'));
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('Ada Admin', $html);
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
    }

    #[Test]
    public function showRedirectsToListForUnknownFeedback(): void
    {
        $response = $this->get(FeedbackModuleController::class)->showAction($this->createRequest('opinion_feedback.show', ['uid' => '999']));

        self::assertSame(302, $response->getStatusCode());
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
