<?php

declare(strict_types=1);

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use Vkemeter\Opinion\Dashboard\LatestFeedbackWidget;
use Vkemeter\Opinion\Dashboard\ModuleButtonProvider;
use Vkemeter\Opinion\Dashboard\NumberWithButtonWidget;
use Vkemeter\Opinion\Dashboard\OpenFeedbackDataProvider;
use Vkemeter\Opinion\Dashboard\UnreadConversationsDataProvider;

return static function (ContainerConfigurator $configurator, ContainerBuilder $containerBuilder): void {
    // EXT:dashboard is optional: without it WidgetRegistry is missing and the widget classes cannot be loaded
    if (!$containerBuilder->hasDefinition(WidgetRegistry::class)) {
        return;
    }

    $services = $configurator->services();
    $services->defaults()->autowire()->autoconfigure()->private();
    $services->load('Vkemeter\\Opinion\\Dashboard\\', '../Classes/Dashboard/*');

    $label = static fn(string $key): string => 'opinion.messages:dashboard.' . $key;
    $widgets = [
        'opinion-openFeedback' => [
            'class' => NumberWithButtonWidget::class,
            'dataProvider' => OpenFeedbackDataProvider::class,
            'key' => 'open',
            'icon' => 'content-widget-number',
            'height' => 'small',
            'width' => 'small',
        ],
        'opinion-newMessages' => [
            'class' => NumberWithButtonWidget::class,
            'dataProvider' => UnreadConversationsDataProvider::class,
            'key' => 'newMessages',
            'icon' => 'content-widget-number',
            'height' => 'small',
            'width' => 'small',
        ],
        'opinion-latestFeedback' => [
            'class' => LatestFeedbackWidget::class,
            'dataProvider' => null,
            'key' => 'latest',
            'icon' => 'content-widget-list',
            'height' => 'medium',
            'width' => 'medium',
        ],
    ];

    foreach ($widgets as $identifier => $widget) {
        $service = $services->set('dashboard.widget.' . $identifier)
            ->class($widget['class'])
            ->arg('$buttonProvider', new Reference(ModuleButtonProvider::class))
            ->arg('$backendViewFactory', new Reference(BackendViewFactory::class))
            ->arg('$options', [
                'icon' => 'opinion-feedback',
                'title' => $label($widget['key'] . '.title'),
                'subtitle' => $label($widget['key'] . '.subtitle'),
                'refreshAvailable' => true,
            ])
            ->tag('dashboard.widget', [
                'identifier' => $identifier,
                'groupNames' => 'opinion',
                'title' => $label($widget['key'] . '.title'),
                'description' => $label($widget['key'] . '.description'),
                'iconIdentifier' => $widget['icon'],
                'height' => $widget['height'],
                'width' => $widget['width'],
            ]);
        if ($widget['dataProvider'] !== null) {
            $service->arg('$dataProvider', new Reference($widget['dataProvider']));
        }
    }
};
