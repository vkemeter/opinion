<?php

declare(strict_types=1);

use Vkemeter\Opinion\Controller\FeedbackModuleController;

return [
    'opinion_feedback' => [
        'parent' => 'admin',
        'access' => 'admin',
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
        ],
    ],
];
