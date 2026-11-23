<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Adminpanel;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use TYPO3\CMS\Adminpanel\ModuleApi\ContentProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\DataProviderInterface;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleData;
use TYPO3\CMS\Adminpanel\ModuleApi\ModuleInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\PageArguments;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * List of the open frontend feedback of the current page: text, date and status only, no sender.
 * Administrators get a link from each entry to its detail view in the module "Feedback".
 */
#[Autoconfigure(public: true)]
final readonly class PageFeedbackSubModule implements ModuleInterface, DataProviderInterface, ContentProviderInterface
{
    public const int LIMIT = 10;

    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
        private FeedbackRepository $feedbackRepository,
        private UriBuilder $uriBuilder,
    ) {}

    public function getIdentifier(): string
    {
        return 'opinion_page_list';
    }

    public function getLabel(): string
    {
        return $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser())->sL('opinion.messages:adminpanel.pageFeedback.label');
    }

    public function getDataToStore(ServerRequestInterface $request, ResponseInterface $response): ModuleData
    {
        $routing = $request->getAttribute('routing');
        $pageUid = $routing instanceof PageArguments ? $routing->getPageId() : 0;

        return new ModuleData([
            'items' => $pageUid > 0 ? $this->feedbackRepository->findOpenByPage($pageUid, Source::Frontend, self::LIMIT) : [],
            'total' => $pageUid > 0 ? $this->feedbackRepository->countOpenByPage($pageUid, Source::Frontend) : 0,
        ]);
    }

    public function getContent(ModuleData $data): string
    {
        $languageService = $this->languageServiceFactory->createFromUserPreferences($this->getBackendUser());
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $isAdmin = $this->getBackendUser()?->isAdmin() ?? false;
        $rows = '';
        foreach ($items as $item) {
            $message = nl2br(htmlspecialchars((string)$item['message']));
            if ($isAdmin) {
                // Shareable URL without token: opened in a new tab, the backend loads with the detail view
                $message = sprintf(
                    '<a href="%s" target="_blank" rel="noopener" title="%s">%s</a>',
                    htmlspecialchars((string)$this->uriBuilder->buildUriFromRoute('opinion_feedback.show', ['uid' => (int)$item['uid']], UriBuilder::SHAREABLE_URL)),
                    htmlspecialchars($languageService->sL('opinion.messages:pageFeedback.show')),
                    $message,
                );
            }
            $rows .= sprintf(
                '<tr><td class="typo3-adminPanel-table-cell-key">%s</td><td>%s</td><td>%s</td></tr>',
                htmlspecialchars(date('d.m.Y H:i', (int)$item['crdate'])),
                $message,
                htmlspecialchars($languageService->sL('opinion.messages:status.' . $item['status'])),
            );
        }
        $more = (int)($data['total'] ?? 0) - count($items);

        return sprintf(
            '<p>%s</p><div class="typo3-adminPanel-table-overflow"><table class="typo3-adminPanel-table"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>%s</tbody></table></div>%s',
            htmlspecialchars($languageService->sL('opinion.messages:adminpanel.pageFeedback.description')),
            htmlspecialchars($languageService->sL('opinion.messages:module.list.date')),
            htmlspecialchars($languageService->sL('opinion.messages:tx_opinion_feedback.message')),
            htmlspecialchars($languageService->sL('opinion.messages:tx_opinion_feedback.status')),
            $rows,
            $more > 0 ? '<p>' . htmlspecialchars(sprintf($languageService->sL('opinion.messages:pageFeedback.more'), $more)) . '</p>' : '',
        );
    }

    private function getBackendUser(): ?BackendUserAuthentication
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;

        return $backendUser instanceof BackendUserAuthentication ? $backendUser : null;
    }
}
