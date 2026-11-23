<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\ButtonProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;
use Vkemeter\Opinion\Repository\FeedbackRepository;

/**
 * The newest feedback the current user may see, linked to the detail view. The Core list widget
 * prints plain strings only.
 */
final class LatestFeedbackWidget implements WidgetInterface, RequestAwareWidgetInterface
{
    private const int LIMIT = 5;

    private ServerRequestInterface $request;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly FeedbackScope $scope,
        private readonly FeedbackRepository $feedbackRepository,
        private readonly ButtonProviderInterface $buttonProvider,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $senderUid = $this->scope->getSenderFilter();
        $items = $senderUid === false ? [] : array_map(
            fn(array $item): array => [
                ...$item,
                'show_url' => (string)$this->uriBuilder->buildUriFromRoute(FeedbackScope::MODULE . '.show', ['uid' => (int)$item['uid']]),
            ],
            $this->feedbackRepository->findPage(self::LIMIT, 0, $senderUid),
        );

        $view = $this->backendViewFactory->create($this->request, ['typo3/cms-dashboard', 'vkemeter/opinion']);
        $view->assignMultiple([
            'items' => $items,
            'button' => $senderUid === false ? null : $this->buttonProvider,
            'options' => $this->options,
            'configuration' => $this->configuration,
        ]);

        return $view->render('Widget/LatestFeedback');
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
