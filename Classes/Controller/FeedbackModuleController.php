<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Domain\FeedbackStatus;
use Vkemeter\Opinion\Formatting\DataFlattener;
use Vkemeter\Opinion\Link\ReportedUrl;
use Vkemeter\Opinion\Redelivery\Redeliverer;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Module "Feedback": administrators see all submissions and manage delivery
 * and status, other users who may send feedback see their own submissions.
 */
#[AsController]
final readonly class FeedbackModuleController
{
    private const int ITEMS_PER_PAGE = 50;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private FeedbackRepository $feedbackRepository,
        private FileRepository $fileRepository,
        private DataFlattener $dataFlattener,
        private UriBuilder $uriBuilder,
        private ComponentFactory $componentFactory,
        private Redeliverer $redeliverer,
        private ConfigurationProviderInterface $configurationProvider,
        private FlashMessageService $flashMessageService,
        private AccessChecker $accessChecker,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hasModuleAccess()) {
            return $this->accessDenied();
        }
        $senderUid = $this->getSenderFilter();
        $total = $this->feedbackRepository->countAll($senderUid);
        $pages = max(1, (int)ceil($total / self::ITEMS_PER_PAGE));
        $currentPage = min($pages, max(1, (int)($request->getQueryParams()['page'] ?? 1)));

        $view = $this->moduleTemplateFactory->create($request);
        $view->assignMultiple([
            'items' => array_map(
                // The address at submission time, also for senders deleted since
                fn(array $item): array => [...$item, 'sender_email' => $this->getSenderEmail($this->decode($item['system_info'] ?? null))],
                $this->feedbackRepository->findPage(self::ITEMS_PER_PAGE, ($currentPage - 1) * self::ITEMS_PER_PAGE, $senderUid),
            ),
            'total' => $total,
            'isAdmin' => $this->isAdmin(),
            'pagination' => [
                'current' => $currentPage,
                'pages' => $pages,
                'previous' => $currentPage > 1 ? $this->buildUri('opinion_feedback', ['page' => $currentPage - 1]) : '',
                'next' => $currentPage < $pages ? $this->buildUri('opinion_feedback', ['page' => $currentPage + 1]) : '',
            ],
        ]);

        return $view->renderResponse('FeedbackModule/List');
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hasModuleAccess()) {
            return $this->accessDenied();
        }
        $uid = (int)($request->getQueryParams()['uid'] ?? 0);
        // Another user's feedback is treated like an unknown uid
        $record = $uid > 0 ? $this->feedbackRepository->findByUid($uid, $this->getSenderFilter()) : null;
        if ($record === null) {
            return new RedirectResponse($this->buildUri('opinion_feedback'));
        }

        $screenshots = $this->fileRepository->findByRelation(FeedbackRepository::TABLE, 'screenshot', $uid);
        $systemInfo = $this->decode($record['system_info'] ?? null);
        $context = $this->decode($record['context'] ?? null);
        $clientInfo = $this->decode($record['client_info'] ?? null);

        $view = $this->moduleTemplateFactory->create($request);
        $view->addButtonToButtonBar($this->componentFactory->createBackButton($this->buildUri('opinion_feedback')));
        $view->assignMultiple([
            'record' => $record,
            'sender' => is_array($systemInfo['backendUser'] ?? null) ? $systemInfo['backendUser'] : [],
            'screenshot' => $screenshots[0] ?? null,
            'isAdmin' => $this->isAdmin(),
            'canRedeliver' => $this->isAdmin() && $this->redeliverer->isUndelivered($record),
            'statuses' => array_map(static fn(FeedbackStatus $status): string => $status->value, FeedbackStatus::cases()),
            'reportedUrl' => ReportedUrl::fromDetails($clientInfo, $context, true),
            // The URLs are shown once as reportedUrl
            'sections' => [
                'context' => $this->dataFlattener->flatten(array_diff_key($context, ['url' => true])),
                'clientInfo' => $this->dataFlattener->flatten(array_diff_key($clientInfo, ['url' => true])),
                'systemInfo' => $this->dataFlattener->flatten(array_diff_key($systemInfo, ['backendUser' => true])),
            ],
        ]);

        return $view->renderResponse('FeedbackModule/Show');
    }

    /**
     * Delivers one feedback again, regardless of the attempt limit of opinion:redeliver.
     */
    public function redeliverAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $uid = (int)(is_array($body) ? ($body['uid'] ?? 0) : 0);
        $record = $uid > 0 ? $this->feedbackRepository->findByUid($uid) : null;
        if ($record === null) {
            return new RedirectResponse($this->buildUri('opinion_feedback'));
        }

        $result = $this->redeliverer->isUndelivered($record)
            ? $this->redeliverer->redeliver($uid, $this->configurationProvider->getConfiguration())
            : null;
        [$key, $severity] = match ($result?->status) {
            DeliveryStatus::Sent => ['module.redeliver.sent', ContextualFeedbackSeverity::OK],
            DeliveryStatus::Failed, DeliveryStatus::Pending => ['module.redeliver.failed', ContextualFeedbackSeverity::ERROR],
            DeliveryStatus::Skipped => ['module.redeliver.skipped', ContextualFeedbackSeverity::WARNING],
            null => ['module.redeliver.impossible', ContextualFeedbackSeverity::WARNING],
        };
        $this->addFlashMessage($key, 'module.redeliver.title', $severity);

        return new RedirectResponse($this->buildUri('opinion_feedback.show', ['uid' => $uid]));
    }

    /**
     * Sets the processing status; administrators only.
     */
    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isAdmin()) {
            return $this->accessDenied();
        }
        $body = $request->getParsedBody();
        $uid = (int)(is_array($body) ? ($body['uid'] ?? 0) : 0);
        if ($uid <= 0 || $this->feedbackRepository->findByUid($uid) === null) {
            return new RedirectResponse($this->buildUri('opinion_feedback'));
        }

        $status = FeedbackStatus::tryFrom((string)(is_array($body) ? ($body['status'] ?? '') : ''));
        if ($status === null) {
            $this->addFlashMessage('module.status.invalid', 'module.status.title', ContextualFeedbackSeverity::ERROR);
        } else {
            $this->feedbackRepository->updateStatus($uid, $status);
            $this->addFlashMessage('module.status.saved', 'module.status.title', ContextualFeedbackSeverity::OK);
        }

        return new RedirectResponse($this->buildUri('opinion_feedback.show', ['uid' => $uid]));
    }

    /**
     * Administrators always, also with the extension disabled; others only while they may send feedback.
     */
    private function hasModuleAccess(): bool
    {
        return $this->isAdmin() || $this->accessChecker->hasAccess($this->getBackendUser());
    }

    private function isAdmin(): bool
    {
        return $this->getBackendUser()?->isAdmin() ?? false;
    }

    /**
     * @return int|null uid of the current user for non-admins, null = all feedback
     */
    private function getSenderFilter(): ?int
    {
        return $this->isAdmin() ? null : (int)($this->getBackendUser()?->user['uid'] ?? 0);
    }

    private function accessDenied(): ResponseInterface
    {
        return new HtmlResponse($this->getLanguageService()->sL('opinion.messages:module.accessDenied'), 403);
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }

    private function addFlashMessage(string $key, string $titleKey, ContextualFeedbackSeverity $severity): void
    {
        $languageService = $this->getLanguageService();
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
            $languageService->sL('opinion.messages:' . $key),
            $languageService->sL('opinion.messages:' . $titleKey),
            $severity,
            true,
        ));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function buildUri(string $route, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
    }

    /**
     * @param array<string, mixed> $systemInfo
     */
    private function getSenderEmail(array $systemInfo): string
    {
        $sender = $systemInfo['backendUser'] ?? null;

        return is_array($sender) && is_string($sender['email'] ?? null) ? $sender['email'] : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) && $json !== '' ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
