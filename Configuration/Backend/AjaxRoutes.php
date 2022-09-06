<?php

declare(strict_types=1);

use Supseven\Opinion\Controller\OpinionController;

return [
    // Main backend rendering setup (previously called backend.php) for the TYPO3 Backend
    'opinion-backend' => [
        'path'   => '/opinion/backend',
        'target' => OpinionController::class . '::opinionBackendAction',
    ],
];
