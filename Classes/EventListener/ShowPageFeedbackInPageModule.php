<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\EventListener;

use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use Vkemeter\Opinion\Access\AccessChecker;
use Vkemeter\Opinion\Domain\Source;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * Info box above the content of the page module with the open feedback of the page, grouped by
 * source. Everybody who may send feedback sees it; only administrators get links to the module.
 */
#[AsEventListener('opinion/show-page-feedback-in-page-module')]
final readonly class ShowPageFeedbackInPageModule
{
    private const int LIMIT = 10;

    public function __construct(
        private AccessChecker $accessChecker,
        private FeedbackRepository $feedbackRepository,
        private BackendViewFactory $backendViewFactory,
        private UriBuilder $uriBuilder,
    ) {}

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || !$this->accessChecker->hasAccess($backendUser)) {
            return;
        }
        // From the request: the event offers getPageContext() only since TYPO3 14.3.12
        $pageUid = (int)($event->getRequest()->getQueryParams()['id'] ?? 0);
        if ($pageUid <= 0 || BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) === false) {
            return;
        }

        $isAdmin = $backendUser->isAdmin();
        $groups = [];
        foreach (Source::cases() as $source) {
            $items = $this->feedbackRepository->findOpenByPage($pageUid, $source, self::LIMIT);
            if ($items === []) {
                continue;
            }
            $groups[$source->value] = [
                'items' => array_map(fn(array $item): array => [
                    ...$item,
                    'show_url' => $isAdmin ? (string)$this->uriBuilder->buildUriFromRoute('opinion_feedback.show', ['uid' => $item['uid']]) : '',
                ], $items),
                'more' => $this->feedbackRepository->countOpenByPage($pageUid, $source) - count($items),
            ];
        }
        if ($groups === []) {
            return;
        }

        $view = $this->backendViewFactory->create($event->getRequest(), ['vkemeter/opinion']);
        $view->assignMultiple([
            'groups' => $groups,
            'total' => array_sum(array_map(static fn(array $group): int => count($group['items']) + $group['more'], $groups)),
        ]);
        $event->addHeaderContent($view->render('PageModule/PageFeedback'));
    }
}
