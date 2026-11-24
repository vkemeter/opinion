<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Delivery;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\EmailDeliveryChannel;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class EmailDeliveryChannelTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel', 'setup'];

    protected array $testExtensionsToLoad = ['vkemeter/opinion'];

    protected array $configurationToUseInTestInstance = [
        'MAIL' => [
            'transport' => 'null',
            'defaultMailFromAddress' => 'noreply@example.com',
            'defaultMailFromName' => 'TYPO3',
        ],
    ];

    #[Test]
    public function deliverAttachesTheScreenshotAlsoForEditorsWithoutFileMount(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->setUpBackendUser(3);
        // As in the submit request: storages created in the backend evaluate the editor's file permissions
        $request = new ServerRequest('https://example.com/typo3/', 'POST', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $this->get(ScreenshotStorageInstaller::class)->install();
        $screenshot = $this->get(ScreenshotStorage::class)->store(base64_decode(self::PNG_BASE64, true), 'png', new \DateTimeImmutable());

        $result = $this->get(EmailDeliveryChannel::class)->deliver($this->createSubmission(), $screenshot, $this->configuration());

        self::assertTrue($result->success, $result->message);
        self::assertContains('screenshot.png', array_map(static fn($part): ?string => $part->getFilename(), $this->getSentEmail()->getAttachments()));
    }

    #[Test]
    public function deliverSendsMailWithAllDetailsAndScreenshot(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $screenshot = $this->get(ScreenshotStorage::class)->store(base64_decode(self::PNG_BASE64, true), 'png', new \DateTimeImmutable());

        $result = $this->get(EmailDeliveryChannel::class)->deliver($this->createSubmission(), $screenshot, $this->configuration());

        self::assertTrue($result->success, $result->message);
        $email = $this->getSentEmail();
        self::assertSame(['team@example.com', 'dev@example.com'], array_map(static fn(Address $address): string => $address->getAddress(), $email->getTo()));
        self::assertSame('editor@example.com', $email->getReplyTo()[0]->getAddress());
        self::assertSame('Eddie Editor', $email->getReplyTo()[0]->getName());
        self::assertSame('noreply@example.com', $email->getFrom()[0]->getAddress());
        self::assertSame('[Opinion] [content/low] Teaser <b>image</b> is cropped.', $email->getSubject());

        $html = (string)$email->getHtmlBody();
        self::assertStringContainsString('Teaser &lt;b&gt;image&lt;/b&gt; is cropped.', $html);
        self::assertStringNotContainsString('<b>image</b>', $html);
        self::assertStringContainsString('cid:screenshot', $html);
        self::assertStringContainsString('screen.width', $html);
        self::assertStringContainsString('2560', $html);
        self::assertStringContainsString('14.3.0', $html);
        self::assertStringContainsString('pageUid', $html);
        self::assertStringContainsString('Teaser <b>image</b> is cropped.', (string)$email->getTextBody());

        $attachmentNames = array_map(static fn($part): ?string => $part->getFilename(), $email->getAttachments());
        self::assertContains('screenshot', $attachmentNames);
        self::assertContains('screenshot.png', $attachmentNames);
    }

    #[Test]
    public function deliverWithoutScreenshotSendsMailWithoutImage(): void
    {
        $result = $this->get(EmailDeliveryChannel::class)->deliver($this->createSubmission(), null, $this->configuration());

        self::assertTrue($result->success);
        self::assertStringNotContainsString('cid:screenshot', (string)$this->getSentEmail()->getHtmlBody());
        self::assertSame([], $this->getSentEmail()->getAttachments());
    }

    #[Test]
    public function reportedUrlIsSentOnce(): void
    {
        $submission = $this->createSubmission();
        $submission = new Submission(
            $submission->message,
            $submission->category,
            $submission->severity,
            Source::Backend,
            $submission->backendUserUid,
            $submission->createdAt,
            ['url' => 'https://example.com/typo3/module/web/layout?id=1'],
            $submission->systemInfo,
            ['pageUid' => 1, 'url' => '/typo3/module/web/layout?id=1'],
        );

        $this->get(EmailDeliveryChannel::class)->deliver($submission, null, $this->configuration());

        $email = $this->getSentEmail();
        $html = (string)$email->getHtmlBody();
        self::assertStringContainsString('<a href="https://example.com/typo3/module/web/layout?id=1">', $html);
        self::assertSame(2, substr_count($html, '/typo3/module/web/layout?id=1'), 'Once as href and once as text, not in the tables');
        self::assertSame(1, substr_count((string)$email->getTextBody(), '/typo3/module/web/layout?id=1'));
    }

    #[Test]
    public function brokenTransportThrows(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = '127.0.0.1:1';

        $this->expectException(\Throwable::class);
        new EmailDeliveryChannel(new \TYPO3\CMS\Core\Mail\Mailer(), new \Vkemeter\Opinion\Formatting\DataFlattener(), $this->get(ScreenshotStorage::class))->deliver($this->createSubmission(), null, $this->configuration());
    }

    private function getSentEmail(): FluidEmail
    {
        $email = $this->get(MailerInterface::class)->getSentMessage()?->getOriginalMessage();
        self::assertInstanceOf(FluidEmail::class, $email);

        return $email;
    }

    private function createSubmission(): Submission
    {
        return new Submission(
            message: 'Teaser <b>image</b> is cropped.',
            category: Category::Content,
            severity: Severity::Low,
            source: Source::Frontend,
            backendUserUid: 2,
            createdAt: new \DateTimeImmutable('2026-10-09 12:00:00'),
            clientInfo: ['screen' => ['width' => 2560, 'height' => 1440], 'online' => true],
            systemInfo: [
                'typo3Version' => '14.3.0',
                'backendUser' => ['uid' => 2, 'username' => 'editor', 'realName' => 'Eddie Editor', 'email' => 'editor@example.com'],
            ],
            context: ['pageUid' => 12],
        );
    }

    private function configuration(): OpinionConfiguration
    {
        return new OpinionConfiguration(true, ['team@example.com', 'dev@example.com'], '[Opinion]', 180);
    }
}
