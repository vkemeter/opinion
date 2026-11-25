<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Functional\Delivery;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Vkemeter\Opinion\Configuration\ConfiguredValueList;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Delivery\EmailDeliveryChannel;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Link\LinkableHosts;
use Vkemeter\Opinion\Storage\ScreenshotStorage;
use Vkemeter\Opinion\Storage\ScreenshotStorageInstaller;

final class EmailDeliveryChannelTest extends FunctionalTestCase
{
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected array $coreExtensionsToLoad = ['adminpanel'];

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
        $this->setBackendRequest();
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
        self::assertSame('[Opinion] [Content/low] Teaser <b>image</b> is cropped.', $email->getSubject());

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
    public function ownAndRemovedCategoriesAreLabelledAlsoInTheSubject(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['opinion']['categories'] = 'bug,question=Question & answer';
        $channel = $this->get(EmailDeliveryChannel::class);
        $configuration = new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180, categories: ConfiguredValueList::parse('bug,question=Question & answer', []));

        $channel->deliver($this->createSubmission(category: 'question'), null, $configuration);
        $email = $this->getSentEmail();
        self::assertSame('[Opinion] [Question & answer/low] Teaser <b>image</b> is cropped.', $email->getSubject());
        self::assertStringContainsString('Question &amp; answer', (string)$email->getHtmlBody());
        self::assertStringContainsString('Question & answer', (string)$email->getTextBody());

        // Removed from the configuration after it was sent: the shipped translation stays
        $channel->deliver($this->createSubmission(category: 'idea'), null, $configuration);
        self::assertStringContainsString('Idea', (string)$this->getSentEmail()->getHtmlBody());
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
    public function videoIsLinkedNeverAttachedAndTheEndContextShownWhenItDiffers(): void
    {
        $this->get(ScreenshotStorageInstaller::class)->install();
        $video = $this->get(ScreenshotStorage::class)->store("\x00\x00\x00\x18ftypisom" . str_repeat('0', 8 * 1024 * 1024), 'mp4', new \DateTimeImmutable());
        $base = new Submission('The menu closes too early.', 'bug', Severity::Normal, Source::Backend, 2, new \DateTimeImmutable(), context: ['module' => 'web_layout'], contextAtEnd: ['module' => 'web_list'], backendUrl: 'https://example.com/typo3');

        $this->get(EmailDeliveryChannel::class)->deliver($base->withStoredFeedback(42, $video), null, $this->configuration());

        $email = $this->getSentEmail();
        self::assertSame([], $email->getAttachments());
        $link = 'https://example.com/typo3/module/opinion/feedback/show?uid=42';
        self::assertStringContainsString('Video (8.0 MB) in the backend: <a href="' . $link . '">', (string)$email->getHtmlBody());
        self::assertStringContainsString('Video (8.0 MB) in the backend: ' . $link, (string)$email->getTextBody());
        self::assertStringContainsString('At the end of the recording', (string)$email->getTextBody());
        self::assertStringContainsString('web_list', (string)$email->getTextBody());

        $sameModule = new Submission('The menu closes too early.', 'bug', Severity::Normal, Source::Backend, 2, new \DateTimeImmutable(), context: ['module' => 'web_layout'], contextAtEnd: ['module' => 'web_layout']);
        $this->get(EmailDeliveryChannel::class)->deliver($sameModule->withStoredFeedback(43, $video), null, $this->configuration());
        self::assertStringNotContainsString('At the end of the recording', (string)$this->getSentEmail()->getTextBody());
    }

    #[Test]
    public function reportedUrlIsSentOnce(): void
    {
        $this->setBackendRequest();
        $this->get(SiteWriter::class)->write('main', ['rootPageId' => 1, 'base' => 'https://example.com/', 'languages' => [['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/']]]);

        $this->get(EmailDeliveryChannel::class)->deliver(
            $this->createSubmission(['url' => 'https://example.com/typo3/module/web/layout?id=1'], ['pageUid' => 1, 'url' => '/typo3/module/web/layout?id=1']),
            null,
            $this->configuration(),
        );

        $email = $this->getSentEmail();
        $html = (string)$email->getHtmlBody();
        self::assertStringContainsString('<a href="https://example.com/typo3/module/web/layout?id=1">', $html);
        self::assertSame(2, substr_count($html, '/typo3/module/web/layout?id=1'), 'Once as href and once as text, not in the tables');
        self::assertSame(1, substr_count((string)$email->getTextBody(), '/typo3/module/web/layout?id=1'));
    }

    #[Test]
    public function reportedUrlOfAHostWithoutSiteIsNotLinked(): void
    {
        // The request host is not trusted in the email: the sender sets it
        $this->setBackendRequest();

        $this->get(EmailDeliveryChannel::class)->deliver($this->createSubmission(['url' => 'https://example.com/login']), null, $this->configuration());

        $html = (string)$this->getSentEmail()->getHtmlBody();
        self::assertStringContainsString('https://example.com/login', $html);
        self::assertStringNotContainsString('href="https://example.com/login', $html);
    }

    #[Test]
    public function brokenTransportThrows(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = '127.0.0.1:1';

        $this->expectException(\Throwable::class);
        new EmailDeliveryChannel(new \TYPO3\CMS\Core\Mail\Mailer(), new \Vkemeter\Opinion\Formatting\DataFlattener(), $this->get(ScreenshotStorage::class), $this->get(LinkableHosts::class), $this->get(\Vkemeter\Opinion\Formatting\ConfiguredLabels::class))->deliver($this->createSubmission(), null, $this->configuration());
    }

    private function setBackendRequest(): void
    {
        $request = new ServerRequest('https://example.com/typo3/', 'POST', null, [], ['HTTP_HOST' => 'example.com', 'HTTPS' => 'on', 'SCRIPT_NAME' => '/typo3/index.php'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function getSentEmail(): FluidEmail
    {
        $email = $this->get(MailerInterface::class)->getSentMessage()?->getOriginalMessage();
        self::assertInstanceOf(FluidEmail::class, $email);

        return $email;
    }

    /**
     * @param array<string, mixed> $clientInfo
     * @param array<string, mixed> $context
     */
    private function createSubmission(array $clientInfo = ['screen' => ['width' => 2560, 'height' => 1440], 'online' => true], array $context = ['pageUid' => 12], string $category = 'content'): Submission
    {
        return new Submission(
            message: 'Teaser <b>image</b> is cropped.',
            category: $category,
            severity: Severity::Low,
            source: Source::Frontend,
            backendUserUid: 2,
            createdAt: new \DateTimeImmutable('2026-10-09 12:00:00'),
            clientInfo: $clientInfo,
            systemInfo: [
                'typo3Version' => '14.3.0',
                'backendUser' => ['uid' => 2, 'username' => 'editor', 'realName' => 'Eddie Editor', 'email' => 'editor@example.com'],
            ],
            context: $context,
        );
    }

    private function configuration(): OpinionConfiguration
    {
        return new OpinionConfiguration(true, ['team@example.com', 'dev@example.com'], '[Opinion]', 180);
    }
}
