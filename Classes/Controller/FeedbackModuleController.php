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
use TYPO3\CMS\Core\Resource\FileRepository;
use Vkemeter\Opinion\Formatting\DataFlattener;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Admin module "Feedback": list of all submissions and a detail view.
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
            'sections' => [
                'context' => $this->dataFlattener->flatten($this->decode($record['context'] ?? null)),
                'clientInfo' => $this->dataFlattener->flatten($this->decode($record['client_info'] ?? null)),
                'systemInfo' => $this->dataFlattener->flatten(array_diff_key($systemInfo, ['backendUser' => true])),
            ],
        ]);

        return $view->renderResponse('FeedbackModule/Show');
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
}
