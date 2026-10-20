<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Tests\Unit\Factory;

use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Vkemeter\Opinion\Collection\CollectableField;
use Vkemeter\Opinion\Collection\CollectionFilter;
use Vkemeter\Opinion\Collector\ClientInfoSanitizer;
use Vkemeter\Opinion\Collector\SchemaSanitizer;
use Vkemeter\Opinion\Collector\SystemInfoCollector;
use Vkemeter\Opinion\Collector\UrlParameterFilter;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Context\ContextProviderInterface;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Factory\InvalidSubmissionException;
use Vkemeter\Opinion\Factory\SubmissionFactory;
use Vkemeter\Opinion\Validation\SubmissionValidator;

final class SubmissionFactoryTest extends UnitTestCase
{
    #[Test]
    public function createFromRequestBuildsSubmission(): void
    {
        $request = $this->createRequest([
            'message' => 'The teaser image is cropped.',
            'category' => 'content',
            'severity' => 'low',
            'source' => 'frontend',
            'clientInfo' => '{"language":"de","cookie":"be_typo_user=abc"}',
            'context' => '{"pageUid":12}',
        ]);

        $submission = $this->createSubject([])->createFromRequest($request, $this->createBackendUser());

        self::assertSame('The teaser image is cropped.', $submission->message);
        self::assertSame(Category::Content, $submission->category);
        self::assertSame(Severity::Low, $submission->severity);
        self::assertSame(Source::Frontend, $submission->source);
        self::assertSame(7, $submission->backendUserUid);
        self::assertSame('2026-10-09T12:00:00+00:00', $submission->createdAt->format(\DateTimeInterface::ATOM));
        self::assertSame(['language' => 'de'], $submission->clientInfo);
        self::assertNull($submission->screenshot);
    }

    #[Test]
    public function systemInfoIsCollectedOnTheServerNotTakenFromTheBody(): void
    {
        $request = $this->createRequest([
            ...$this->validFields(),
            'systemInfo' => '{"backendUser":{"username":"admin"}}',
            'backendUserUid' => '1',
        ], ['REMOTE_ADDR' => '192.168.10.20']);

        $submission = $this->createSubject([])->createFromRequest($request, $this->createBackendUser());

        self::assertSame(7, $submission->backendUserUid);
        self::assertSame('editor', $submission->systemInfo['backendUser']['username']);
        self::assertSame('editor@example.com', $submission->systemInfo['backendUser']['email']);
        self::assertSame('192.168.10.0', $submission->systemInfo['ipAddress']);
        self::assertSame('2026-10-09T12:00:00+00:00', $submission->systemInfo['submittedAt']);
    }

    #[Test]
    public function contextWithoutProviderIsDropped(): void
    {
        $request = $this->createRequest([...$this->validFields(), 'context' => '{"pageUid":12}']);

        self::assertSame([], $this->createSubject([])->createFromRequest($request, $this->createBackendUser())->context);
    }

    #[Test]
    public function contextIsResolvedByMatchingProvider(): void
    {
        $backendProvider = self::createStub(ContextProviderInterface::class);
        $backendProvider->method('supports')->willReturnCallback(static fn(Source $source): bool => $source === Source::Backend);
        $backendProvider->method('resolve')->willReturn(['module' => 'web_layout']);
        $frontendProvider = self::createStub(ContextProviderInterface::class);
        $frontendProvider->method('supports')->willReturnCallback(static fn(Source $source): bool => $source === Source::Frontend);
        $frontendProvider->method('resolve')->willReturnCallback(
            static fn(array $context, ServerRequestInterface $request): array => ['pageUid' => (int)$context['pageUid'], 'verified' => true],
        );

        $request = $this->createRequest([...$this->validFields(), 'context' => '{"pageUid":"12"}']);
        $submission = $this->createSubject([$backendProvider, $frontendProvider])->createFromRequest($request, $this->createBackendUser());

        self::assertSame(['pageUid' => 12, 'verified' => true], $submission->context);
    }

    #[Test]
    public function invalidRequestThrowsWithFieldErrors(): void
    {
        try {
            $this->createSubject([])->createFromRequest($this->createRequest(['message' => 'short']), $this->createBackendUser());
            self::fail('Expected exception');
        } catch (InvalidSubmissionException $exception) {
            self::assertSame(
                ['message' => 'tooShort', 'category' => 'required', 'severity' => 'required', 'source' => 'required'],
                $exception->errors,
            );
        }
    }

    #[Test]
    public function switchedOffDetailsAreDroppedEvenIfTheBrowserSendsThem(): void
    {
        $contextProvider = self::createStub(ContextProviderInterface::class);
        $contextProvider->method('supports')->willReturn(true);
        $contextProvider->method('resolve')->willReturn(['pageUid' => 12, 'pageTitle' => 'Teaser', 'siteIdentifier' => 'default']);
        $request = $this->createRequest([...$this->validFields(), 'clientInfo' => '{"language":"de","timezone":"Europe/Vienna"}'], ['REMOTE_ADDR' => '192.168.10.20']);

        $submission = $this->createSubject([$contextProvider], [CollectableField::ClientTimezone, CollectableField::SystemIpAddress, CollectableField::ContextPage])
            ->createFromRequest($request, $this->createBackendUser());

        self::assertSame(['language' => 'de'], $submission->clientInfo);
        self::assertArrayNotHasKey('ipAddress', $submission->systemInfo);
        self::assertSame('editor', $submission->systemInfo['backendUser']['username']);
        self::assertSame(['siteIdentifier' => 'default'], $submission->context);
    }

    #[Test]
    public function createFromRequestRemovesDeniedParametersFromBothUrls(): void
    {
        $contextProvider = self::createStub(ContextProviderInterface::class);
        $contextProvider->method('supports')->willReturn(true);
        $contextProvider->method('resolve')->willReturn(['url' => '/typo3/module/web/layout?token=abc&id=12']);
        $request = $this->createRequest([
            ...$this->validFields(),
            'clientInfo' => '{"url":"https://example.com/news?ADMCMD_prev=abc&id=12&adMcmd_simTime=1#c42"}',
        ]);

        $submission = $this->createSubject([$contextProvider])->createFromRequest($request, $this->createBackendUser());

        self::assertSame('https://example.com/news?id=12#c42', $submission->clientInfo['url']);
        self::assertSame('/typo3/module/web/layout?id=12', $submission->context['url']);
    }

    /**
     * @param list<ContextProviderInterface> $contextProviders
     * @param list<CollectableField> $disabledFields
     */
    private function createSubject(array $contextProviders, array $disabledFields = []): SubmissionFactory
    {
        $clock = self::createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-09T12:00:00+00:00'));
        $configurationProvider = self::createStub(ConfigurationProviderInterface::class);
        $configurationProvider->method('getConfiguration')->willReturn(new OpinionConfiguration(true, ['team@example.com'], '[Opinion]', 180, $disabledFields, ['ADMCMD_*']));

        return new SubmissionFactory(
            new SubmissionValidator(),
            new ClientInfoSanitizer(new SchemaSanitizer()),
            new SystemInfoCollector(new Typo3Version()),
            $clock,
            $configurationProvider,
            new CollectionFilter(),
            new UrlParameterFilter(),
            $contextProviders,
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $serverParams
     */
    private function createRequest(array $fields, array $serverParams = []): ServerRequest
    {
        return new ServerRequest('https://example.com/typo3/ajax/opinion/submit', 'POST', null, [], $serverParams)->withParsedBody($fields);
    }

    /**
     * @return array<string, string>
     */
    private function validFields(): array
    {
        return ['message' => 'The teaser image is cropped.', 'category' => 'bug', 'severity' => 'normal', 'source' => 'frontend'];
    }

    private function createBackendUser(): BackendUserAuthentication
    {
        $backendUser = self::createStub(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 7, 'username' => 'editor', 'realName' => 'Eddie Editor', 'email' => 'editor@example.com'];
        $backendUser->method('isAdmin')->willReturn(false);

        return $backendUser;
    }
}
