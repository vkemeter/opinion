<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Factory;

use Psr\Clock\ClockInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use Vkemeter\Opinion\Collection\CollectionFilter;
use Vkemeter\Opinion\Collector\ClientInfoSanitizer;
use Vkemeter\Opinion\Collector\SystemInfoCollector;
use Vkemeter\Opinion\Collector\UrlParameterFilter;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Configuration\OpinionConfiguration;
use Vkemeter\Opinion\Context\ContextProviderInterface;
use Vkemeter\Opinion\Domain\Category;
use Vkemeter\Opinion\Domain\Severity;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Domain\Submission;
use Vkemeter\Opinion\Validation\SubmissionValidator;

final readonly class SubmissionFactory
{
    /**
     * @param iterable<ContextProviderInterface> $contextProviders
     */
    public function __construct(
        private SubmissionValidator $validator,
        private ClientInfoSanitizer $clientInfoSanitizer,
        private SystemInfoCollector $systemInfoCollector,
        private ClockInterface $clock,
        private ConfigurationProviderInterface $configurationProvider,
        private CollectionFilter $collectionFilter,
        private UrlParameterFilter $urlParameterFilter,
        #[AutowireIterator('opinion.context_provider')]
        private iterable $contextProviders,
    ) {}

    /**
     * @throws InvalidSubmissionException
     */
    public function createFromRequest(ServerRequestInterface $request, BackendUserAuthentication $backendUser): Submission
    {
        $fields = $request->getParsedBody();
        $screenshotUpload = $request->getUploadedFiles()['screenshot'] ?? null;

        $result = $this->validator->validate(
            is_array($fields) ? $fields : [],
            $screenshotUpload instanceof UploadedFileInterface ? $screenshotUpload : null,
        );
        if (!$result->isValid() || !$result->category instanceof Category || !$result->severity instanceof Severity || !$result->source instanceof Source) {
            throw new InvalidSubmissionException($result->errors, 1791562180);
        }

        $createdAt = \DateTimeImmutable::createFromInterface($this->clock->now());
        $configuration = $this->configurationProvider->getConfiguration();

        return new Submission(
            message: $result->message,
            category: $result->category,
            severity: $result->severity,
            source: $result->source,
            backendUserUid: (int)($backendUser->user['uid'] ?? 0),
            createdAt: $createdAt,
            clientInfo: $this->filterUrl($this->collectionFilter->filter('client', $this->clientInfoSanitizer->sanitize($result->clientInfo), $configuration), $configuration),
            systemInfo: $this->collectionFilter->filter('system', $this->systemInfoCollector->collect($backendUser, $request, $createdAt), $configuration),
            context: $this->filterUrl($this->collectionFilter->filter('context', $this->resolveContext($result->source, $result->context, $request), $configuration), $configuration),
            screenshot: $result->screenshot,
        );
    }

    /**
     * @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function filterUrl(array $details, OpinionConfiguration $configuration): array
    {
        if (is_string($details['url'] ?? null)) {
            $details['url'] = $this->urlParameterFilter->filter($details['url'], $configuration->deniedUrlParameters);
        }

        return $details;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function resolveContext(Source $source, array $context, ServerRequestInterface $request): array
    {
        foreach ($this->contextProviders as $contextProvider) {
            if ($contextProvider->supports($source)) {
                return $contextProvider->resolve($context, $request);
            }
        }

        return [];
    }
}
