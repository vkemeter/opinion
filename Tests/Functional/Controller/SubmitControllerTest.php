<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\Container;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\UploadedFile;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Controller\SubmitController;
use Vkemeter\Opinion\Repository\FeedbackRepository;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class SubmitControllerTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    private const string WEBM = "\x1A\x45\xDF\xA3\x9F\x42\x86\x81\x01\x42\x82\x84webm\x42\x87rest";

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

    /**
     * Scenario: Editor without TSconfig option
     */
    #[Test]
    public function editorWithoutTsConfigIsDenied(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->submit($this->validFields());

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($this->decode($response)['success']);
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Requirement: Access rules (editors with options.opinion.enable = 1)
     * Scenario: New submission
     */
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

    /**
     * Scenario: Administrator
     * Scenario: Subject
     */
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
        self::assertSame('[Opinion] [Bug/normal] The teaser image is cropped.', $email->getSubject());
    }

    /**
     * Scenario: Missing recipients
     */
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
        self::assertSame('email: FAILED No valid recipient configured in the extension configuration.', $this->get(FeedbackRepository::class)->findByUid(1)['delivery_log'] ?? null);
    }

    /**
     * Scenario: Submission without email address
     */
    #[Test]
    public function userWithoutEmailAddressIsToldToAddOne(): void
    {
        $this->setUpBackendUser(4);

        $response = $this->submit($this->validFields());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('no email address', $this->decode($response)['message']);
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Message too short
     * Scenario: Unknown category
     */
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

    /**
     * Scenario: Disabled
     */
    #[Test]
    public function videoWhileVideoIsDisabledIsRejected(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), null, self::WEBM);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('Videos are not accepted.', $this->decode($response)['errors']['video']);
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: WebM video
     */
    #[Test]
    public function webmVideoIsAcceptedAndStored(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['videoEnabled'] = '1';
        $this->get(ScreenshotStorageInstaller::class)->install();
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), null, self::WEBM);

        self::assertSame(200, $response->getStatusCode());
        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'video', 1);
        self::assertCount(1, $references);
        self::assertSame('webm', $references[0]->getOriginalFile()->getExtension());
    }

    /**
     * Scenario: Screenshot storage missing
     */
    #[Test]
    public function videoWithoutStorageIsRejectedWithServiceUnavailable(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['videoEnabled'] = '1';
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), null, self::WEBM);

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('without a video', (string)$this->decode($response)['message']);
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Disguised video
     */
    #[Test]
    public function disguisedVideoIsRejected(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['videoEnabled'] = '1';
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), null, 'just a text file');

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['video'], array_keys($this->decode($response)['errors']));
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Screenshot and video
     */
    #[Test]
    public function screenshotAndVideoTogetherAreRejected(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['videoEnabled'] = '1';
        $this->get(ScreenshotStorageInstaller::class)->install();
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), base64_decode(self::PNG_BASE64, true), self::WEBM);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['video'], array_keys($this->decode($response)['errors']));
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Screenshot storage missing
     */
    #[Test]
    public function screenshotWithoutStorageIsRejectedWithServiceUnavailable(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), base64_decode(self::PNG_BASE64, true));

        self::assertSame(503, $response->getStatusCode());
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Writing the screenshot fails
     */
    #[Test]
    public function failingScreenshotWriteIsRejectedWithServiceUnavailable(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $this->freezeClock('2030-06-15 12:00:00');
        // A file named like the year folder makes creating the dated folder fail; no other test writes into 2030
        $blocker = Environment::getVarPath() . '/opinion/2030';
        touch($blocker);
        $this->setUpBackendUser(1);

        try {
            $response = $this->submit($this->validFields(), base64_decode(self::PNG_BASE64, true));
        } finally {
            unlink($blocker);
        }

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('The screenshot cannot be stored at the moment. Please send your feedback without a screenshot.', $this->decode($response)['message']);
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Mail transport down
     * Scenario: Stored but not delivered
     */
    #[Test]
    public function unreachableMailServerKeepsTheFeedbackWithItsScreenshot(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = '127.0.0.1:1';
        $this->get(ScreenshotStorageInstaller::class)->install();
        $this->setUpBackendUser(1);

        $response = $this->submit($this->validFields(), base64_decode(self::PNG_BASE64, true));
        $body = $this->decode($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($body['success']);
        self::assertFalse($body['delivered']);
        self::assertSame('Your feedback has been saved, but could not be delivered. The team will see it in the backend.', $body['message']);
        $record = $this->get(FeedbackRepository::class)->findByUid(1);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertStringContainsString('email: FAILED', (string)($record['delivery_log'] ?? ''));
        $references = $this->get(FileRepository::class)->findByRelation(FeedbackRepository::TABLE, 'screenshot', 1);
        self::assertCount(1, $references);
        self::assertSame(base64_decode(self::PNG_BASE64, true), $references[0]->getOriginalFile()->getContents());
    }

    /**
     * Scenario: Limit reached
     * Scenario: Invalid submissions do not count
     */
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

    /**
     * Scenario: Extension disabled
     */
    #[Test]
    public function disabledExtensionDeniesEvenAdmins(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['enabled'] = '0';
        $this->setUpBackendUser(1);

        self::assertSame(403, $this->submit($this->validFields())->getStatusCode());
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Own configured category
     */
    #[Test]
    public function ownConfiguredCategoryIsStored(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['categories'] = 'bug,question=Question';
        $this->setUpBackendUser(1);

        self::assertSame(200, $this->submit(['category' => 'question'] + $this->validFields())->getStatusCode());
        self::assertSame('question', $this->get(FeedbackRepository::class)->findByUid(1)['category'] ?? null);
    }

    /**
     * Scenario: Context is a JSON list
     */
    #[Test]
    public function contextAsJsonListIsRejected(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->submit(['context' => '[12]'] + $this->validFields());

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['context'], array_keys($this->decode($response)['errors']));
        self::assertSame(0, $this->countFeedback());
    }

    /**
     * Scenario: Failed delivery, still new
     */
    #[Test]
    public function failedDeliveryLeavesTheProcessingStatusNew(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['recipients'] = '';
        $this->setUpBackendUser(1);

        $this->submit($this->validFields());

        $record = $this->get(FeedbackRepository::class)->findByUid(1);
        self::assertSame('failed', $record['delivery_status'] ?? null);
        self::assertSame('new', $record['status'] ?? null);
    }

    /**
     * Scenario: Module changed during recording
     */
    #[Test]
    public function contextAtEndIsValidatedLikeTheContext(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['videoEnabled'] = '1';
        $this->get(ScreenshotStorageInstaller::class)->install();
        $this->setUpBackendUser(1);
        $fields = ['source' => 'backend', 'context' => '{"module":"web_layout"}'] + $this->validFields();

        $this->submit(['contextAtEnd' => '{"module":"web_list","url":"https://evil.example/typo3/module/web/list?token=abc&id=1"}'] + $fields, null, self::WEBM);
        $this->submit(['contextAtEnd' => '{"module":"not_a_module"}'] + $fields, null, self::WEBM);

        self::assertSame(['module' => 'web_layout'], json_decode((string)($this->get(FeedbackRepository::class)->findByUid(1)['context'] ?? ''), true));
        $contextAtEnd = (array)json_decode((string)($this->get(FeedbackRepository::class)->findByUid(1)['context_at_end'] ?? ''), true);
        // MySQL's JSON column does not keep the key order
        ksort($contextAtEnd);
        self::assertSame(['module' => 'web_list', 'url' => '/typo3/module/web/list?id=1'], $contextAtEnd);
        self::assertSame([], json_decode((string)($this->get(FeedbackRepository::class)->findByUid(2)['context_at_end'] ?? '[]'), true) ?? [], 'unregistered module dropped');
    }

    private function freezeClock(string $now): void
    {
        $container = $this->getContainer();
        self::assertInstanceOf(Container::class, $container);
        $container->set(ClockInterface::class, new MockClock($now));
    }

    /**
     * @param array<string, string> $fields
     */
    private function submit(array $fields, ?string $screenshot = null, ?string $video = null): ResponseInterface
    {
        $files = [];
        foreach (['screenshot' => [$screenshot, 'image/png'], 'video' => [$video, 'video/mp4']] as $field => [$content, $mediaType]) {
            if ($content !== null) {
                $file = (string)tempnam(sys_get_temp_dir(), 'opinion');
                file_put_contents($file, $content);
                $files[$field] = new UploadedFile($file, strlen($content), UPLOAD_ERR_OK, $field, $mediaType);
            }
        }
        $request = new ServerRequest('https://example.com/typo3/ajax/opinion/submit', 'POST')->withParsedBody($fields)->withUploadedFiles($files);

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
