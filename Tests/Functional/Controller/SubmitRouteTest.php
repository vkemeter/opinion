<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Http\Application;
use TYPO3\CMS\Backend\Http\RequestHandler;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * The submit endpoint as TYPO3 dispatches it, not the controller called directly.
 */
final class SubmitRouteTest extends FunctionalTestCase
{
    private const string ROUTE = 'ajax_opinion_submit';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'opinion' => ['enabled' => '1', 'recipients' => 'team@example.com'],
        ],
        'MAIL' => ['transport' => 'null'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    /**
     * Scenario: Request without backend session
     */
    #[Test]
    public function requestWithoutBackendSessionIsUnauthorized(): void
    {
        $request = $this->createRequest('?token=0123456789abcdef0123456789abcdef01234567');

        $response = $this->get(Application::class)->handle($request);

        self::assertSame(401, $response->getStatusCode(), $response->getHeaderLine('Location'));
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Request without backend session
     */
    #[Test]
    public function invalidRequestTokenIsUnauthorized(): void
    {
        $this->setUpBackendUser(1);

        self::assertSame(401, $this->dispatch('?token=0123456789abcdef0123456789abcdef01234567')->getStatusCode());
        self::assertSame(401, $this->dispatch('')->getStatusCode(), 'missing token');
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Frontend uses the backend route
     */
    #[Test]
    public function validRequestTokenIsAccepted(): void
    {
        $this->setUpBackendUser(1);
        $token = $this->get(FormProtectionFactory::class)->createForType('backend')->generateToken('route', self::ROUTE);

        $response = $this->dispatch('?token=' . $token);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(1, $this->countFeedback());
    }

    private function dispatch(string $query): ResponseInterface
    {
        $request = $this->createRequest($query);
        $request = $request->withAttribute('route', $this->get(Router::class)->matchRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(RequestHandler::class)->handle($request);
    }

    private function createRequest(string $query): ServerRequest
    {
        $request = new ServerRequest('https://example.com/typo3/ajax/opinion/submit' . $query, 'POST', 'php://input', [], [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
            'SCRIPT_NAME' => '/index.php',
            'REQUEST_URI' => '/typo3/ajax/opinion/submit' . $query,
            'REMOTE_ADDR' => '127.0.0.1',
        ]);
        parse_str(ltrim($query, '?'), $queryParams);
        $request = $request
            ->withQueryParams($queryParams)
            ->withParsedBody(['message' => 'The teaser image is cropped.', 'category' => 'bug', 'severity' => 'normal', 'source' => 'backend'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function countFeedback(): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(FeedbackRepository::TABLE)->count('*', FeedbackRepository::TABLE, []);
    }
}
