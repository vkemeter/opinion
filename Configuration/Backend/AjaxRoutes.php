<?php

declare(strict_types=1);

use Vkemeter\Opinion\Controller\SubmitController;

return [
    'opinion_submit' => [
        'path' => '/opinion/submit',
        'methods' => ['POST'],
        'target' => SubmitController::class . '::submitAction',
    ],
];
