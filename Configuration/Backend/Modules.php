<?php

declare(strict_types=1);

use Vkemeter\Opinion\Controller\FeedbackModuleController;

return [
    'opinion_feedback' => [
        // Own entry in the module menu, for administrators and editors who are granted the module
        'standalone' => true,
        'position' => ['after' => 'site'],
        'access' => 'user',
        'path' => '/module/opinion/feedback',
        'iconIdentifier' => 'opinion-feedback',
        'labels' => 'opinion.module',
        'routes' => [
            '_default' => [
                'target' => FeedbackModuleController::class . '::listAction',
            ],
            'show' => [
                'target' => FeedbackModuleController::class . '::showAction',
            ],
            'redeliver' => [
                'target' => FeedbackModuleController::class . '::redeliverAction',
                'methods' => ['POST'],
            ],
            'save' => [
                'target' => FeedbackModuleController::class . '::saveAction',
                'methods' => ['POST'],
            ],
        ],
    ],
];
