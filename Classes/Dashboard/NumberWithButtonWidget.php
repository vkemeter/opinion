<?php

declare(strict_types=1);

namespace Vkemeter\Opinion\Dashboard;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\Widgets\ButtonProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\NumberWithIconDataProviderInterface;
use TYPO3\CMS\Dashboard\Widgets\RequestAwareWidgetInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetInterface;

/**
 * The Core number widget with a button to the module; the Core one takes no button.
 * The button is left out for users who may not open the module.
 * Options: icon, title, subtitle (as for the Core NumberWithIconWidget).
 */
final class NumberWithButtonWidget implements WidgetInterface, RequestAwareWidgetInterface
{
    private ServerRequestInterface $request;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly WidgetConfigurationInterface $configuration,
        private readonly NumberWithIconDataProviderInterface $dataProvider,
        private readonly ButtonProviderInterface $buttonProvider,
        private readonly FeedbackScope $scope,
        private readonly BackendViewFactory $backendViewFactory,
        private readonly array $options = [],
    ) {}

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function renderWidgetContent(): string
    {
        $view = $this->backendViewFactory->create($this->request, ['typo3/cms-dashboard', 'vkemeter/opinion']);
        $view->assignMultiple([
            'icon' => $this->options['icon'] ?? '',
            'title' => $this->options['title'] ?? '',
            'subtitle' => $this->options['subtitle'] ?? '',
            'number' => $this->dataProvider->getNumber(),
            'button' => $this->scope->getSenderFilter() === false ? null : $this->buttonProvider,
            'options' => $this->options,
            'configuration' => $this->configuration,
        ]);

        return $view->render('Widget/NumberWithButton');
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
