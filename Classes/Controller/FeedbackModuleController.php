<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\FileRepository;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use Vkemeter\Opinion\Configuration\ConfigurationProviderInterface;
use Vkemeter\Opinion\Domain\DeliveryStatus;
use Vkemeter\Opinion\Formatting\DataFlattener;
use Vkemeter\Opinion\Redelivery\Redeliverer;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Admin module "Feedback": list of all submissions, a detail view and a
 * new delivery attempt for feedback that was not delivered.
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
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        $total = $this->feedbackRepository->countAll();
        $pages = max(1, (int)ceil($total / self::ITEMS_PER_PAGE));
        $currentPage = min($pages, max(1, (int)($request->getQueryParams()['page'] ?? 1)));

        $view = $this->moduleTemplateFactory->create($request);
        $view->assignMultiple([
            'items' => $this->feedbackRepository->findPage(self::ITEMS_PER_PAGE, ($currentPage - 1) * self::ITEMS_PER_PAGE),
            'total' => $total,
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
        $uid = (int)($request->getQueryParams()['uid'] ?? 0);
        $record = $uid > 0 ? $this->feedbackRepository->findByUid($uid) : null;
        if ($record === null) {
            return new RedirectResponse($this->buildUri('opinion_feedback'));
        }

        $screenshots = $this->fileRepository->findByRelation(FeedbackRepository::TABLE, 'screenshot', $uid);
        $systemInfo = $this->decode($record['system_info'] ?? null);

        $view = $this->moduleTemplateFactory->create($request);
        $view->addButtonToButtonBar($this->componentFactory->createBackButton($this->buildUri('opinion_feedback')));
        $view->assignMultiple([
            'record' => $record,
            'sender' => is_array($systemInfo['backendUser'] ?? null) ? $systemInfo['backendUser'] : [],
            'screenshot' => $screenshots[0] ?? null,
            'canRedeliver' => $this->redeliverer->isUndelivered($record),
            'sections' => [
                'context' => $this->dataFlattener->flatten($this->decode($record['context'] ?? null)),
                'clientInfo' => $this->dataFlattener->flatten($this->decode($record['client_info'] ?? null)),
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
            null => ['module.redeliver.impossible', ContextualFeedbackSeverity::WARNING],
        };
        $languageService = $this->getLanguageService();
        $this->flashMessageService->getMessageQueueByIdentifier()->enqueue(new FlashMessage(
            $languageService->sL('opinion.messages:' . $key),
            $languageService->sL('opinion.messages:module.redeliver.title'),
            $severity,
            true,
        ));

        return new RedirectResponse($this->buildUri('opinion_feedback.show', ['uid' => $uid]));
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function buildUri(string $route, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
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
