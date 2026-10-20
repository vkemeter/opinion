<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Controller\SubmitController;
use Vkemeter\Opinion\Repository\FeedbackRepository;

final class SubmitControllerTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'opinion' => ['enabled' => '1', 'recipients' => 'team@example.com', 'subjectPrefix' => '[Opinion]', 'retentionDays' => '180'],
        ],
        'MAIL' => ['transport' => 'null'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
    }

    #[Test]
    public function editorWithoutTsConfigIsDenied(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->submit($this->validFields());

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->decode($response)['success']);
        self::assertSame(0, $this->countFeedback());
    }

    #[Test]
    public function editorWithTsConfigCanSubmit(): void
    {
        $this->setUpBackendUser(3);

        $response = $this->submit($this->validFields());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decode($response)['success']);
        self::assertSame(1, $this->countFeedback());
        self::assertSame(3, (int)($this->get(FeedbackRepository::class)->findByUid(1)['be_user'] ?? 0));
    }

    #[Test]
    public function adminCanSubmit(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields());

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decode($response)['delivered']);
        self::assertSame('sent', $this->get(FeedbackRepository::class)->findByUid(1)['delivery_status'] ?? null);
        $email = $this->get(MailerInterface::class)->getSentMessage()?->getOriginalMessage();
        self::assertInstanceOf(FluidEmail::class, $email);
        self::assertSame('[Opinion] [bug/normal] The teaser image is cropped.', $email->getSubject());
    }

    #[Test]
    public function missingRecipientsStillStoreTheFeedback(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['recipients'] = '';
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields());
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertFalse($body['delivered']);
        self::assertSame('failed', $this->get(FeedbackRepository::class)->findByUid(1)['delivery_status'] ?? null);
    }

    #[Test]
    public function userWithoutEmailAddressIsToldToAddOne(): void
    {
        $this->setUpBackendUser(4);

        $response = $this->submit($this->validFields());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('no email address', $this->decode($response)['message']);
        self::assertSame(0, $this->countFeedback());
    }

    #[Test]
    public function invalidInputIsRejectedWithTranslatedFieldErrors(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->submit([...$this->validFields(), 'message' => 'short', 'category' => 'praise']);
        $body = $this->decode($response);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($body['success']);
        self::assertSame(['message', 'category'], array_keys($body['errors']));
        self::assertSame('Please describe the problem in at least 10 characters.', $body['errors']['message']);
        self::assertSame(0, $this->countFeedback());
    }

    #[Test]
    public function screenshotWithoutStorageIsRejectedWithServiceUnavailable(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), (string)base64_decode(self::PNG_BASE64, true));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(0, $this->countFeedback());
    }

    #[Test]
    public function submissionsAboveTheRateLimitAreRejected(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['rateLimit'] = '2';
        // The limiter state survives between the tests of this class
        $this->get(CacheManager::class)->getCache('ratelimiter')->flush();
        $this->setUpBackendUser(3);

        self::assertSame(200, $this->submit($this->validFields())->getStatusCode());
        self::assertSame(422, $this->submit(['message' => ''] + $this->validFields())->getStatusCode(), 'invalid submissions do not count');
        self::assertSame(200, $this->submit($this->validFields())->getStatusCode());
        $response = $this->submit($this->validFields());

        self::assertSame(429, $response->getStatusCode());
        self::assertFalse($this->decode($response)['success']);
        self::assertStringContainsString('try again later', (string)$this->decode($response)['message']);
        self::assertGreaterThan(0, (int)$response->getHeaderLine('Retry-After'));
        self::assertSame(2, $this->countFeedback());

        // The limit is per user
        $this->setUpBackendUser(1);
        self::assertSame(200, $this->submit($this->validFields())->getStatusCode());
    }

    #[Test]
    public function disabledExtensionDeniesEvenAdmins(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['enabled'] = '0';
        $this->setUpBackendUser(1);

        self::assertSame(403, $this->submit($this->validFields())->getStatusCode());
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields, ?string $screenshot = null): ResponseInterface
    {
        $request = new ServerRequest('https://example.com/typo3/ajax/opinion/submit', 'POST')->withParsedBody($fields);
        if ($screenshot !== null) {
            $file = (string)tempnam(sys_get_temp_dir(), 'opinion');
            file_put_contents($file, $screenshot);
            $request = $request->withUploadedFiles(['screenshot' => new UploadedFile($file, strlen($screenshot), UPLOAD_ERR_OK, 'screenshot.png', 'image/png')]);
        }

        return $this->get(SubmitController::class)->submitAction($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $body = json_decode((string)$response->getBody(), true);
        self::assertIsArray($body);

        return $body;
    }

    private function countFeedback(): int
    {
        return $this->get(ConnectionPool::class)->getConnectionForTable(FeedbackRepository::TABLE)->count('*', FeedbackRepository::TABLE, []);
    }

    /**
     * @return array<string, string>
     */
    private function validFields(): array
    {
        return [
            'message' => 'The teaser image is cropped.',
            'category' => 'bug',
            'severity' => 'normal',
            'source' => 'frontend',
            'clientInfo' => '{"language":"de"}',
        ];
    }
}
