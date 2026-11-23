<?php

declare(strict_types=1);

use Vkemeter\Opinion\Controller\FeedbackModuleController;

return [
    // Not a module sub-route: the Core would open a module URL in a new tab inside the backend frame
    'opinion_screenshot' => [
        'path' => '/opinion/screenshot',
        'methods' => ['GET'],
        'target' => FeedbackModuleController::class . '::screenshotAction',
        'inheritAccessFromModule' => 'opinion_feedback',
    ],
];
