<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\Channel;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Access\SubmissionRateLimiter;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Exception\ScreenshotStorageException;
use Vkemeter\Opinion\Factory\InvalidSubmissionException;
use Vkemeter\Opinion\Factory\SubmissionFactory;
use Vkemeter\Opinion\Processing\SubmissionProcessor;

#[AsController]
final readonly class SubmitController
{
    public function __construct(
        private AccessChecker $accessChecker,
        private ConfigurationProviderInterface $configurationProvider,
        private SubmissionFactory $submissionFactory,
        private SubmissionProcessor $submissionProcessor,
        private LanguageServiceFactory $languageServiceFactory,
        private SubmissionRateLimiter $rateLimiter,
        #[Channel('opinion')]
        private LoggerInterface $logger,
    ) {}

    public function submitAction(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $languageService = $this->languageServiceFactory->createFromUserPreferences($backendUser);

        if ($backendUser === null || !$this->accessChecker->hasAccess($backendUser)) {
            return $this->createResponse($languageService, 403, false, 'response.accessDenied');
        }
        if (!$this->accessChecker->hasValidEmail($backendUser)) {
            return $this->createResponse($languageService, 403, false, 'dialog.emailMissing');
        }

        try {
            $submission = $this->submissionFactory->createFromRequest($request, $backendUser);
        } catch (InvalidSubmissionException $exception) {
            $errors = [];
            foreach ($exception->errors as $field => $code) {
                $errors[$field] = $languageService->sL('opinion.messages:error.' . $field . '.' . $code);
            }

            return $this->createResponse($languageService, 422, false, 'response.invalid', ['errors' => $errors]);
        }

        $configuration = $this->configurationProvider->getConfiguration();
        // Counted after the validation, so correcting field errors does not use up the limit
        $retryAfter = $this->rateLimiter->consume($backendUser, $configuration);
        if ($retryAfter !== null) {
            return $this->createResponse($languageService, 429, false, 'response.rateLimited')
                ->withHeader('Retry-After', (string)max(1, $retryAfter->getTimestamp() - time()));
        }

        try {
            $result = $this->submissionProcessor->process($submission, $configuration);
        } catch (ScreenshotStorageException $exception) {
            $this->logger->error('Feedback with screenshot could not be stored: {message}', ['message' => $exception->getMessage()]);

            return $this->createResponse($languageService, 503, false, 'response.screenshotStorageUnavailable');
        }

        return match ($result->status) {
            DeliveryStatus::Failed => $this->createResponse($languageService, 200, true, 'response.notDelivered', ['delivered' => false]),
            DeliveryStatus::Pending => $this->createResponse($languageService, 200, true, 'response.saved', ['delivered' => false]),
            DeliveryStatus::Sent => $this->createResponse($languageService, 200, true, 'response.sent', ['delivered' => true]),
        };
    }

    /**
     * @param array<string, mixed> $additionalData
     */
    private function createResponse(LanguageService $languageService, int $statusCode, bool $success, string $messageKey, array $additionalData = []): JsonResponse
    {
        return new JsonResponse([
            'success' => $success,
            'title' => $languageService->sL('opinion.messages:response.title'),
            'message' => $languageService->sL('opinion.messages:' . $messageKey),
            ...$additionalData,
        ], $statusCode);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication && is_array($backendUser->user) ? $backendUser : null;
    }
}
